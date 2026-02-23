<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instant_Form_Email_Handler {

    public static function init() {
        add_action( 'wp_mail_failed', array( __CLASS__, 'log_mail_errors' ) );

        // Server-side hooks (fire during webhook / gateway processing)
        add_action( 'woocommerce_payment_complete',        array( __CLASS__, 'maybe_send_emails' ), 15, 1 );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_send_emails' ), 15, 1 );

        // Front-end safeguard
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'maybe_send_emails_thankyou' ), 15, 1 );
    }

    public static function log_mail_errors( $wp_error ) {
        error_log( 'Instant PCB Form — wp_mail error: ' . $wp_error->get_error_message() );
    }

    // ── Called from server-side status/payment hooks ─────────────────────────
    // Only fires when order is in a confirmed-paid status.
    public static function maybe_send_emails( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $status = $order->get_status();
        $paid   = array( 'processing', 'completed' );

        error_log( "Instant PCB Form [status hook]: order #$order_id status='$status'" );

        if ( ! in_array( $status, $paid, true ) ) {
            return;
        }

        self::dispatch( $order );
    }

    // ── Called when customer lands on Thank You page ─────────────────────────
    // We bypass the status check here because Stripe's webhook may be async.
    // The presence of _pcb_specs + being on the thank-you page is sufficient proof.
    public static function maybe_send_emails_thankyou( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        error_log( "Instant PCB Form [woocommerce_thankyou]: order #$order_id status='" . $order->get_status() . "'" );

        self::dispatch( $order );
    }

    // ── Called directly from our AJAX endpoint (order-pay page JS after payment success) ──
    // This is the most reliable path for Stripe — it fires right when payment succeeds
    // before any redirect, bypassing all WooCommerce hook timing issues.
    public static function maybe_send_emails_from_ajax( $order ) {
        if ( ! $order ) return;
        error_log( "Instant PCB Form [ajax_payment_done]: order #" . $order->get_id() . " status='" . $order->get_status() . "'" );
        self::dispatch( $order );
    }

    // ── Core dispatcher — deduplicates, checks for PCB order, then sends ────
    private static function dispatch( $order ) {
        if ( ! $order ) return;
        $order_id = $order->get_id();

        // In-memory guard (prevents double-fire in same PHP request)
        static $sent_this_request = array();
        if ( isset( $sent_this_request[ $order_id ] ) ) {
            error_log( "Instant PCB Form: order #$order_id already dispatched in this request. Skipping." );
            return;
        }
        $sent_this_request[ $order_id ] = true;

        // DB guard — use WC order meta (works with HPOS and legacy postmeta both)
        if ( $order->get_meta( '_instant_form_emails_sent' ) === '1' ) {
            error_log( "Instant PCB Form: order #$order_id emails already sent (DB flag). Skipping." );
            return;
        }

        // Is this an instant-form order? Read with WC meta (HPOS-safe)
        $specs_json = $order->get_meta( '_pcb_specs' );

        if ( empty( $specs_json ) ) {
            error_log( "Instant PCB Form: order #$order_id has no _pcb_specs. Not an instant-form order." );
            return;
        }

        error_log( "Instant PCB Form: order #$order_id — all checks passed. Sending emails now." );

        // Mark as sent immediately (before sending) to avoid race conditions
        $order->update_meta_data( '_instant_form_emails_sent', '1' );
        $order->save();

        // Gather data
        $specs          = json_decode( $specs_json, true );
        $uploaded_files = $order->get_meta( '_pcb_gerber_files' );

        if ( empty( $uploaded_files ) ) {
            $legacy = $order->get_meta( '_pcb_gerber_url' );
            $uploaded_files = $legacy ? array( array( 'url' => $legacy, 'path' => '' ) ) : array();
        }

        $shipping = array(
            'address'  => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'city'     => $order->get_shipping_city()      ?: $order->get_billing_city(),
            'state'    => $order->get_shipping_state()     ?: $order->get_billing_state(),
            'postcode' => $order->get_shipping_postcode()  ?: $order->get_billing_postcode(),
            'country'  => $order->get_shipping_country()   ?: $order->get_billing_country(),
        );

        self::send_emails( $order_id, $specs, $shipping, $uploaded_files );
    }

    // ── Public: send emails (also called directly from process_checkout for non-Stripe) ──
    public static function send_emails( $order_id, $specs, $shipping, $gerber_files_or_url, $gerber_path = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "Instant PCB Form: send_emails() — cannot load order #$order_id" );
            return;
        }

        // Customer details
        $customer_email = $order->get_billing_email();
        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $part_number    = $order->get_meta( '_pcb_part_number' );
        $total_price    = $order->get_formatted_order_total();
        $payment_method = $order->get_payment_method_title();

        // Normalise gerber file list
        if ( is_array( $gerber_files_or_url ) ) {
            $final_files = $gerber_files_or_url;
        } else {
            $final_files = array( array( 'url' => $gerber_files_or_url, 'path' => $gerber_path ) );
        }

        // Build gerber HTML links
        $gerber_links_html = '';
        $gerber_urls_text  = '';
        $primary_url       = '';
        foreach ( $final_files as $idx => $file ) {
            $url = isset( $file['url'] ) ? $file['url'] : '';
            if ( ! $url ) continue;
            if ( ! $primary_url ) $primary_url = $url;
            $num   = $idx + 1;
            $label = count( $final_files ) > 1 ? "Gerber File $num" : 'Gerber File';
            $gerber_links_html .= "<a href='" . esc_url( $url ) . "' style='display:inline-block;margin:5px 0;background:#007bff;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;font-weight:bold;'>Download $label</a><br>";
            $gerber_urls_text  .= esc_url( $url ) . "\n";
        }
        if ( ! $gerber_links_html ) $gerber_links_html = 'No file attached';

        // Build replacement map
        $replacements = array(
            '[order_id]'         => $order_id,
            '[date]'             => date_i18n( get_option( 'date_format' ), strtotime( $order->get_date_created() ) ),
            '[customer_name]'    => $customer_name,
            '[customer_email]'   => $customer_email,
            '[part_number]'      => $part_number,
            '[total_price]'      => $total_price,
            '[payment_method]'   => $payment_method,
            '[quantity]'         => isset( $specs['qty'] )              ? $specs['qty']              : '',
            '[board_size]'       => isset( $specs['size'] )             ? $specs['size']             : '',
            '[layers]'           => isset( $specs['layers'] )           ? $specs['layers']           : '',
            '[thickness]'        => isset( $specs['thickness'] )        ? $specs['thickness']        : '',
            '[copper]'           => isset( $specs['copper'] )           ? $specs['copper']           : '',
            '[surface_finish]'   => isset( $specs['finish'] )           ? $specs['finish']           : '',
            '[solder_mask]'      => isset( $specs['mask'] )             ? $specs['mask']             : '',
            '[silkscreen]'       => isset( $specs['silk'] )             ? $specs['silk']             : '',
            '[special_req]'      => isset( $specs['req'] )              ? $specs['req']              : 'None',
            '[pcb_cost]'         => isset( $specs['calculation']['pcb_cost'] )  ? wc_price( $specs['calculation']['pcb_cost'] )  : '',
            '[shipping_cost]'    => isset( $specs['calculation']['shipping'] )   ? wc_price( $specs['calculation']['shipping'] )   : '',
            '[build_time]'       => isset( $specs['calculation']['build_time'] ) ? $specs['calculation']['build_time']             : '',
            '[total_area]'       => isset( $specs['calculation']['area'] )       ? $specs['calculation']['area']                   : '',
            '[shipping_address]' => isset( $shipping['address'] )  ? $shipping['address']  : '',
            '[shipping_city]'    => isset( $shipping['city'] )     ? $shipping['city']     : '',
            '[shipping_state]'   => isset( $shipping['state'] )    ? $shipping['state']    : '',
            '[shipping_zip]'     => isset( $shipping['postcode'] ) ? $shipping['postcode'] : '',
            '[shipping_country]' => isset( $shipping['country'] )  ? $shipping['country']  : '',
            '[gerber_link]'      => $gerber_links_html,
            '[gerber_url]'       => esc_url( $primary_url ),
            '[all_gerber_urls]'  => trim( $gerber_urls_text ),
        );

        self::do_send_admin( $replacements, $final_files, $customer_name, $customer_email );
        self::do_send_customer( $replacements, $customer_email );
    }

    // ── Send admin email ──────────────────────────────────────────────────────
    private static function do_send_admin( $replacements, $gerber_files, $reply_name, $reply_email ) {
        $admin_to = get_option( 'instant_form_admin_email' );
        if ( empty( $admin_to ) ) {
            $admin_to = get_option( 'admin_email' );
        }

        if ( ! is_email( $admin_to ) ) {
            error_log( "Instant PCB Form: Admin email '$admin_to' is not valid. Aborting." );
            return;
        }

        $subject = self::apply_replacements(
            get_option( 'instant_form_admin_subject', 'NEW PCB ORDER PAID: [part_number]' ),
            $replacements
        );
        $body = self::apply_replacements(
            get_option( 'instant_form_admin_content', self::get_default_admin_template() ),
            $replacements
        );

        // Attachments
        $attachments = array();
        foreach ( (array) $gerber_files as $file ) {
            $path = isset( $file['path'] ) ? $file['path'] : '';
            if ( $path && file_exists( $path ) ) {
                $attachments[] = $path;
            }
        }

        error_log( "Instant PCB Form: Sending admin email → $admin_to | subject: $subject | attachments: " . count( $attachments ) );

        $hdrs = self::build_headers( $reply_email, $reply_name );

        // Attempt 1: with attachments
        $ok = wp_mail( $admin_to, $subject, $body, $hdrs, $attachments );

        // Attempt 2: without attachments (in case attachment is too large / mime issue)
        if ( ! $ok && ! empty( $attachments ) ) {
            error_log( "Instant PCB Form: Admin email attempt 1 failed. Retrying without attachments." );
            $ok = wp_mail( $admin_to, $subject, $body, $hdrs );
        }

        // Attempt 3: completely bare — just in case the From header is blocked
        if ( ! $ok ) {
            error_log( "Instant PCB Form: Admin email attempt 2 failed. Retrying with bare headers." );
            $ok = wp_mail( $admin_to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
        }

        error_log( $ok
            ? "Instant PCB Form: Admin email SENT ✓ to $admin_to"
            : "Instant PCB Form: Admin email FAILED ✗ to $admin_to — check SMTP settings."
        );
    }

    // ── Send customer email ───────────────────────────────────────────────────
    private static function do_send_customer( $replacements, $to_email ) {
        if ( ! is_email( $to_email ) ) {
            error_log( "Instant PCB Form: Customer email '$to_email' is invalid. Skipping." );
            return;
        }

        $subject = self::apply_replacements(
            get_option( 'instant_form_customer_subject', 'Order Confirmation: [part_number]' ),
            $replacements
        );
        $body = self::apply_replacements(
            get_option( 'instant_form_customer_content', self::get_default_customer_template() ),
            $replacements
        );

        // Reply-To = the admin email set in plugin settings
        $admin_reply = get_option( 'instant_form_admin_email' );
        if ( empty( $admin_reply ) ) {
            $admin_reply = get_option( 'admin_email' );
        }
        $hdrs = self::build_headers( $admin_reply );

        error_log( "Instant PCB Form: Sending customer email → $to_email" );

        $ok = wp_mail( $to_email, $subject, $body, $hdrs );

        error_log( $ok
            ? "Instant PCB Form: Customer email SENT ✓ to $to_email"
            : "Instant PCB Form: Customer email FAILED ✗ to $to_email — check SMTP settings."
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function build_headers( $reply_email = '', $reply_name = '' ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $blog  = sanitize_text_field( get_bloginfo( 'name' ) );
        $host  = parse_url( home_url(), PHP_URL_HOST );
        $domain = $host ? str_replace( 'www.', '', $host ) : '';
        $from   = $domain ? "noreply@$domain" : get_option( 'admin_email' );

        if ( is_email( $from ) ) {
            $headers[] = "From: $blog <$from>";
        }

        if ( $reply_email && is_email( $reply_email ) ) {
            $reply_name = sanitize_text_field( $reply_name );
            $reply_name = str_replace( array( ',', '<', '>', '"', "'" ), '', $reply_name );
            $headers[]  = $reply_name
                ? "Reply-To: $reply_name <$reply_email>"
                : "Reply-To: $reply_email";
        }

        return $headers;
    }

    private static function apply_replacements( $content, $replacements ) {
        // Decode HTML entities injected by WordPress TinyMCE editor
        $content = html_entity_decode( html_entity_decode( $content ) );

        // Strip invisible HTML that editors sometimes wrap around [ ... ]
        $content = preg_replace_callback( '/\[([^\]]+)\]/', function ( $m ) {
            $inner = trim( strip_tags( $m[1] ) );
            $inner = str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', $inner );
            return '[' . $inner . ']';
        }, $content );

        foreach ( $replacements as $tag => $value ) {
            $content = str_ireplace( $tag, is_scalar( $value ) ? (string) $value : '', $content );
        }

        return $content;
    }

    // ── Default templates ─────────────────────────────────────────────────────

    public static function get_default_admin_template() {
        return self::email_wrap(
            "<h3 style='margin-top:0;color:#0056b3;'>New PCB Order Paid: #[order_id]</h3>
            <p><strong>Part Number:</strong> [part_number]</p>

            <div style='background:#f8f9fa;padding:15px;border-radius:6px;margin:20px 0;border:1px solid #e9ecef;'>
                <strong>Customer Details:</strong><br>
                Name: [customer_name]<br>
                Email: <a href='mailto:[customer_email]'>[customer_email]</a>
            </div>

            <div style='background:#f8f9fa;padding:15px;border-radius:6px;margin:20px 0;border:1px solid #e9ecef;'>
                <strong>PCB Specs:</strong><br>
                Qty: [quantity] &nbsp;|&nbsp; Size: [board_size]<br>
                Layers: [layers] &nbsp;|&nbsp; Thickness: [thickness]\"<br>
                Copper: [copper]oz &nbsp;|&nbsp; Finish: [surface_finish]<br>
                Mask: [solder_mask] &nbsp;|&nbsp; Silk: [silkscreen]<br>
                Build Time: [build_time]
            </div>

            <div style='background:#f8f9fa;padding:15px;border-radius:6px;margin:20px 0;border:1px solid #e9ecef;'>
                <strong>Shipping Address:</strong><br>
                [shipping_address], [shipping_city], [shipping_state] [shipping_zip], [shipping_country]
            </div>

            <div style='text-align:center;margin-top:30px;'>
                <div style='font-size:28px;color:#28a745;font-weight:bold;'>[total_price]</div>
                <div style='margin-top:20px;'>[gerber_link]</div>
            </div>",
            "Admin Notification"
        );
    }

    public static function get_default_customer_template() {
        return self::email_wrap(
            "<h3 style='margin-top:0;'>Hi [customer_name],</h3>
            <p>Thank you for your order! We have received your payment for part number <strong>[part_number]</strong>.</p>

            <div style='background:#f0f7ff;padding:20px;border-radius:8px;margin:25px 0;border:1px solid #cce5ff;text-align:center;'>
                <span style='display:block;font-size:14px;color:#555;'>Total Paid</span>
                <span style='display:block;font-size:32px;color:#28a745;font-weight:bold;'>[total_price]</span>
                <span style='display:block;font-size:14px;color:#777;margin-top:5px;'>Order #[order_id]</span>
            </div>

            <div style='margin-bottom:25px;'>
                <h4 style='border-bottom:2px solid #eee;padding-bottom:10px;margin-bottom:15px;'>Order Summary</h4>
                <table style='width:100%;border-collapse:collapse;'>
                    <tr><td style='padding:8px 0;color:#666;'>Board Size:</td><td style='font-weight:bold;'>[board_size]</td></tr>
                    <tr><td style='padding:8px 0;color:#666;'>Quantity:</td><td style='font-weight:bold;'>[quantity] pcs</td></tr>
                    <tr><td style='padding:8px 0;color:#666;'>Layers:</td><td style='font-weight:bold;'>[layers]</td></tr>
                    <tr><td style='padding:8px 0;color:#666;'>Surface Finish:</td><td style='font-weight:bold;'>[surface_finish]</td></tr>
                    <tr><td style='padding:8px 0;color:#666;'>Est. Build Time:</td><td style='font-weight:bold;'>[build_time]</td></tr>
                </table>
            </div>

            <p>We will review your Gerber files and begin production once confirmed. You will receive a shipping update soon.</p>",
            "&copy; " . date( 'Y' ) . " " . get_bloginfo( 'name' ) . ". All rights reserved."
        );
    }

    private static function email_wrap( $content, $footer ) {
        $name  = get_bloginfo( 'name' );
        return '<!DOCTYPE html>
<html>
<body style="margin:0;padding:40px 20px;font-family:\'Segoe UI\',Arial,sans-serif;background:#f0f2f5;color:#333;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);border-top:6px solid #28a745;">
  <div style="padding:30px 40px 10px;text-align:center;border-bottom:1px solid #eee;">
    <h2 style="margin:0;color:#28a745;">' . esc_html( $name ) . '</h2>
  </div>
  <div style="padding:30px 40px;line-height:1.7;">' . $content . '</div>
  <div style="background:#f8f9fa;padding:20px;text-align:center;font-size:12px;color:#888;border-top:1px solid #eee;">' . $footer . '</div>
</div>
</body>
</html>';
    }

    // ── Test email (used from admin settings page) ────────────────────────────
    public static function send_test_email( $to_email ) {
        if ( ! is_email( $to_email ) ) return false;
        $hdrs = self::build_headers();
        $sent = wp_mail(
            $to_email,
            'Instant PCB Quote — Test Email',
            '<h2>Test Email Success</h2><p>Your mail configuration is working correctly. Form emails will be sent to this address when orders are placed.</p>',
            $hdrs
        );
        error_log( $sent
            ? "Instant PCB Form: Test email SENT to $to_email."
            : "Instant PCB Form: Test email FAILED to $to_email."
        );
        return $sent;
    }
}
