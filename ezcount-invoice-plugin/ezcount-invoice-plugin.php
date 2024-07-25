<?php
/*
Plugin Name: EZCount Invoicing
Description: תוסף ליצירת חשבוניות באמצעות ממשק EZCount עבור הזמנות WooCommerce. פותח על ידי Dooble.
Version: 1.1
Author: Dooble
Author URI: https://www.dooble.co.il/
Requires at least: 5.0
Tested up to: 6.0
Requires PHP: 7.0
*/

defined('ABSPATH') or die('No script kiddies please!');

// Start session if not already started
add_action('init', 'ezcount_plugin_start_session', 1);
function ezcount_plugin_start_session() {
    if (!session_id()) {
        session_start();
    }
}

// Add admin menu
add_action('admin_menu', 'ezcount_plugin_menu');

function ezcount_plugin_menu() {
    add_menu_page(
        'EZCount Invoice Plugin Settings', // Page title
        'EZCount Settings', // Menu title
        'manage_options', // Capability
        'ezcount-invoice-settings', // Menu slug
        'ezcount_plugin_settings_page' // Callback function
    );
}

// Register settings
add_action('admin_init', 'ezcount_plugin_settings');

function ezcount_plugin_settings() {
    // Register settings
    register_setting('ezcount-plugin-settings-group', 'ezcount_api_key');
    register_setting('ezcount-plugin-settings-group', 'ezcount_developer_email');
    register_setting('ezcount-plugin-settings-group', 'ezcount_env_url');

    // Add settings section
    add_settings_section(
        'ezcount-plugin-settings-section',
        'API Settings',
        null,
        'ezcount-invoice-settings'
    );

    // Add settings fields
    add_settings_field(
        'ezcount_api_key',
        'API Key',
        'ezcount_api_key_field_callback',
        'ezcount-invoice-settings',
        'ezcount-plugin-settings-section'
    );
    
    add_settings_field(
        'ezcount_developer_email',
        'Developer Email',
        'ezcount_developer_email_field_callback',
        'ezcount-invoice-settings',
        'ezcount-plugin-settings-section'
    );
    
    add_settings_field(
        'ezcount_env_url',
        'Environment URL()',
        'ezcount_env_url_field_callback',
        'ezcount-invoice-settings',
        'ezcount-plugin-settings-section'
    );
}

function ezcount_api_key_field_callback() {
    $api_key = get_option('ezcount_api_key');
    echo '<input style="width:80%;" type="text" name="ezcount_api_key" value="' . esc_attr($api_key) . '" />';
}

function ezcount_developer_email_field_callback() {
    $email = get_option('ezcount_developer_email');
    echo '<input style="width:80%;" type="text" name="ezcount_developer_email" value="' . esc_attr($email) . '" />';
}

function ezcount_env_url_field_callback() {
    $env_url = get_option('ezcount_env_url');
    echo '<input style="width:80%;" type="text" name="ezcount_env_url" value="' . esc_attr($env_url) . '" /> <br> (DEV: https://demo.ezcount.co.il/ PROD: https://api.ezcount.co.il/)';
}

// Render settings page
function ezcount_plugin_settings_page() {
    ?>
    <div class="wrap">
        <h1>EZCount Invoice Plugin Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ezcount-plugin-settings-group');
            do_settings_sections('ezcount-invoice-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Function to send JSON request
function send_json_request($url, $data = []) {
    $options = array(
        'http' => array(
            'method' => 'POST',
            'content' => json_encode($data),
            'header' => "Content-Type: application/json\r\n" .
                "Accept: application/json\r\n"
        )
    );
    $context = stream_context_create($options);
    $json_str = file_get_contents($url, false, $context);
    $json_obj = json_decode($json_str);
    return $json_obj;
}

// Create invoice from WooCommerce order
function ezcount_create_invoice_from_order($order_id) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('woocommerce_not_active', 'WooCommerce is not active.');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('order_not_found', 'Order not found.');
    }

    // Extract order details
    $items = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $items[] = [
            'catalog_number' => $product ? $product->get_sku() : 'N/A',
            'details' => $item->get_name(),
            'amount' => $item->get_quantity(),
            'price' => $item->get_total(),
            'vat_type' => 'INC'
        ];
    }

    $payment_methods = [
        [
            'payment_type' => 3, // Example: credit card payment
            'payment' => $order->get_total(),
            'cc_number' => 'N/A', // Placeholder; adjust as needed
            'cc_type_name' => 'N/A', // Placeholder; adjust as needed
            'cc_deal_type' => 1 // Normal transaction
        ]
    ];

    // Create invoice
    $transaction_id = $order_id; // Use order ID as transaction ID
    $url = get_option('ezcount_env_url') . '/api/createDoc';
    $data = [
        'api_key' => get_option('ezcount_api_key'),
        'developer_email' => get_option('ezcount_developer_email'),
        'type' => 320, // Example: invoice reception
        'description' => 'Invoice for WooCommerce order #' . $order_id,
        'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'customer_email' => $order->get_billing_email(),
        'customer_address' => $order->get_billing_address_1() . ', ' . $order->get_billing_city() . ', ' . $order->get_billing_postcode(),
        'item' => $items,
        'payment' => $payment_methods,
        'price_total' => $order->get_total(),
        'comment' => 'Thank you for your business',
        'transaction_id' => $transaction_id
    ];

    $response = send_json_request($url, $data);

    return $response;
}

// Hook into WooCommerce order completion
add_action('woocommerce_order_status_completed', 'ezcount_on_order_complete', 10, 1);
function ezcount_on_order_complete($order_id) {

    $response = ezcount_create_invoice_from_order($order_id);

    if (is_wp_error($response)) {
        error_log('EZCount invoice creation failed: ' . $response->get_error_message());
    } else {
        error_log('EZCount invoice created successfully: ' . print_r($response, true));
    }
}

// Create a shortcode to manually trigger invoice creation
add_shortcode('create_invoice_manually', 'create_invoice_manually_shortcode');
function create_invoice_manually_shortcode($atts) {
    $atts = shortcode_atts(['order_id' => ''], $atts, 'create_invoice_manually');
    $order_id = intval($atts['order_id']);
    ob_start();
    $response = ezcount_create_invoice_from_order($order_id);
    ?>
    <pre><?php print_r($response); ?></pre>
    <?php
    return ob_get_clean();
}