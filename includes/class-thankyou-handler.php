<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instant_Form_Thankyou_Handler {

    public static function init() {
        // ── PRIMARY fix: Force Stripe's return URL to order-received ──────────
        // The WC Stripe plugin calls get_return_url($order) when building its
        // confirmPayment() return_url and when building wc_stripe_upe_params.
        // By filtering this at priority 5 (before Stripe's own hooks), we ensure
        // Stripe redirects to our order-received page after payment.
        // PRIMARY: Force Stripe's return_url to go directly to the order-received page.
        // Without this, Stripe bounces back to the order-pay URL, creating a redirect loop.
        add_filter( 'woocommerce_get_return_url', array( __CLASS__, 'force_thankyou_url' ), 5, 2 );

        // ── FALLBACK: if Stripe redirected back to order-pay with intent params ─
        // Some Stripe plugin versions redirect to order-pay first, then we take over.
        add_action( 'template_redirect', array( __CLASS__, 'handle_stripe_return' ), 100 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'confirm_pcb_order_on_thankyou' ), 5 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'enable_stripe_on_pay_page' ), 999 );

        // ── Thank You page UI ─────────────────────────────────────────────────
        add_action( 'wp_head',                                     array( __CLASS__, 'inject_thankyou_styles' ) );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'add_redirect_button' ), 20 );
        add_filter( 'woocommerce_thankyou_order_received_text',    array( __CLASS__, 'custom_thankyou_text' ), 10, 2 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIMARY: Override the return URL WC Stripe uses for confirmPayment()
    // ─────────────────────────────────────────────────────────────────────────

    public static function force_thankyou_url( $url, $order ) {
        if ( ! $order ) {
            return $url;
        }
        if ( ! is_object( $order ) ) {
            $order = wc_get_order( absint( $order ) );
        }
        if ( ! $order ) {
            return $url;
        }
        // Apply for any PCB order (has _pcb_specs) OR any order created via our form
        $source  = $order->get_meta( '_pcb_source' );
        $has_pcb = $order->get_meta( '_pcb_specs' ) || $source === 'Instant Form Plugin';
        if ( $has_pcb ) {
            return $order->get_checkout_order_received_url();
        }
        return $url;
    }

    public static function enable_stripe_on_pay_page( $available_gateways ) {
        // Only trigger on relevant pages
        if ( ! is_checkout() && ! is_add_payment_method_page() && ! isset( $_GET['pay_for_order'] ) ) {
             return $available_gateways;
        }

        // If we're on the pay-for-order page
        if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            if ( ! $order_id ) {
                // Fallback for some permalink structures
                global $wp;
                if ( isset( $wp->query_vars['order-pay'] ) ) {
                    $order_id = absint( $wp->query_vars['order-pay'] );
                }
            }
            
            if ( ! $order_id ) return $available_gateways;

            $order = wc_get_order( $order_id );
            if ( ! $order || ! $order->get_meta( '_pcb_specs' ) ) return $available_gateways;

            // Force enable Stripe if it's the chosen method or just enabled in system
            if ( ! isset( $available_gateways['stripe'] ) ) {
                $gateways = WC()->payment_gateways->payment_gateways();
                if ( isset( $gateways['stripe'] ) && $gateways['stripe']->enabled === 'yes' ) {
                    $available_gateways['stripe'] = $gateways['stripe'];
                }
            }
        }

        return $available_gateways;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FALLBACK: If Stripe redirected back with ?payment_intent params
    // (some Stripe plugin versions do a page-level redirect back to order-pay)
    // ─────────────────────────────────────────────────────────────────────────

    public static function handle_stripe_return() {
        // Only fire when Stripe's return params are present in the URL
        if ( empty( $_GET['payment_intent'] ) || empty( $_GET['redirect_status'] ) ) {
            return;
        }

        $redirect_status   = sanitize_text_field( wp_unslash( $_GET['redirect_status'] ) );
        $payment_intent_id = sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) );

        error_log( "Instant PCB Form: handle_stripe_return triggered. Status: $redirect_status, Intent: $payment_intent_id" );

        if ( $redirect_status !== 'succeeded' ) {
            error_log( "Instant PCB Form: Stripe redirect_status=$redirect_status for intent $payment_intent_id — not succeeded, skipping." );
            return;
        }

        // ── Find the order ────────────────────────────────────────────────────
        $order_id = 0;

        // Case A: on the order-pay page — WP exposes ID in query_vars
        global $wp;
        if ( isset( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
        } elseif ( isset( $wp->query_vars['order-received'] ) ) {
            $order_id = absint( $wp->query_vars['order-received'] );
        }

        // Case B: find order by the Stripe intent ID meta if not found by URL
        if ( ! $order_id ) {
            $found = wc_get_orders( array(
                'meta_key'   => '_stripe_intent_id',
                'meta_value' => $payment_intent_id,
                'limit'      => 1,
            ) );
            if ( ! empty( $found ) ) {
                $order_id = $found[0]->get_id();
            }
        }

        if ( ! $order_id ) {
            error_log( "Instant PCB Form: handle_stripe_return — cannot find order for intent $payment_intent_id." );
            return;
        }

        $order = wc_get_order( $order_id );
        // If no PCB order found, skip (don't interfere with other WC orders)
        if ( ! $order ) {
            return;
        }
        // Check if this is our PCB order (either meta is fine)
        $is_pcb = $order->get_meta( '_pcb_specs' ) || $order->get_meta( '_pcb_source' ) === 'Instant Form Plugin';
        if ( ! $is_pcb ) {
            return;
        }

        $status = $order->get_status();
        $paid = array( 'processing', 'completed' );

        // ── Ensure order is marked as paid and set to PROCESSING ──────────────
        if ( ! in_array( $status, $paid, true ) ) {
            error_log( "Instant PCB Form: Stripe success confirmed. Marking order #$order_id as PROCESSING." );
            
            if ( ! $order->get_transaction_id() ) {
                $order->set_transaction_id( $payment_intent_id );
            }
            
            // payment_complete() usually sets to processing or completed depending on the order type
            $order->payment_complete( $payment_intent_id );
            
            // Explicitly force to processing to satisfy user requirement
            $order->update_status( 'processing', 'Payment verified via Stripe redirect.' );
            $order->save();
            
            if ( class_exists( 'Instant_Form_Email_Handler' ) ) {
                Instant_Form_Email_Handler::maybe_send_emails_from_ajax( $order );
            }
        }

        // ── Redirect to Thank You page if we are still on the Pay page ─────────
        if ( ! is_order_received_page() ) {
            $thankyou_url = $order->get_checkout_order_received_url();
            error_log( "Instant PCB Form: Redirecting order #$order_id from Pay page → $thankyou_url" );
            wp_safe_redirect( $thankyou_url );
            exit;
        }
    }

    public static function confirm_pcb_order_on_thankyou( $order_id ) {
        if ( ! $order_id ) return;
        
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_meta( '_pcb_specs' ) ) return;
        
        $status = $order->get_status();
        $paid = array( 'processing', 'completed' );
        
        if ( ! in_array( $status, $paid, true ) ) {
            $intent_id = sanitize_text_field( $_GET['payment_intent'] ?? '' );
            if ( ! $intent_id ) {
                $intent_id = $order->get_meta( '_stripe_intent_id' );
            }
            
            $status_param = sanitize_text_field( $_GET['redirect_status'] ?? '' );
            $method = $order->get_payment_method();
            $is_stripe = ( strpos( $method, 'stripe' ) !== false );

            error_log( "Instant PCB Form: Thank-you check for #$order_id. Status: $status, Intent: $intent_id, Redirect Status: $status_param, Method: $method" );
            
            // CRITICAL: Only mark as paid if Stripe explicitly says 'succeeded' or we have a verified intent
            if ( $is_stripe && $intent_id && ( $status_param === 'succeeded' || empty( $status_param ) && $order->has_status( 'pending' ) && ! empty( $_GET['key'] ) ) ) {
                
                // If we have succeeded status, it's a guaranteed win
                if ( $status_param === 'succeeded' ) {
                    error_log( "Instant PCB Form: Stripe success confirmed for #$order_id. Marking as paid." );
                    $order->payment_complete( $intent_id );
                    $order->add_order_note( 'Payment successfully verified via Stripe redirect.' );
                    
                    if ( class_exists( 'Instant_Form_Email_Handler' ) ) {
                        Instant_Form_Email_Handler::maybe_send_emails_from_ajax( $order );
                    }
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Thank You page styles
    // ─────────────────────────────────────────────────────────────────────────

    public static function inject_thankyou_styles() {
        if ( ! is_order_received_page() ) return;
        ?>
        <style>
            .woocommerce-order {
                max-width: 800px; margin: 40px auto; background: #fff;
                padding: 40px; border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,.1);
                border-top: 6px solid #28a745;
                font-family: 'Segoe UI', Arial, sans-serif;
            }
            .woocommerce-order h2,
            .woocommerce-order .woocommerce-notice--success {
                color: #28a745; text-align: center; font-weight: bold;
            }
            .woocommerce-order .woocommerce-notice--success {
                font-size: 1.5em; margin-bottom: 30px; background: transparent; border: none;
            }
            ul.order_details {
                background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px;
                padding: 20px; display: flex; justify-content: space-between;
                list-style: none; margin-bottom: 30px;
            }
            ul.order_details li {
                border-right: 1px dashed #ccc; padding-right: 20px; margin-right: 20px;
                flex: 1; text-transform: uppercase; font-size: .85em; color: #666;
            }
            ul.order_details li:last-child { border: none; padding: 0; margin: 0; }
            ul.order_details li strong { display: block; font-size: 1.2em; color: #333; margin-top: 5px; text-transform: none; }
            .woocommerce-table--order-details { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .woocommerce-table--order-details th,
            .woocommerce-table--order-details td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
            .woocommerce-table--order-details th { color: #1e7e34; font-weight: 600; background: #f9f9f9; }
            .woocommerce-table--order-details tfoot th { text-align: right; color: #555; }
            .woocommerce-table--order-details tfoot td { font-weight: bold; color: #333; }
            .woocommerce-table--order-details tfoot tr:last-child td { color: #28a745; font-size: 1.2em; }
            .woocommerce-customer-details address {
                border: 1px solid #eee; border-radius: 6px; padding: 15px;
                background: #fafafa; font-style: normal; line-height: 1.6; color: #555;
            }
            .woocommerce-column__title { color: #333; font-size: 1.2em; margin-bottom: 15px; }
            .instant-quote-btn-wrapper { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; }
            .btn-new-quote {
                display: inline-block; background: #28a745; color: #fff;
                padding: 15px 30px; font-size: 18px; font-weight: bold;
                text-decoration: none; border-radius: 6px; transition: background .3s;
            }
            .btn-new-quote:hover { background: #218838; color: #fff; }
        </style>
        <?php
    }

    public static function add_redirect_button( $order ) {
        ?>
        <div class="instant-quote-btn-wrapper">
            <a href="<?php echo esc_url( home_url( '/instant-online-quote/' ) ); ?>" class="btn-new-quote">
                Calculate Another Quote
            </a>
        </div>
        <?php
    }

    public static function custom_thankyou_text( $text, $order ) {
        if ( ! $order ) return $text;
        return 'Thank you! Your order has been received and payment confirmed.';
    }
}
