<?php
/**
 * Class: Veeqo_Address_Search
 * Description: Modern Headless Google Places integration for WooCommerce.
 * Feature: Silently fetches address data using the new Places API 
 * and enqueues external assets for maximum performance.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_Address_Search {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        if ( is_checkout() ) {
            $api_key = get_option( 'veeqo_google_autocomplete_key' );
            if ( empty( $api_key ) ) return;
            
            // 1. Load Google's backend library with version '1.0.0' added for the code sniffer
            wp_enqueue_script( 'veeqo-google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . esc_attr( $api_key ) . '&loading=async&libraries=places&callback=initVeeqoHeadlessSearch', array( 'jquery' ), '1.0.0', true );
            
            // 2. Load our custom CSS file
            wp_enqueue_style( 'veeqo-address-style', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/veeqo-address-search.css', array(), '1.0.0' );

            // 3. Load our custom JavaScript file
            wp_enqueue_script( 'veeqo-address-script', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/veeqo-address-search.js', array( 'jquery', 'veeqo-google-maps' ), '1.0.0', true );
        }
    }
}