<?php
/**
 * Plugin Name: Instant PCB Quote Form
 * Description: A powerful instant PCB calculation form with WooCommerce integration and in-form checkout.
 * Version: 1.5
 * Author: Mohit Kumar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// core includes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-email-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-form-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-thankyou-handler.php';

// Initialize classes
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    Instant_Form_Admin_Settings::init();
    Instant_Form_Handler::init();
    Instant_Form_Email_Handler::init();
    Instant_Form_Thankyou_Handler::init();

    // ── REDIRECT PREVENTION ──────────────────────────────────────────────────────
    // wc_checkout_redirect() fires on BOTH the form page AND the order-pay endpoint
    // because both register as is_checkout() with an empty cart.

    add_action( 'template_redirect', function() {
        $uri              = $_SERVER['REQUEST_URI'] ?? '';
        $checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );
        
        $is_order_pay     = is_wc_endpoint_url( 'order-pay' ) || isset( $_GET['pay_for_order'] ) || strpos( $uri, '/order-pay/' ) !== false;
        $is_form_page     = ( is_page( $checkout_page_id ) || is_page( 'instant-online-quote' ) ) && ! is_wc_endpoint_url();
        $is_form_uri      = ( strpos( $uri, 'instant-online-quote' ) !== false || ( $checkout_page_id && is_page( $checkout_page_id ) ) );

        // Block the empty-cart redirect for the form page AND the order-pay endpoint
        if ( $is_form_page || $is_order_pay ) {
            remove_action( 'template_redirect', 'wc_checkout_redirect', 10 );
        }

        if ( $is_form_uri || $is_order_pay ) {
            add_filter( 'woocommerce_is_store_coming_soon', '__return_false', 9999 );
        }

        if ( $is_form_page ) {
            remove_action( 'template_redirect', 'redirect_canonical' );
        }
    }, 1 );

    // Belt-and-suspenders: disable the WC filter itself for our protected pages
    add_filter( 'woocommerce_checkout_redirect_empty_cart', function( $redirect ) {
        $checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );
        $uri              = $_SERVER['REQUEST_URI'] ?? '';
        
        if ( is_wc_endpoint_url( 'order-pay' ) || isset( $_GET['pay_for_order'] ) || strpos( $uri, '/order-pay/' ) !== false ) {
            return false;
        }
        if ( is_page( $checkout_page_id ) || is_page( 'instant-online-quote' ) ) {
            return false;
        }
        return $redirect;
    } );

    // ── ORDER-PAY + ORDER-RECEIVED CONTENT FIX ──────────────────────────────────
    add_filter( 'the_content', function( $content ) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $is_pay_endpoint      = ( strpos( $uri, '/order-pay/' ) !== false || isset( $_GET['pay_for_order'] ) || is_wc_endpoint_url( 'order-pay' ) );
        $is_received_endpoint = ( strpos( $uri, '/order-received/' ) !== false || is_wc_endpoint_url( 'order-received' ) );

        if ( $is_pay_endpoint || $is_received_endpoint ) {
            // For order-pay, we want a very clean UI showing only the card fields
            if ( $is_pay_endpoint ) {
                $order_id = absint( get_query_var( 'order-pay' ) );
                $card_html = '<div class="pcb-clean-pay-wrapper" style="max-width:600px; margin: 40px auto; padding: 20px; background:#fff; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.05);">';
                $card_html .= '<h2 style="text-align:center; color:#333; margin-bottom:20px;">Complete Your Secure Payment</h2>';
                $card_html .= do_shortcode( '[woocommerce_checkout]' );
                $card_html .= '</div>';
                return $card_html;
            }
            return do_shortcode( '[woocommerce_checkout]' );
        }
        return $content;
    }, 999 );

    // Hide the Page Title on order-pay pages to keep it clean
    add_filter( 'the_title', function( $title, $id = null ) {
        if ( in_the_loop() && ( is_wc_endpoint_url( 'order-pay' ) || isset( $_GET['pay_for_order'] ) ) ) {
            return ''; // Hide "Instant Online Quote" title on payment page
        }
        return $title;
    }, 10, 2 );
    // Force WC checkout scripts on the form page, order-pay, and order-received
    add_action( 'wp_enqueue_scripts', function() {
        $on_form      = is_page( 'instant-online-quote' ) || ( is_page() && has_shortcode( get_post()->post_content ?? '', 'instant_form' ) );
        $on_order_pay = is_wc_endpoint_url( 'order-pay' ) || isset( $_GET['pay_for_order'] );
        $on_received  = is_wc_endpoint_url( 'order-received' );
        if ( $on_form || $on_order_pay || $on_received ) {
            add_filter( 'woocommerce_is_checkout', '__return_true', 9999 );
        }
    }, 1 );
});

/**
 * Check if WooCommerce is active
 */
function is_instant_form_wc_active() {
    return class_exists( 'WooCommerce' );
}

function instant_form_shortcode() {
    if ( ! is_instant_form_wc_active() ) {
        return '<p style="color:red; text-align:center;">WooCommerce is required for this form to function properly.</p>';
    }

    // Safety: DO NOT render the quote form if we are on a WC payment or thank-you page
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_pay_endpoint = ( is_wc_endpoint_url( 'order-pay' ) || strpos( $uri, '/order-pay/' ) !== false || isset( $_GET['pay_for_order'] ) );
    $is_received_endpoint = ( is_wc_endpoint_url( 'order-received' ) || strpos( $uri, '/order-received/' ) !== false );

    if ( $is_pay_endpoint || $is_received_endpoint ) {
        return '';
    }

    // Enqueue WC scripts needed for checkout/payment gateways
    if ( function_exists( 'wc_enqueue_js' ) ) {
        wp_enqueue_script( 'wc-checkout' );
        
        if ( class_exists( 'WC_Gateway_Stripe' ) ) {
            // Stripe processing will now happen exclusively on the WC Pay page to ensure 100% reliability
        }
    }

    ob_start();
    ?>
    <style>
        .pcb-quote-container { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #333; max-width: 800px; margin: auto; }
        .pcb-quote-container .container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); border-top: 6px solid #28a745; }
        .pcb-quote-container h2 { text-align: center; color: #1e7e34; margin-bottom: 25px; }
        .pcb-quote-container .input-group { margin-bottom: 15px; }
        .pcb-quote-container label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9em; color: #555; }
        .pcb-quote-container input, .pcb-quote-container select, .pcb-quote-container textarea { width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .pcb-quote-container textarea { height: 80px; resize: vertical; }
        .pcb-quote-container .flex-row { display: flex; gap: 10px; align-items: center; }
        .pcb-quote-container .flex-row div { flex: 1; }
        .pcb-quote-container .dim-sep { font-weight: bold; color: #999; padding-top: 20px; }
        .pcb-quote-container .btn-calculate { width: 100%; padding: 15px; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 18px; margin-top: 20px; font-weight: bold; transition: background 0.3s; }
        .pcb-quote-container .btn-calculate:hover { background: #218838; }
        
        .pcb-quote-container .btn-action { width: 100%; padding: 15px; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 18px; margin-top: 10px; font-weight: bold; transition: background 0.3s; }
        .pcb-quote-container .btn-submit-order { background: #007bff; display: none; }
        .pcb-quote-container .btn-submit-order:hover { background: #0069d9; }
        
        .pcb-quote-container .btn-quote-only { background: #6c757d; display: none; }
        .pcb-quote-container .btn-quote-only:hover { background: #5a6268; }
        
        .pcb-quote-container .btn-action:disabled { background: #ccc !important; cursor: not-allowed; }
        
        .pcb-quote-container #result { margin-top: 30px; background: #f8f9fa; padding: 25px; border-radius: 8px; display: none; border: 1px solid #dee2e6; }
        .pcb-quote-container .price-line { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px dashed #ccc; padding-bottom: 5px; }
        .pcb-quote-container .total-price { color: #28a745; font-size: 1.6em; font-weight: bold; }
        .pcb-quote-container .highlight { color: #0056b3; font-weight: bold; }
        .pcb-quote-container .error-msg { color: #d9534f; font-weight: bold; text-align: center; padding: 20px; border: 2px solid #d9534f; border-radius: 8px; display: none; margin-top: 25px; }
        .pcb-quote-container .success-msg { color: #28a745; font-weight: bold; text-align: center; padding: 20px; border: 2px solid #28a745; border-radius: 8px; display: none; margin-top: 25px; }
        .pcb-quote-container #panel_details { display: none; padding: 15px; border: 1px solid #e0e0e0; border-radius: 8px; background: #fafafa; margin-top: 10px; }
        .pcb-quote-container #shipping_section { display: none; margin-top: 20px; padding: 25px; border: 2px solid #007bff; border-radius: 12px; background: #f0f7ff; }
        
        .pcb-quote-container hr { border: 0; border-top: 1px solid #eee; margin: 20px 0; }
        
        /* Payment Section Styling */
        .pcb-payment-section { margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
        .pcb-payment-section h3 { margin-top: 0; font-size: 1.2em; color: #333; }
        .payment-gateway-item { margin-bottom: 10px; padding: 10px; border: 1px solid #eee; border-radius: 6px; cursor: pointer; }
        .payment-gateway-item:hover { border-color: #007bff; background: #f8fbff; }
        .payment-gateway-item.selected { border-color: #007bff; background: #e7f3ff; }
        .payment-gateway-item input[type="radio"] { margin-right: 10px; }
        .payment-fields-container { margin-top: 15px; padding: 15px; border-top: 1px solid #eee; display: none; }

        /* Assumptions Styling */
        .pcb-assumptions { margin-top: 40px; border-top: 2px solid #eee; padding-top: 20px; font-size: 0.85em; color: #666; }
        .pcb-assumptions h3 { color: #333; font-size: 1.1em; margin-bottom: 15px; }
        .pcb-assumptions ul { padding-left: 20px; }
        .pcb-assumptions li { margin-bottom: 8px; line-height: 1.4; }

        .file-upload-info { font-size: 0.8em; color: #888; margin-top: 5px; }

        #payment_fields_wrapper { min-height: 50px; display: none; margin-top: 15px; border: 1px dashed #ddd; border-radius: 8px; padding: 15px; background: #fafafa; }
    </style>

    <div class="pcb-quote-container woocommerce-checkout">
        <div class="container" id="pcb-instant-form">
            <h2>Instant Online PCB Quote</h2>

            <!-- Contact Info -->
            <div class="flex-row">
                <div class="input-group">
                    <label>Name (Required):</label>
                    <input type="text" id="user_name" placeholder="Full Name">
                </div>
                <div class="input-group">
                    <label>Email Address (Required):</label>
                    <input type="email" id="user_email" placeholder="Email (must be valid)">
                </div>
            </div>

            <div class="input-group">
                <label>Part Number:</label>
                <input type="text" id="part_number" placeholder="Enter Part Number">
            </div>

            <!-- Billing metadata for order creation -->
            <input type="hidden" id="billing_first_name" name="billing_first_name" value="">
            <input type="hidden" id="billing_last_name" name="billing_last_name" value="">
            <input type="hidden" id="billing_email" name="billing_email" value="">

            <!-- PCB Specs -->
            <div class="input-group">
                <label>Unit Board Size (inch):</label>
                <div class="flex-row">
                    <input type="number" id="u_w" placeholder="Width" step="0.001">
                    <span class="dim-sep">x</span>
                    <input type="number" id="u_l" placeholder="Height" step="0.001">
                </div>
            </div>

            <div class="input-group">
                <label>Delivery Format:</label>
                <select id="is_panel" onchange="togglePanel()">
                    <option value="no">Single Boards</option>
                    <option value="yes">Panelized</option>
                </select>
            </div>

            <div id="panel_details">
                <div class="input-group">
                    <label>Panel Dimensions (inch):</label>
                    <div class="flex-row">
                        <input type="number" id="p_w" placeholder="Width" step="0.001">
                        <span class="dim-sep">x</span>
                        <input type="number" id="p_l" placeholder="Height" step="0.001">
                    </div>
                </div>
                <div class="input-group">
                    <label>Boards per Panel:</label>
                    <input type="number" id="p_qty" value="1" min="1">
                </div>
            </div>

            <div class="input-group">
                <label>Total Quantity (pcs):</label>
                <input type="number" id="order_qty" value="100" min="1">
            </div>

            <div class="flex-row">
                <div class="input-group">
                    <label>Layers:</label>
                    <select id="layers">
                        <option value="1">1 Layer</option>
                        <option value="2">2 Layers</option>
                        <option value="4" selected>4 Layers</option>
                        <option value="6">6 Layers</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Board Thickness:</label>
                    <select id="thickness">
                        <option value="0.063" selected>0.063" (Std)</option>
                        <option value="0.031">0.031"</option>
                        <option value="0.039">0.039"</option>
                        <option value="0.047">0.047"</option>
                        <option value="0.059">0.059"</option>
                        <option value="0.079">0.079"</option>
                        <option value="0.093">0.093"</option>
                    </select>
                </div>
            </div>

            <div class="input-group">
                <label>Material (Tg):</label>
                <select id="material">
                    <option value="0">Standard FR4 (Tg130-140)</option>
                    <option value="0.09">FR4 Tg150</option>
                    <option value="0.13">FR4 Tg170</option>
                    <option value="0.23">FR4 Tg180</option>
                </select>
            </div>

            <div class="flex-row">
                <div class="input-group">
                    <label>Copper Weight:</label>
                    <select id="copper">
                        <option value="1">1 oz</option>
                        <option value="1.5">1.5 oz</option>
                        <option value="2">2 oz</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Surface Finish:</label>
                    <select id="finish">
                        <option value="HASL">HASL LF</option>
                        <option value="ENIG">ENIG</option>
                        <option value="ENIG2">ENIG 2u"</option>
                        <option value="Silver">Immersion Silver</option>
                        <option value="Immersion Tin">Immersion Tin</option>
                    </select>
                </div>
            </div>

            <div class="flex-row">
                <div class="input-group">
                    <label>Solder Mask Color:</label>
                    <select id="solder_mask">
                        <option value="Green">Green</option>
                        <option value="Black">Black</option>
                        <option value="Blue">Blue</option>
                        <option value="Red">Red</option>
                        <option value="White">White</option>
                        <option value="Yellow">Yellow</option>
                        <option value="None">None</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Silkscreen Color:</label>
                    <select id="silkscreen">
                        <option value="White">White</option>
                        <option value="Black">Black</option>
                        <option value="Yellow">Yellow</option>
                        <option value="None">None</option>
                    </select>
                </div>
            </div>

            <div class="input-group">
                <label>Gerber File(s) (Required):</label>
                <input type="file" id="gerber_file" accept=".zip,.rar,.7z" multiple>
                <div class="file-upload-info">Please attach your Gerber files in a .zip or .rar format.</div>
            </div>

            <div class="input-group">
                <label>Special Requirements:</label>
                <textarea id="special_req" placeholder="e.g. Specific stack-up, impedance control, or color requirements..."></textarea>
            </div>

            <button type="button" class="btn-calculate" onclick="calculatePCB()">Calculate Price</button>

            <div id="status_msg" class="success-msg"></div>
            <div id="error_container" class="error-msg"></div>

            <div id="result">
                <div class="price-line"><span>Contact:</span><span id="res_contact" class="highlight"></span></div>
                <div class="price-line"><span>Part Number:</span><span id="res_pn" class="highlight"></span></div>
                <div class="price-line"><span>Quantity:</span><span id="res_qty" class="highlight"></span></div>
                <div class="price-line"><span>Total Area:</span><span><span id="res_total_area"></span> m²</span></div>
                <hr>
                <div class="price-line"><span>PCB Cost:</span><span>$<span id="res_pcb_cost"></span></span></div>
                <div class="price-line"><span>Shipping Cost:</span><span>$<span id="res_shipping"></span></span></div>
                <hr>
                <div class="price-line"><span>Total Final Price:</span><span class="total-price">$<span id="res_total_p"></span></span></div>
                <div class="price-line"><span>Build Time:</span><span id="res_build_time" class="highlight"></span></div>
                
                <!-- Shipping section -->
                <div id="shipping_section">
                    <hr>
                    <h3>Shipping Information</h3>
                    <div class="input-group">
                        <label>Street Address:</label>
                        <input type="text" id="ship_address" placeholder="123 Main St">
                    </div>
                    <div class="flex-row">
                        <div class="input-group">
                            <label>City:</label>
                            <input type="text" id="ship_city" placeholder="City">
                        </div>
                        <div class="input-group">
                            <label>State/Province:</label>
                            <input type="text" id="ship_state" placeholder="State">
                        </div>
                    </div>
                    <div class="flex-row">
                        <div class="input-group">
                            <label>Zip/Postcode:</label>
                            <input type="text" id="ship_postcode" placeholder="Zip Code">
                        </div>
                        <div class="input-group">
                            <label>Country:</label>
                            <input type="text" id="ship_country" placeholder="Country">
                        </div>
                    </div>

                    <!-- Payment Methods Section -->
                    <div class="pcb-payment-section">
                        <h3>Payment Method</h3>
                        <div id="payment_gateways_list" class="payment_methods">
                            <p>Loading payment methods...</p>
                        </div>
                        <div id="payment_fields_wrapper" class="payment-fields-container">
                            <!-- Gateway specific fields (like Stripe) will be loaded here -->
                        </div>
                    </div>

                    <div class="flex-row" style="margin-top:20px; gap:15px;">
                        <button type="button" id="submit_order_btn" class="btn-action btn-submit-order" onclick="submitFinalOrder()">Complete Order & Pay</button>
                        <button type="button" id="send_quote_btn" class="btn-action btn-quote-only" onclick="sendQuoteOnly()">Send Quote to Email</button>
                    </div>
                    <div id="payment_status_msg" style="margin-top: 15px;"></div>
                    <div id="payment_processing_msg" style="display:none; text-align:center; margin-top:10px;">
                        <p>Processing, please wait...</p>
                    </div>
                </div>
            </div>

            <div class="pcb-assumptions">
                <h3>The online quotes are base on the assumptions:</h3>
                <ul>
                    <li>No Blind/ Buried Vias, No gold finger, No slots, No multiple part numbers on one panel</li>
                    <li>Minimum trace width/space: 6mil/6mil. Minimum hole size: 12mil</li>
                    <li>Limit the hole count to 25 per sq inch for 2Layer; 32 per sq inch for 4layer; 38 per sq inch for 6layer</li>
                    <li> Board length should be less than 23.5″ </li>
                    <li> Please get a custom quote if your requirements are different from above. Please get a custom quote if your lead time is less than 5 days. Please get a custom quote if you have multiple part numbers in the same Gerber set. </li>
                    <li> Both online quotes and orders will be confirmed after we review the Gerber file. Prices and lead time are subject to change if Gerber file does not match online form. </li>
                    <li> If you have any questions about this quote form, please email us <a href="mailto:info@superpcb.com">info@superpcb.com</a> or call <a href="tel:+12145509837">(214) 550-9837</a>. </li>
                </ul>
            </div>
        </div>
    </div>

    <script>
    // Global state for payment
    let selectedGateway = null;
    let lastCalculatedData = null;

    // Synchronize name field with Stripe's data attribute and parameters
    document.addEventListener('DOMContentLoaded', function() {
        const syncBillingData = function() {
            const nameInput = document.getElementById('user_name');
            const emailInput = document.getElementById('user_email');
            
            if (nameInput) {
                let fullName = nameInput.value.trim();
                if (!fullName) fullName = 'Guest User'; // Fallback for mounting
                
                const parts = fullName.split(' ');
                const first = parts[0] || 'Guest';
                const last = parts.length > 1 ? parts.slice(1).join(' ') : 'User';

                // Update hidden billing fields in DOM
                const hFirst = document.getElementById('billing_first_name');
                const hLast = document.getElementById('billing_last_name');
                const stripeData = document.getElementById('stripe-payment-data');
                
                if (hFirst) hFirst.value = first;
                if (hLast) hLast.value = last;
                if (stripeData) stripeData.setAttribute('data-full-name', fullName);

                // Update parameters for scripts
                if (typeof wc_stripe_params !== 'undefined') {
                    wc_stripe_params.billing_first_name = first;
                    wc_stripe_params.billing_last_name = last;
                }
                if (typeof wc_stripe_upe_params !== 'undefined') {
                    if (!wc_stripe_upe_params.customerBillingData) wc_stripe_upe_params.customerBillingData = {};
                    wc_stripe_upe_params.customerBillingData.name = fullName;
                }
            }
            
            if (emailInput) {
                const hEmail = document.getElementById('billing_email');
                if (hEmail) hEmail.value = emailInput.value;
                if (typeof wc_stripe_params !== 'undefined') wc_stripe_params.billing_email = emailInput.value;
                if (typeof wc_stripe_upe_params !== 'undefined') {
                    if (!wc_stripe_upe_params.customerBillingData) wc_stripe_upe_params.customerBillingData = {};
                    wc_stripe_upe_params.customerBillingData.email = emailInput.value;
                }
            }
        };

        const nameInput = document.getElementById('user_name');
        const emailInput = document.getElementById('user_email');
        
        if (nameInput) nameInput.addEventListener('input', syncBillingData);
        if (emailInput) emailInput.addEventListener('input', syncBillingData);
        
        // Initial sync
        syncBillingData();
    });

    function togglePanel() {
        const isPanel = document.getElementById('is_panel').value;
        document.getElementById('panel_details').style.display = (isPanel === 'yes') ? 'block' : 'none';
    }

    function validateEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/i;
        return regex.test(email);
    }

    async function fetchPaymentGateways() {
        const listDiv = document.getElementById('payment_gateways_list');
        try {
            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=instant_form_get_gateways');
            const result = await response.json();
            
            if (result.success && result.data.length > 0) {
                let html = '';
                result.data.forEach((gateway, index) => {
                    html += `
                        <div class="payment-gateway-item ${index === 0 ? 'selected' : ''}" onclick="selectGateway('${gateway.id}')">
                            <input type="radio" name="payment_method" id="payment_method_${gateway.id}" value="${gateway.id}" ${index === 0 ? 'checked' : ''}>
                            <strong>${gateway.title}</strong>
                            <p style="margin:5px 0 0 25px; font-size:0.85em; color:#666;">${gateway.description}</p>
                        </div>
                    `;
                    if (index === 0) selectedGateway = gateway.id;
                });
                listDiv.innerHTML = html;
                loadGatewayFields();
            } else {
                listDiv.innerHTML = '<p style="color:red;">No payment methods available. Please contact site admin.</p>';
            }
        } catch (error) {
            listDiv.innerHTML = '<p style="color:red;">Error loading payment methods.</p>';
        }
    }

    function selectGateway(id) {
        selectedGateway = id;
        const items = document.querySelectorAll('.payment-gateway-item');
        items.forEach(item => {
            const radio = item.querySelector('input');
            if (radio.value === id) {
                item.classList.add('selected');
                radio.checked = true;
            } else {
                item.classList.remove('selected');
            }
        });
        loadGatewayFields();
    }

    async function loadGatewayFields() {
        const wrapper = document.getElementById('payment_fields_wrapper');
        wrapper.style.display = 'none';
        wrapper.innerHTML = '';
        
        // Skip loading on-page fields for Stripe to avoid JS timing issues
        if (selectedGateway && selectedGateway.indexOf('stripe') !== -1) {
            wrapper.innerHTML = '<div style="padding:10px; color:#555; font-size:0.9em;">Clicking "Complete Order" will take you to the secure payment page.</div>';
            wrapper.style.display = 'block';
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'instant_form_get_gateway_fields');
            formData.append('gateway_id', selectedGateway);

            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success && result.data.html) {
                wrapper.innerHTML = result.data.html;
                wrapper.style.display = 'block';
                
                // Trigger scripts if any
                const scripts = wrapper.getElementsByTagName('script');
                for (let script of scripts) {
                    const newScript = document.createElement('script');
                    if (script.src) newScript.src = script.src;
                    else newScript.textContent = script.textContent;
                    document.body.appendChild(newScript);
                }
            }
        } catch (error) {
            console.error('Error loading gateway fields', error);
        }
    }

    function calculatePCB() {
        const resultDiv = document.getElementById('result');
        const errorDiv = document.getElementById('error_container');
        const statusMsg = document.getElementById('status_msg');
        const shippingSection = document.getElementById('shipping_section');
        const submitBtn = document.getElementById('submit_order_btn');
        const quoteBtn = document.getElementById('send_quote_btn');
        
        resultDiv.style.display = 'none';
        errorDiv.style.display = 'none';
        statusMsg.style.display = 'none';
        shippingSection.style.display = 'none';
        submitBtn.style.display = 'none';
        quoteBtn.style.display = 'none';

        const name = document.getElementById('user_name').value;
        const email = document.getElementById('user_email').value;
        if(!name || !validateEmail(email)) { alert("Please enter your name and a valid email address."); return; }

        const uw = parseFloat(document.getElementById('u_w').value) || 0;
        const ul = parseFloat(document.getElementById('u_l').value) || 0;
        const orderQty = parseFloat(document.getElementById('order_qty').value) || 0;
        if(uw <= 0 || ul <= 0 || orderQty <= 0) { alert("Please enter valid dimensions and quantity."); return; }

        let unitAreaSqIn = uw * ul;
        if (document.getElementById('is_panel').value === 'yes') {
            const pw = parseFloat(document.getElementById('p_w').value) || 0;
            const pl = parseFloat(document.getElementById('p_l').value) || 0;
            const pQty = parseFloat(document.getElementById('p_qty').value) || 1;
            
            if ((uw * ul * pQty) > (pw * pl)) {
                alert("Panel Validation Error: Your boards do not fit within the specified panel size.");
                return;
            }
            unitAreaSqIn = (pw * pl) / pQty;
        }

        const totalAreaM2 = (unitAreaSqIn * orderQty) * 0.00064516;
        if (totalAreaM2 > 3) { errorDiv.style.display = 'block'; errorDiv.innerText = "Area > 3m². Contact us for custom pricing."; return; }

        const layers = parseInt(document.getElementById('layers').value);
        const mFactor = parseFloat(document.getElementById('material').value);
        let baseRate = (layers === 1) ? (totalAreaM2 < 1 ? 120 : 100) : 
                       (layers === 2) ? (totalAreaM2 < 1 ? 180 : 150) : 
                       (layers === 4) ? (totalAreaM2 < 1 ? 280 : 250) : 
                       (totalAreaM2 < 1 ? 350 : 310);
        let baseCost = totalAreaM2 * baseRate * (1 + mFactor);

        const minDimMM = Math.min(uw, ul) * 25.4;
        const maxDimMM = Math.max(uw, ul) * 25.4;
        let sizeSurcharge = 0;
        if (minDimMM < 30.01) {
            let flatFee = 0, percentage = 0;
            if (minDimMM < 15) { flatFee = 60; percentage = 0.30; }
            else if (minDimMM <= 20) { flatFee = 40; percentage = 0.25; }
            else if (minDimMM <= 25) { flatFee = 35; percentage = 0.20; }
            else { flatFee = 25; percentage = 0.15; }
            sizeSurcharge = (totalAreaM2 < 1) ? flatFee : Math.max(flatFee, baseCost * percentage);
        }
        let overPerc = 0;
        if (maxDimMM > 500) overPerc = 0.40;
        else if (maxDimMM > 480) overPerc = 0.30;
        else if (maxDimMM > 450) overPerc = 0.20;
        else if (maxDimMM >= 431) overPerc = 0.15;
        const oversizeCost = baseCost * overPerc;

        let thickExtra = 0;
        if (document.getElementById('thickness').value === "0.093") {
            const tRate = (layers <= 4) ? {f1:25, f2:40, r:50} : {f1:30, f2:50, r:60};
            thickExtra = (totalAreaM2 < 0.5) ? tRate.f1 : (totalAreaM2 < 1.0 ? tRate.f2 : totalAreaM2 * tRate.r);
        }
        let copperExtra = 0;
        if (parseFloat(document.getElementById('copper').value) > 1) {
            const cRate = (layers === 1) ? 30 : (layers === 2) ? 50 : (layers === 4) ? 70 : 90;
            copperExtra = (totalAreaM2 >= 1) ? (totalAreaM2 * cRate) : (totalAreaM2 >= 0.5 ? cRate : (cRate * 0.6));
        }
        const fType = document.getElementById('finish').value;
        let finishExtra = 0;
        if (fType !== "HASL") {
            const fRates = { ENIG:[38,39], ENIG2:[45,50], Silver:[38,39], "Immersion Tin":[29,30] };
            finishExtra = (totalAreaM2 >= 1) ? totalAreaM2 * fRates[fType][1] : fRates[fType][0];
        }

        const tooling = layers === 4 ? 240 : layers === 6 ? 350 : 160;
        const pcbCostTotal = baseCost + sizeSurcharge + oversizeCost + thickExtra + copperExtra + finishExtra + tooling;
        const shipping = Math.max(45, 40 + (totalAreaM2 * (layers === 6 ? 5.3 : 4.2) * 10));
        
        let timeMsg = (layers <= 2) ? (totalAreaM2 < 1 ? "5-7 working days" : "2 weeks") : 
                      (layers === 4) ? (totalAreaM2 < 1 ? "7-9 working days" : "2 weeks") : 
                      (totalAreaM2 < 1 ? "10-12 working days" : "2-3 weeks");

        document.getElementById('res_contact').innerText = name + " (" + email + ")";
        document.getElementById('res_pn').innerText = document.getElementById('part_number').value || "N/A";
        document.getElementById('res_qty').innerText = orderQty + " pcs";
        document.getElementById('res_total_area').innerText = totalAreaM2.toFixed(3);
        document.getElementById('res_pcb_cost').innerText = pcbCostTotal.toFixed(2);
        document.getElementById('res_shipping').innerText = shipping.toFixed(2);
        document.getElementById('res_total_p').innerText = (pcbCostTotal + shipping).toFixed(2);
        document.getElementById('res_build_time').innerText = timeMsg;
        
        resultDiv.style.display = 'block';
        shippingSection.style.display = 'block';
        submitBtn.style.display = 'block';
        quoteBtn.style.display = 'block';

        lastCalculatedData = {
            total_price: (pcbCostTotal + shipping).toFixed(2),
            pcb_cost: pcbCostTotal.toFixed(2),
            shipping: shipping.toFixed(2),
            build_time: timeMsg,
            area: totalAreaM2.toFixed(3)
        };
        
        fetchPaymentGateways();
        resultDiv.scrollIntoView({ behavior: 'smooth' });
    }

    async function submitFinalOrder() {
        const fileInput = document.getElementById('gerber_file');
        const errorDiv = document.getElementById('error_container'); // Global top error
        const paymentStatusDiv = document.getElementById('payment_status_msg'); // Local feedback
        const mainBtn = document.getElementById('submit_order_btn');
        const procMsg = document.getElementById('payment_processing_msg');

        paymentStatusDiv.innerHTML = '';
        paymentStatusDiv.style.display = 'none';

        if (!fileInput.files.length) {
            alert("Please attach at least one Gerber file (.zip or .rar) to proceed.");
            return;
        }

        const addr = document.getElementById('ship_address').value;
        const city = document.getElementById('ship_city').value;
        const country = document.getElementById('ship_country').value;
        if (!addr || !city || !country) {
            alert("Please fill in your shipping address details.");
            return;
        }

        if (!selectedGateway) {
            alert("Please select a payment method.");
            return;
        }

        mainBtn.disabled = true;
        mainBtn.innerText = "Processing Order...";
        procMsg.style.display = 'block';
        errorDiv.style.display = 'none';

        const formData = new FormData();
        formData.append('action', 'instant_form_create_order');
        formData.append('name', document.getElementById('user_name').value);
        formData.append('email', document.getElementById('user_email').value);
        formData.append('part_number', document.getElementById('part_number').value);

        const nameParts = document.getElementById('user_name').value.trim().split(' ');
        const firstName = nameParts[0] || 'Guest';
        const lastName = nameParts.slice(1).join(' ') || 'User';
        const email = document.getElementById('user_email').value;

        formData.append('billing_first_name', firstName);
        formData.append('billing_last_name', lastName);
        formData.append('billing_email', email);
        
        // Handle Multiple Gerber Files
        for (let i = 0; i < fileInput.files.length; i++) {
            formData.append('gerber[]', fileInput.files[i]);
        }

        formData.append('payment_method', selectedGateway);
        
        formData.append('shipping', JSON.stringify({
            address: document.getElementById('ship_address').value,
            city: document.getElementById('ship_city').value,
            state: document.getElementById('ship_state').value,
            postcode: document.getElementById('ship_postcode').value,
            country: document.getElementById('ship_country').value
        }));

        formData.append('specs', JSON.stringify({
            size: document.getElementById('u_w').value + 'x' + document.getElementById('u_l').value,
            qty: document.getElementById('order_qty').value,
            layers: document.getElementById('layers').value,
            thickness: document.getElementById('thickness').value,
            finish: document.getElementById('finish').value,
            copper: document.getElementById('copper').value,
            mask: document.getElementById('solder_mask').value,
            silk: document.getElementById('silkscreen').value,
            req: document.getElementById('special_req').value,
            calculation: lastCalculatedData
        }));

        if (selectedGateway) {
            formData.append('payment_data', JSON.stringify({}));
        }

        try {
            // STEP 1: Create the WooCommerce Order via AJAX
            procMsg.innerHTML = 'Creating secure order...';
            
            const orderRes = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            });
            const orderResult = await orderRes.json();

            if (!orderResult.success) {
                throw new Error(orderResult.data || 'Could not process order.');
            }

            const { order_id, pay_url } = orderResult.data;
            
            // STEP 2: Redirect to the official Order Pay page for final payment
            mainBtn.innerText = "Redirecting to Payment...";
            paymentStatusDiv.style.display = 'block';
            paymentStatusDiv.innerHTML = '<div class="success-msg" style="display:block;">Order Created. Redirecting to secure payment page...</div>';
            
            setTimeout(() => {
                window.location.href = pay_url;
            }, 600);

        } catch (error) {
            console.error('Submission error:', error);
            paymentStatusDiv.style.display = 'block';
            paymentStatusDiv.innerHTML = '<div class="error-msg" style="display:block;">' + error.message + '</div>';
            mainBtn.disabled = false;
            mainBtn.innerText = "Complete Order & Pay";
            procMsg.style.display = 'none';
        }
    }

    async function sendQuoteOnly() {
        const name = document.getElementById('user_name').value;
        const email = document.getElementById('user_email').value;
        const quoteBtn = document.getElementById('send_quote_btn');
        const paymentStatusDiv = document.getElementById('payment_status_msg');
        const procMsg = document.getElementById('payment_processing_msg');

        if(!name || !validateEmail(email)) { 
            alert("Please enter your name and a valid email address."); 
            return; 
        }

        quoteBtn.disabled = true;
        quoteBtn.innerText = "Sending Quote...";
        procMsg.style.display = 'block';
        procMsg.innerHTML = 'Sending quote to your email...';
        paymentStatusDiv.style.display = 'none';

        const formData = new FormData();
        formData.append('action', 'instant_form_send_quote');
        formData.append('name', name);
        formData.append('email', email);
        formData.append('part_number', document.getElementById('part_number').value);
        formData.append('specs', JSON.stringify({
            size: document.getElementById('u_w').value + 'x' + document.getElementById('u_l').value,
            qty: document.getElementById('order_qty').value,
            layers: document.getElementById('layers').value,
            thickness: document.getElementById('thickness').value,
            finish: document.getElementById('finish').value,
            copper: document.getElementById('copper').value,
            mask: document.getElementById('solder_mask').value,
            silk: document.getElementById('silkscreen').value,
            req: document.getElementById('special_req').value,
            calculation: lastCalculatedData
        }));

        try {
            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                paymentStatusDiv.style.display = 'block';
                paymentStatusDiv.innerHTML = '<div class="success-msg" style="display:block;">' + result.data + '</div>';
                quoteBtn.innerText = "Quote Sent ✓";
            } else {
                throw new Error(result.data || 'Failed to send quote.');
            }
        } catch (error) {
            console.error('Quote error:', error);
            paymentStatusDiv.style.display = 'block';
            paymentStatusDiv.innerHTML = '<div class="error-msg" style="display:block;">' + error.message + '</div>';
            quoteBtn.disabled = false;
            quoteBtn.innerText = "Send Quote to Email";
        } finally {
            procMsg.style.display = 'none';
        }
    }
    </script>
<?php
    $output = ob_get_clean();
    return $output;
}
add_shortcode( 'instant_form', 'instant_form_shortcode' );

