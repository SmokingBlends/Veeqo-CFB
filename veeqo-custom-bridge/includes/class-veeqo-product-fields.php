<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_Product_Fields {

    public function __construct() {
        // Hooks into the shipping tab on the product edit page
        add_action( 'woocommerce_product_options_shipping', array( $this, 'add_customs_fields' ) );
        
        // Saves the data when you hit update
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_customs_fields' ) );
    }

    public function add_customs_fields() {
        echo '<div class="options_group">';
        
        // SECURITY FIX: Create the nonce handshake field
        wp_nonce_field( 'veeqo_save_product_fields', 'veeqo_product_nonce' );
        
        // HS Tariff Code Field
        woocommerce_wp_text_input( array(
            'id'          => '_veeqo_hs_code',
            'label'       => 'HS Tariff Code',
            'description' => 'Used for Veeqo international customs (e.g., 1211.90.92).',
            'desc_tip'    => true,
            'placeholder' => '1211.90.92'
        ) );

        // Country of Origin Field
        woocommerce_wp_text_input( array(
            'id'          => '_veeqo_country_of_origin',
            'label'       => 'Country of Origin',
            'description' => 'Two-letter country code (e.g., US).',
            'desc_tip'    => true,
            'placeholder' => 'US'
        ) );

        echo '</div>';
    }

    public function save_customs_fields( $post_id ) {
        // SECURITY FIX: Verify the nonce handshake and sanitize the nonce input
        if ( ! isset( $_POST['veeqo_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['veeqo_product_nonce'] ) ), 'veeqo_save_product_fields' ) ) {
            return;
        }

        // SECURITY FIX: Unslash the inputs before sanitizing them
        $hs_code = isset( $_POST['_veeqo_hs_code'] ) ? sanitize_text_field( wp_unslash( $_POST['_veeqo_hs_code'] ) ) : '';
        $country = isset( $_POST['_veeqo_country_of_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['_veeqo_country_of_origin'] ) ) : '';

        // Save to the main parent product
        update_post_meta( $post_id, '_veeqo_hs_code', $hs_code );
        update_post_meta( $post_id, '_veeqo_country_of_origin', $country );

        // Automatically copy these values to all attached variations
        $product = wc_get_product( $post_id );
        if ( $product && $product->is_type( 'variable' ) ) {
            $variations = $product->get_children();
            foreach ( $variations as $variation_id ) {
                update_post_meta( $variation_id, '_veeqo_hs_code', $hs_code );
                update_post_meta( $variation_id, '_veeqo_country_of_origin', $country );
            }
        }
    }
}

new Veeqo_Product_Fields();