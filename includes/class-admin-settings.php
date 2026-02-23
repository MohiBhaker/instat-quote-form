<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instant_Form_Admin_Settings {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
    }

    public static function add_menu() {
        add_menu_page(
            'Instant Form Emails',
            'Instant Quote Emails',
            'manage_options',
            'instant-form-emails',
            array( __CLASS__, 'render_page' ),
            'dashicons-email-alt',
            50
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['instant_form_save_settings'] ) ) {
            check_admin_referer( 'instant_form_settings_nonce' );
            update_option( 'instant_form_admin_email', sanitize_email( $_POST['admin_email'] ) );
            update_option( 'instant_form_admin_subject', sanitize_text_field( $_POST['admin_subject'] ) );
            update_option( 'instant_form_admin_content', wp_kses_post( $_POST['admin_content'] ) );
            update_option( 'instant_form_customer_subject', sanitize_text_field( $_POST['customer_subject'] ) );
            update_option( 'instant_form_customer_content', wp_kses_post( $_POST['customer_content'] ) );
            echo '<div class="updated"><p>Settings Saved!</p></div>';
        }

        $admin_email = get_option( 'instant_form_admin_email', 'mohitbhaker181@gmail.com' );
        $admin_sub   = get_option( 'instant_form_admin_subject', 'NEW PCB ORDER PAID: [part_number]' );
        $admin_cont  = get_option( 'instant_form_admin_content', Instant_Form_Email_Handler::get_default_admin_template() );
        
        $cust_sub    = get_option( 'instant_form_customer_subject', 'Order Confirmation: [part_number]' );
        $cust_cont   = get_option( 'instant_form_customer_content', Instant_Form_Email_Handler::get_default_customer_template() );
        ?>
        <div class="wrap">
            <h1>Instant PCB Quote Email Settings</h1>
            <form method="post">
                <?php wp_nonce_field( 'instant_form_settings_nonce' ); ?>
                
                <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
                    <h2>Available Shortcodes</h2>
                    <p>You can use these shortcodes in the subject or body of the emails:</p>
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px;">
                        <code>[order_id]</code> <code>[date]</code> <code>[customer_name]</code>
                        <code>[customer_email]</code> <code>[part_number]</code> <code>[total_price]</code>
                        <code>[pcb_cost]</code> <code>[shipping_cost]</code> <code>[build_time]</code>
                        <code>[shipping_address]</code> <code>[shipping_city]</code> <code>[shipping_state]</code>
                        <code>[shipping_zip]</code> <code>[shipping_country]</code> <code>[board_size]</code>
                        <code>[quantity]</code> <code>[layers]</code> <code>[thickness]</code>
                        <code>[copper]</code> <code>[surface_finish]</code> <code>[solder_mask]</code>
                        <code>[silkscreen]</code> <code>[gerber_link] (HTML Link)</code> <code>[gerber_url] (Raw URL)</code>
                        <code>[payment_method]</code> <code>[special_req]</code> <code>[total_area]</code>
                    </div>
                </div>

                <hr>

                <h2>Admin Notification Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Admin Email To:</label></th>
                        <td><input type="email" name="admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Subject:</label></th>
                        <td><input type="text" name="admin_subject" value="<?php echo esc_attr( $admin_sub ); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Email Body:</label></th>
                        <td><?php wp_editor( $admin_cont, 'admin_content', array( 'textarea_rows' => 10 ) ); ?></td>
                    </tr>
                </table>

                <hr>

                <h2>Customer Confirmation Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Subject:</label></th>
                        <td><input type="text" name="customer_subject" value="<?php echo esc_attr( $cust_sub ); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Email Body:</label></th>
                        <td><?php wp_editor( $cust_cont, 'customer_content', array( 'textarea_rows' => 10 ) ); ?></td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="instant_form_save_settings" class="button button-primary" value="Save Email Settings">
                </p>
            </form>

            <hr>
            <h2>Test Admin Email</h2>
            <p>Click below to send a test email to the saved Admin Email Address (<b><?php echo esc_html($admin_email); ?></b>). This helps verify if your server is sending emails correctly.</p>
            <form method="post">
                <?php wp_nonce_field( 'instant_form_test_email_nonce' ); ?>
                <input type="submit" name="instant_form_send_test_email" class="button button-secondary" value="Send Test Email Now">
            </form>

            <?php
            if ( isset( $_POST['instant_form_send_test_email'] ) ) {
                check_admin_referer( 'instant_form_test_email_nonce' );
                $sent = Instant_Form_Email_Handler::send_test_email( $admin_email );
                if ( $sent ) {
                    echo '<div class="updated"><p>Test email sent successfully! Please check your inbox (and spam folder).</p></div>';
                } else {
                    echo '<div class="error"><p>Test email FAILED to send. Please check your server mail logs or SMTP settings.</p></div>';
                }
            }
            ?>

            <hr>
            <h2>Debug Info</h2>
            <div style="background:#fff; padding:20px; border:1px solid #ccc;">
                <h3>Last PHP Mailer Error (if any):</h3>
                <pre><?php global $phpmailer; if ( isset( $phpmailer ) ) print_r( $phpmailer->ErrorInfo ); else echo "No error info available."; ?></pre>
            </div>
        </div>
        <?php
    }
}
