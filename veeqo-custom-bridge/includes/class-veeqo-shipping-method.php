<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'veeqo_live_rates';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'Veeqo Live Rates';
        $this->method_description = 'Calculates dynamic shipping rates via Veeqo.';
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title', 'Veeqo Live Rates' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        // Pull the real store details directly from WooCommerce
        $store_address = get_option( 'woocommerce_store_address', '' );
        $store_city    = get_option( 'woocommerce_store_city', '' );
        $store_zip     = get_option( 'woocommerce_store_postcode', '' );
        $admin_email   = get_option( 'admin_email', '' );

        // Woo stores country and state together (e.g., US:FL), so we split it to get the state
        $base_location = get_option( 'woocommerce_default_country', '' );
        $store_state   = '';
        if ( strpos( $base_location, ':' ) !== false ) {
            $parts = explode( ':', $base_location );
            $store_state = $parts[1];
        }

        $this->instance_form_fields = array(
            'title' => array( 'title' => 'Method Title', 'type' => 'text', 'default' => 'Veeqo Live Rates' ),
            
            // New settings for the Free option
            'free_title' => array( 'title' => 'Free Display Name', 'type' => 'text', 'default' => 'Free Shipping' ),
            'free_threshold' => array( 'title' => 'Free Shipping Threshold ($)', 'type' => 'number', 'custom_attributes' => array( 'step' => '0.01' ), 'default' => '35.00' ),
            
            'ground_title' => array( 'title' => 'Ground Display Name', 'type' => 'text', 'default' => 'USPS Ground Advantage' ),
            'ground_api_names' => array( 'title' => 'Ground Veeqo API Names', 'type' => 'textarea', 'default' => 'USPS Ground Advantage, Ground Advantage' ),
            'ground_fallback' => array( 'title' => 'Ground Fallback Price ($)', 'type' => 'number', 'custom_attributes' => array( 'step' => '0.01' ), 'default' => '5.45' ),
            'priority_title' => array( 'title' => 'Priority Display Name', 'type' => 'text', 'default' => 'USPS Priority Mail' ),
            'priority_api_names' => array( 'title' => 'Priority Veeqo API Names', 'type' => 'textarea', 'default' => 'USPS Priority Mail, Priority Mail Cubic' ),
            'priority_fallback' => array( 'title' => 'Priority Fallback Price ($)', 'type' => 'number', 'custom_attributes' => array( 'step' => '0.01' ), 'default' => '10.95' ),
            
            'sender_address' => array( 'title' => 'Sender Address', 'type' => 'text', 'default' => $store_address ),
            'sender_city' => array( 'title' => 'Sender City', 'type' => 'text', 'default' => $store_city ),
            'sender_state' => array( 'title' => 'Sender State', 'type' => 'text', 'default' => $store_state ),
            'sender_zip' => array( 'title' => 'Sender Zip', 'type' => 'text', 'default' => $store_zip ),
            'sender_phone' => array( 'title' => 'Sender Phone', 'type' => 'text', 'default' => '' ),
            'sender_email' => array( 'title' => 'Sender Email', 'type' => 'text', 'default' => $admin_email ),
        );
    }

    public function calculate_shipping( $package = array() ) {
        $zip_code = isset( $package['destination']['postcode'] ) ? preg_replace('/[^0-9]/', '', $package['destination']['postcode']) : '';
        $country   = isset( $package['destination']['country'] ) ? $package['destination']['country'] : 'US';
        $city      = isset( $package['destination']['city'] ) ? sanitize_text_field( $package['destination']['city'] ) : '';
        $state     = isset( $package['destination']['state'] ) ? sanitize_text_field( $package['destination']['state'] ) : '';
        $address_1 = isset( $package['destination']['address_1'] ) ? sanitize_text_field( $package['destination']['address_1'] ) : '';

        if ( empty( $zip_code ) || strlen( $zip_code ) < 5 || $country !== 'US' ) return;

        $hash_str = '';
        foreach ( $package['contents'] as $item ) {
            $product_id = $item['product_id'];
            $variation_id = !empty($item['variation_id']) ? $item['variation_id'] : 0; 
            $line_total = isset($item['line_total']) ? $item['line_total'] : 0; 
            $hash_str .= $product_id . '_' . $variation_id . '_' . $item['quantity'] . '_' . $line_total . '|';
        }
        
        $cart_hash = md5( $hash_str . $zip_code . $state . $city . $address_1 );
        
        $shipping_version = WC_Cache_Helper::get_transient_version( 'shipping' );
        $transient_name = 'veeqo_rates_v2_' . $cart_hash . '_' . $shipping_version;
        
        $cached_rates = get_transient( $transient_name );
        if ( false !== $cached_rates && is_array( $cached_rates ) ) {
            foreach ( $cached_rates as $rate ) { $this->add_rate( $rate ); }
            return;
        }

        if ( class_exists( 'Veeqo_API' ) && method_exists('Veeqo_API', 'get_box_for_items') ) {
            $box_data = Veeqo_API::get_box_for_items( $package['contents'], true );
        } else {
            wc_get_logger()->error( 'Veeqo API class or method missing.', array( 'source' => 'veeqo_domestic_shipping' ) );
            return; 
        }

        $weight_oz = (float)($box_data['weight_oz'] ?? 0);
        $rates = $this->get_veeqo_live_rates( $zip_code, $city, $state, $address_1, $weight_oz, $box_data, $package );

        if ( ! empty( $rates ) ) {
            set_transient( $transient_name, $rates, HOUR_IN_SECONDS );
            foreach ( $rates as $rate ) { $this->add_rate( $rate ); }
        }
    }

    private function get_veeqo_live_rates( $zip_code, $city, $state, $address_1, $weight_oz, $box, $package ) {
        $api_key = get_option( 'veeqo_api_key' );
        $rates_to_return = array();

        $ground_price = (float)$this->get_option( 'ground_fallback', '5.45' );
        $priority_price = (float)$this->get_option( 'priority_fallback', '10.95' );
        
        // Grab the new custom settings
        $free_threshold = (float)$this->get_option( 'free_threshold', '35.00' );
        $free_title     = $this->get_option( 'free_title', 'Free Shipping' );

        $raw_ground_names = $this->get_option( 'ground_api_names', 'USPS Ground Advantage, Ground Advantage' );
        $raw_priority_names = $this->get_option( 'priority_api_names', 'USPS Priority Mail, Priority Mail Cubic' );
        $ground_names = array_map( 'trim', explode( ',', $raw_ground_names ) );
        $priority_names = array_map( 'trim', explode( ',', $raw_priority_names ) );

        if ( ! empty( $api_key ) ) {
            $cart_value = ( WC()->cart ) ? (float)WC()->cart->get_cart_contents_total() : $free_threshold;
            
            $length = (float)($box['l'] ?? 1);
            $width  = (float)($box['w'] ?? 1);
            $height = (float)($box['h'] ?? 1);

            $admin_email = get_option( 'admin_email', 'quote@shippingquote.com' );

            $payload = [
                'customer_reference' => 'CART-DOM-' . time(),
                'from_address' => [
                    'name'         => get_bloginfo('name'), 
                    'line1'        => $this->get_option('sender_address', ''), 
                    'town'         => $this->get_option('sender_city', ''), 
                    'county'       => $this->get_option('sender_state', ''), 
                    'postcode'     => $this->get_option('sender_zip', ''), 
                    'country_code' => 'US', 
                    'phone'        => $this->get_option('sender_phone', ''), 
                    'email'        => $this->get_option('sender_email', $admin_email)
                ],
                'to_address'   => [
                    'name'         => 'Rate Quote', 
                    'line1'        => !empty($address_1) ? $address_1 : 'Address Unknown', 
                    'town'         => !empty($city) ? $city : 'City Unknown', 
                    'county'       => !empty($state) ? $state : 'FL', 
                    'postcode'     => $zip_code, 
                    'country_code' => 'US', 
                    'phone'        => '18005551234', 
                    'email'        => $admin_email
                ],
                'parcels'      => [['weight'=>round($weight_oz/16,4), 'weight_unit'=>'lb', 'length'=>$length, 'width'=>$width, 'height'=>$height, 'dimension_unit'=>'in']],
                'estimated_value' => (float)$cart_value, 
                'currency_code'   => 'USD', 
                'contents'        => 'Merchandise'
            ];

            $response = wp_remote_post( 'https://api.veeqo.com/shipping/api/v1/rates', [
                'method'  => 'POST',
                'timeout' => 8,
                'headers' => ['Content-Type'=>'application/json', 'Accept'=>'application/json', 'x-api-key'=>$api_key],
                'body'    => wp_json_encode( $payload )
            ]);

            if ( is_wp_error( $response ) ) {
                wc_get_logger()->error( 'Veeqo Domestic API Error: ' . $response->get_error_message(), array( 'source' => 'veeqo_domestic_shipping' ) );
            } elseif ( wp_remote_retrieve_response_code( $response ) == 200 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['quotes'] ) ) {
                    $found_ground = []; 
                    $found_priority = [];
                    
                    foreach ( $body['quotes'] as $rate_option ) {
                        $service_name = $rate_option['service_name'] ?? 'Unknown';
                        $cost = (float)($rate_option['total_charge'] ?? 0);
                        
                        foreach( $ground_names as $k ) {
                            if( !empty($k) && stripos($service_name, $k) !== false ) { 
                                $found_ground[] = $cost; 
                                break; 
                            }
                        }
                        foreach( $priority_names as $k ) {
                            if( !empty($k) && stripos($service_name, $k) !== false ) { 
                                $found_priority[] = $cost; 
                                break; 
                            }
                        }
                    }
                    if ( ! empty( $found_ground ) ) $ground_price = min( $found_ground );
                    if ( ! empty( $found_priority ) ) $priority_price = min( $found_priority );
                } else {
                    wc_get_logger()->warning( 'Veeqo Domestic API returned empty quotes for payload: ' . wp_json_encode($payload), array( 'source' => 'veeqo_domestic_shipping' ) );
                }
            } else {
                wc_get_logger()->error( 'Veeqo Domestic API returned invalid response code: ' . wp_remote_retrieve_response_code( $response ), array( 'source' => 'veeqo_domestic_shipping' ) );
            }
        }

        // --- THE BODY SHOT: DYNAMIC FREE GROUND SHIPPING INTERCEPT ---
        $cart_subtotal = ( WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0;
        $ground_label  = $this->get_option( 'ground_title', 'USPS Ground Advantage' );

        if ( $cart_subtotal >= $free_threshold ) {
            $rates_to_return[] = [ 'id' => $this->id . '_ground_free', 'label' => $free_title, 'cost' => 0, 'calc_tax' => 'per_order' ];
        } else {
            $rates_to_return[] = [ 'id' => $this->id . '_ground_paid', 'label' => $ground_label, 'cost' => $ground_price, 'calc_tax' => 'per_order' ];
        }
        
        $rates_to_return[] = [ 'id' => $this->id . '_priority', 'label' => $this->get_option( 'priority_title', 'USPS Priority Mail' ), 'cost' => $priority_price, 'calc_tax' => 'per_order' ];
        
        return $rates_to_return;
    }
}