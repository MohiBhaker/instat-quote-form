<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instant_Form_Handler {

    public static function init() {
        add_action( 'wp_ajax_instant_form_get_gateways', array( __CLASS__, 'get_gateways' ) );
        add_action( 'wp_ajax_nopriv_instant_form_get_gateways', array( __CLASS__, 'get_gateways' ) );

        add_action( 'wp_ajax_instant_form_get_gateway_fields', array( __CLASS__, 'get_gateway_fields' ) );
        add_action( 'wp_ajax_nopriv_instant_form_get_gateway_fields', array( __CLASS__, 'get_gateway_fields' ) );

        add_action( 'wp_ajax_instant_form_create_order', array( __CLASS__, 'create_order' ) );
        add_action( 'wp_ajax_nopriv_instant_form_create_order', array( __CLASS__, 'create_order' ) );

        add_action( 'wp_ajax_instant_form_process_checkout', array( __CLASS__, 'process_checkout' ) );
        add_action( 'wp_ajax_nopriv_instant_form_process_checkout', array( __CLASS__, 'process_checkout' ) );
    }

    private static function ensure_session() {
        if ( ! class_exists( 'WooCommerce' ) ) return;
        if ( WC()->session && ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }
    }

    public static function get_gateways() {
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error();
        self::ensure_session();

        // Basic country/user mock for gateway availability
        if ( WC()->customer ) {
            if ( empty( WC()->customer->get_billing_country() ) ) {
                WC()->customer->set_billing_country( 'US' );
            }
            WC()->customer->save();
        }
        
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $data = array();
        foreach ( $gateways as $gateway ) {
            if ( $gateway->enabled === 'yes' ) {
                $data[] = array(
                    'id' => $gateway->id,
                    'title' => $gateway->get_title(),
                    'description' => $gateway->get_description()
                );
            }
        }
        wp_send_json_success( $data );
    }

    public static function get_gateway_fields() {
        $gateway_id = sanitize_text_field( $_POST['gateway_id'] );
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        
        if ( isset( $gateways[$gateway_id] ) ) {
            ob_start();
            $gateways[$gateway_id]->payment_fields();
            $html = ob_get_clean();
            wp_send_json_success( array( 'html' => $html ) );
        }
        wp_send_json_error();
    }

    private static function parse_order_data() {
        $name           = sanitize_text_field( $_POST['name'] ?? '' );
        $email          = sanitize_email( $_POST['email'] ?? '' );
        $part_number    = sanitize_text_field( $_POST['part_number'] ?? '' );
        $payment_method = sanitize_text_field( $_POST['payment_method'] ?? '' );

        $billing_first_name = ! empty( $_POST['billing_first_name'] ) ? sanitize_text_field( $_POST['billing_first_name'] ) : 'Guest';
        $billing_last_name  = ! empty( $_POST['billing_last_name'] ) ? sanitize_text_field( $_POST['billing_last_name'] ) : 'User';
        $billing_email      = ! empty( $_POST['billing_email'] ) ? sanitize_email( $_POST['billing_email'] ) : $email;

        $_POST['billing_first_name'] = $billing_first_name;
        $_POST['billing_last_name']  = $billing_last_name;
        $_POST['billing_email']      = $billing_email;
        if ( empty( $_POST['billing_country'] ) ) $_POST['billing_country'] = 'US';

        $specs_json    = stripslashes( $_POST['specs'] ?? '{}' );
        $specs         = json_decode( $specs_json, true );
        $shipping_data = json_decode( stripslashes( $_POST['shipping'] ?? '{}' ), true );
        $payment_data  = json_decode( stripslashes( $_POST['payment_data'] ?? '{}' ), true );

        return compact(
            'name', 'email', 'part_number', 'payment_method',
            'billing_first_name', 'billing_last_name', 'billing_email',
            'specs_json', 'specs', 'shipping_data', 'payment_data'
        );
    }

    private static function handle_uploads() {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploaded_files = array();
        if ( isset( $_FILES['gerber'] ) ) {
            $files = $_FILES['gerber'];
            if ( is_array( $files['name'] ) ) {
                foreach ( $files['name'] as $i => $n ) {
                    if ( $files['error'][$i] === 0 ) {
                        $file = array(
                            'name'     => $files['name'][$i],
                            'type'     => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error'    => $files['error'][$i],
                            'size'     => $files['size'][$i],
                        );
                        $movefile = wp_handle_upload( $file, array( 'test_form' => false ) );
                        if ( $movefile && ! isset( $movefile['error'] ) ) {
                            $uploaded_files[] = array( 'url' => $movefile['url'], 'path' => $movefile['file'] );
                        }
                    }
                }
            } else {
                $movefile = wp_handle_upload( $files, array( 'test_form' => false ) );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $uploaded_files[] = array( 'url' => $movefile['url'], 'path' => $movefile['file'] );
                }
            }
        }
        return $uploaded_files;
    }

    private static function build_order( $data, $uploaded_files ) {
        extract( $data );
        $primary_file_url = ! empty( $uploaded_files ) ? $uploaded_files[0]['url'] : '';

        $order = wc_create_order( array(
            'customer_id' => get_current_user_id(),
        ) );

        $order->set_created_via( 'Instant PCB Form' );
        $order->update_meta_data( '_pcb_source', 'Instant Form Plugin' );
        
        $item = new WC_Order_Item_Fee();
        $item->set_name( 'PCB Manufacturing: ' . ( $part_number ?: 'Model' ) );
        $item->set_total( (float) ($specs['calculation']['total_price'] ?? 0) );
        $order->add_item( $item );

        $address = array(
            'first_name' => $billing_first_name,
            'last_name'  => $billing_last_name,
            'email'      => $billing_email,
            'address_1'  => $shipping_data['address'] ?? '',
            'city'       => $shipping_data['city'] ?? '',
            'state'      => $shipping_data['state'] ?? '',
            'postcode'   => $shipping_data['postcode'] ?? '',
            'country'    => $shipping_data['country'] ?? 'US',
        );
        
        $order->set_address( $address, 'billing' );
        $order->set_address( $address, 'shipping' );
        $order->set_payment_method( $payment_method );
        
        $order->set_customer_ip_address( $_SERVER['REMOTE_ADDR'] );
        $order->set_customer_user_agent( $_SERVER['HTTP_USER_AGENT'] );

        $order->update_meta_data( '_pcb_gerber_url', $primary_file_url );
        $order->update_meta_data( '_pcb_gerber_files', $uploaded_files );
        $order->update_meta_data( '_pcb_specs', $specs_json );
        $order->update_meta_data( '_pcb_part_number', $part_number );
        
        $order->calculate_totals();
        $order->set_status( 'pending', 'Order initialized via Instant Form.' );
        $order->save();

        // ── CRITICAL: Store in WC session to ensure "order-pay" works correctly for guests ──
        if ( WC()->session ) {
            WC()->session->set( 'order_awaiting_payment', $order->get_id() );
            WC()->session->set( 'chosen_payment_method', $payment_method );
        }

        return array( $order, $address );
    }

    public static function create_order() {
        $prev = error_reporting( E_ERROR );
        try {
            self::ensure_session();
            $data = self::parse_order_data();
            $uploaded_files = self::handle_uploads();

            if ( empty( $uploaded_files ) ) {
                wp_send_json_error( 'File upload failed. Please ensure at least one valid file is attached.' );
                return;
            }

            list( $order, $address ) = self::build_order( $data, $uploaded_files );

            $is_stripe = ( strpos( $data['payment_method'], 'stripe' ) !== false );
            
            error_log( "Instant PCB Form: Created order #{$order->get_id()} (Stripe: " . ($is_stripe ? 'Yes':'No') . ")" );

            error_reporting( $prev );
            wp_send_json_success( array(
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'pay_url'   => $order->get_checkout_payment_url(),
                'is_stripe' => $is_stripe,
            ) );
        } catch ( Exception $e ) {
            error_reporting( $prev );
            wp_send_json_error( $e->getMessage() );
        }
    }

    public static function process_checkout() {
        $prev = error_reporting( E_ERROR );
        try {
            self::ensure_session();
            $order_id = absint( $_POST['order_id'] ?? 0 );
            $data = self::parse_order_data();
            
            $order = $order_id ? wc_get_order( $order_id ) : null;
            if ( ! $order ) {
                wp_send_json_error( 'Could not find order #' . $order_id );
                return;
            }

            $payment_method = $data['payment_method'];
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            if ( ! isset( $gateways[ $payment_method ] ) ) {
                wp_send_json_error( 'Invalid payment method: ' . $payment_method );
                return;
            }

            // Sync $_POST for the gateway
            foreach ( $data['payment_data'] as $key => $val ) {
                $_POST[ $key ] = $val;
            }
            $_POST['payment_method'] = $payment_method;
            $_POST['billing_email']  = $order->get_billing_email();

            // Try to set nonces if gateway expects them (Stripe often doesn't care about the specific core nonce in order-pay flow, but let's be safe)
            if ( ! isset( $_POST['_wpnonce'] ) ) {
                $_POST['_wpnonce'] = wp_create_nonce( 'woocommerce-pay' );
            }

            // ── STRIPE DATA SYNC ──
            // If the JS on the first page already got a token/payment_method, 
            // inject it into the POST so the gateway sees it.
            if ( ! empty( $_POST['stripe_token'] ) ) {
                $_POST['stripe_token'] = sanitize_text_field( $_POST['stripe_token'] );
            }
            if ( ! empty( $_POST['stripe_payment_method'] ) ) {
                $_POST['payment_method_id'] = sanitize_text_field( $_POST['stripe_payment_method'] );
            }

            // ── GATEWAY HANDOFF ──
            try {
                // Clear any old notices to avoid "error noise" on the checkout page
                if ( function_exists( 'wc_clear_notices' ) ) wc_clear_notices();

                $result = $gateways[ $payment_method ]->process_payment( $order->get_id() );
                
                $is_success = ( isset( $result['result'] ) && $result['result'] === 'success' );
                $redirect   = $result['redirect'] ?? '';
            } catch ( Exception $e ) {
                error_log( "Instant PCB Form: Gateway process_payment exception: " . $e->getMessage() );
                $is_success = false;
            }

            // ── REDIRECT LOGIC ──
            // Only redirect to order-pay if we absolutely have to (e.g. 3D Secure required)
            // Otherwise, if it's successful, we'll send them to thank you.
            if ( ! $is_success && strpos( $payment_method, 'stripe' ) !== false && empty( $redirect ) ) {
                $is_success = true; // Mark as "success" so JS redirects to the pay page instead of showing error
                $redirect   = $order->get_checkout_payment_url();
            }

            // Extract error message if failure
            $error_message = 'Payment initiation failed.';
            if ( ! $is_success && function_exists( 'wc_get_notices' ) ) {
                $notices = wc_get_notices( 'error' );
                if ( ! empty( $notices ) ) {
                    $error_message = wp_strip_all_tags( $notices[0]['notice'] );
                    wc_clear_notices();
                }
            }

            error_reporting( $prev );
            wp_send_json_success( array(
                'result'   => $is_success ? 'success' : 'failure',
                'redirect' => $redirect,
                'message'  => $is_success ? 'Redirecting to payment...' : $error_message,
            ) );
        } catch ( Exception $e ) {
            error_reporting( $prev );
            wp_send_json_error( 'Server error: ' . $e->getMessage() );
        }
    }
}
