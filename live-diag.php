<?php
/**
 * Live-server safe diagnostic: Check WC pages + Stripe mode
 * Access via: https://superpcb.com/wp-content/plugins/instant-form/live-diag.php
 */

// Find wp-load.php relative to this file (works on any server)
$root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
if (!file_exists($root . '/wp-load.php')) {
    die('Cannot find wp-load.php. Root guessed: ' . $root);
}
define('WP_USE_THEMES', false);
require_once $root . '/wp-load.php';

if (!current_user_can('manage_options')) {
    die('Access denied. Please log in as admin first.');
}

echo "<h2>WooCommerce Page Settings</h2><pre>";
$pages = [
    'woocommerce_checkout_page_id',
    'woocommerce_cart_page_id',
    'woocommerce_shop_page_id',
    'woocommerce_myaccount_page_id',
];
foreach ($pages as $key) {
    $id = get_option($key, 'NOT SET');
    $page = $id ? get_post((int)$id) : null;
    $slug = $page ? $page->post_name : '—';
    $status = $page ? $page->post_status : '—';
    echo "$key = $id (slug: $slug, status: $status)\n";
}

echo "\n=== Form Page ===\n";
$form_pages = get_posts(['post_type'=>'page','s'=>'[instant_form]','numberposts'=>5]);
global $wpdb;
$form_pages = $wpdb->get_results("SELECT ID, post_name, post_title, post_status FROM {$wpdb->posts} WHERE post_content LIKE '%instant_form%' AND post_type='page' LIMIT 5");
foreach ($form_pages as $p) {
    echo "ID:{$p->ID} slug:{$p->post_name} status:{$p->post_status}\n";
    echo "Permalink: " . get_permalink($p->ID) . "\n";
}

echo "\n=== Stripe Gateway ===\n";
$gateways = WC()->payment_gateways->payment_gateways();
if (isset($gateways['stripe'])) {
    $s = $gateways['stripe'];
    echo "Enabled: " . $s->enabled . "\n";
    echo "Testmode: " . $s->testmode . "\n";
    echo "Has test publishable key: " . (strlen($s->publishable_key) > 10 ? 'YES' : 'NO') . "\n";
    echo "Key starts with: " . substr($s->publishable_key, 0, 7) . "\n";
} else {
    echo "Stripe gateway not found!\n";
}

echo "\n=== Recent Orders ===\n";
$orders = wc_get_orders(['limit' => 3, 'orderby' => 'date', 'order' => 'DESC']);
foreach ($orders as $order) {
    echo "Order #{$order->get_id()} | Status:{$order->get_status()} | Method:{$order->get_payment_method()} | Created:" . $order->get_date_created() . "\n";
    echo "  Pay URL: " . $order->get_checkout_payment_url() . "\n";
}
echo "</pre>";
