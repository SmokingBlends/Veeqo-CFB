<?php
/**
 * Plugin Name: Veeqo Custom Fulfillment Bridge
 * Plugin URI:  https://github.com/veeqo-bridge
 * Description: Pushes WooCommerce orders to Veeqo on-the-fly and provides live shipping rates.
 * Version:     1.3.0
 * Author:      Your AI Co-Pilot
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Stable tag:  1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Prefixed constant perfectly matching the plugin slug
define( 'VEEQO_CUSTOM_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );

require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-admin.php';
require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-api.php';
require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-product-fields.php'; 
require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-tracking-sync.php'; 
require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-address-search.php'; // NEW: Load the address search

// NEW: Load the shipping method only after WooCommerce is ready
add_action( 'woocommerce_shipping_init', 'veeqo_custom_bridge_shipping_init' );
function veeqo_custom_bridge_shipping_init() {
    require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-shipping-method.php';
    require_once VEEQO_CUSTOM_BRIDGE_DIR . 'includes/class-veeqo-international-shipping-method.php'; 
}

// NEW: Tell WooCommerce this new shipping method exists
add_filter( 'woocommerce_shipping_methods', 'veeqo_custom_bridge_add_shipping_method' );
function veeqo_custom_bridge_add_shipping_method( $methods ) {
    $methods['veeqo_live_rates'] = 'Veeqo_Shipping_Method';
    $methods['veeqo_international_rates'] = 'Veeqo_International_Shipping_Method'; 
    return $methods;
}

// Function name strictly prefixed to satisfy WP naming conventions
function veeqo_custom_bridge_run() {
    new Veeqo_Admin();
    new Veeqo_API();
    new Veeqo_Tracking_Sync(); 
    new Veeqo_Address_Search(); // NEW: Initialize the address search
}
add_action( 'plugins_loaded', 'veeqo_custom_bridge_run' );