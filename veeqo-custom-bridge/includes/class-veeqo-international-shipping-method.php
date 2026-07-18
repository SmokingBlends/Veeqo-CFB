<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_International_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'veeqo_international_rates';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'Veeqo International Rates';
        $this->method_description = 'Calculates dynamic international shipping rates via Veeqo.';
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title', 'Veeqo International Rates' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        
        add_filter( 'woocommerce_cart_no_shipping_available_html', array( $this, 'custom_intl_no_rates_message' ), 10, 1 );
        add_filter( 'woocommerce_no_shipping_available_html', array( $this, 'custom_intl_no_rates_message' ), 10, 1 );
    }

    public function init_form_fields() {
        $store_address = get_option( 'woocommerce_store_address', '' );
        $store_city    = get_option( 'woocommerce_store_city', '' );
        $store_zip     = get_option( 'woocommerce_store_postcode', '' );
        $admin_email   = get_option( 'admin_email', '' );

        $base_location = get_option( 'woocommerce_default_country', '' );
        $store_state   = '';
        if ( strpos( $base_location, ':' ) !== false ) {
            $parts = explode( ':', $base_location );
            $store_state = $parts[1];
        }

        $this->instance_form_fields = array(
            'title' => array( 'title' => 'Method Title', 'type' => 'text', 'default' => 'Veeqo International Rates' ),
            'dhl_title' => array( 'title' => 'DHL Display Name', 'type' => 'text', 'default' => 'DHL Express (2-4 Days)' ),
            'dhl_api_names' => array( 'title' => 'DHL Veeqo API Names', 'type' => 'textarea', 'default' => 'DHL Express International, DHL' ),
            'usps_title' => array( 'title' => 'USPS Display Name', 'type' => 'text', 'default' => 'USPS International (7-14 Days)' ),
            'usps_api_names' => array( 'title' => 'USPS Veeqo API Names', 'type' => 'textarea', 'default' => 'USPS First Class International, USPS Priority Mail International' ),
            'intl_custom_error' => array( 'title' => 'No Rates Error Message', 'type' => 'textarea', 'default' => 'Please enter a valid shipping address to view shipping options.' ),
            'sender_address' => array( 'title' => 'Sender Address', 'type' => 'text', 'default' => $store_address ),
            'sender_city' => array( 'title' => 'Sender City', 'type' => 'text', 'default' => $store_city ),
            'sender_state' => array( 'title' => 'Sender State', 'type' => 'text', 'default' => $store_state ),
            'sender_zip' => array( 'title' => 'Sender Zip', 'type' => 'text', 'default' => $store_zip ),
            'sender_phone' => array( 'title' => 'Sender Phone', 'type' => 'text', 'default' => '' ),
            'sender_email' => array( 'title' => 'Sender Email', 'type' => 'text', 'default' => $admin_email ),
        );
    }

    public function custom_intl_no_rates_message( $default_html ) {
        if ( WC()->customer && WC()->customer->get_shipping_country() !== 'US' ) {
            $custom_message = $this->get_option( 'intl_custom_error', 'Please enter a valid shipping address to view shipping options.' );
            return wp_kses_post( $custom_message );
        }
        return $default_html;
    }

    public function calculate_shipping( $package = array() ) {
        if ( empty($package['destination']['country']) || $package['destination']['country'] === 'US' ) {
            return;
        }

        $zip_code  = isset( $package['destination']['postcode'] ) ? sanitize_text_field( $package['destination']['postcode'] ) : '';
        $country   = $package['destination']['country'];
        $city      = isset( $package['destination']['city'] ) ? sanitize_text_field( $package['destination']['city'] ) : '';
        $state     = isset( $package['destination']['state'] ) ? sanitize_text_field( $package['destination']['state'] ) : '';
        $address_1 = isset( $package['destination']['address_1'] ) ? sanitize_text_field( $package['destination']['address_1'] ) : '';
        
        if ( empty( $zip_code ) ) return;

        $hash_str = '';
        foreach ( $package['contents'] as $item ) {
            $product_id = $item['product_id'];
            $variation_id = !empty($item['variation_id']) ? $item['variation_id'] : 0; 
            $line_total = isset($item['line_total']) ? $item['line_total'] : 0; 
            $hash_str .= $product_id . '_' . $variation_id . '_' . $item['quantity'] . '_' . $line_total . '|';
        }
        
        $cart_hash = md5( $hash_str . $zip_code . $state . $country . $city . $address_1 );
        
        $shipping_version = WC_Cache_Helper::get_transient_version( 'shipping' );
        $transient_name = 'wc_veeqo_intl_v4_' . $cart_hash . '_' . $shipping_version;
        
        $cached_rates = get_transient( $transient_name );
        if ( false !== $cached_rates && is_array( $cached_rates ) ) {
            foreach ( $cached_rates as $rate ) { $this->add_rate( $rate ); }
            return;
        }

        if ( class_exists( 'Veeqo_API' ) && method_exists('Veeqo_API', 'get_box_for_items') ) {
            $box_data = Veeqo_API::get_box_for_items( $package['contents'], true );
        } else {
            wc_get_logger()->error( 'Veeqo API class or method missing.', array( 'source' => 'veeqo_shipping' ) ); 
            return; 
        }

        $weight_oz = (float)($box_data['weight_oz'] ?? 0);
        $rates = $this->get_veeqo_live_rates( $zip_code, $country, $city, $state, $address_1, $weight_oz, $box_data, $package );

        if ( ! empty( $rates ) ) {
            set_transient( $transient_name, $rates, HOUR_IN_SECONDS ); 
            foreach ( $rates as $rate ) { $this->add_rate( $rate ); }
        }
    }

    private function build_channel_items( $contents ) {
        $items = [];
        foreach ( $contents as $item ) {
            $product = wc_get_product( $item['product_id'] );
            if ( ! $product ) continue;
            
            $hs_code = get_post_meta( $product->get_id(), '_veeqo_hs_code', true ) ?: '1211.90.92';
            $country_of_origin = get_post_meta( $product->get_id(), '_veeqo_country_of_origin', true ) ?: 'US';
            
            $item_value = isset($item['line_total']) && $item['quantity'] > 0 ? (float)($item['line_total'] / $item['quantity']) : (float)($product->get_price() ?: 10.00);
                    
            $items[] = [
                'quantity'               => (int)$item['quantity'],
                'value'                  => number_format( $item_value, 2, '.', '' ), 
                'currency_code'          => 'USD',
                'description'            => substr( $product->get_name(), 0, 40 ),
                'country_of_manufacture' => $country_of_origin,          
                'tariff_code'            => $hs_code   
            ];
        }
        return $items;
    }

    private function get_veeqo_live_rates( $zip_code, $country_code, $city, $state, $address_1, $weight_oz, $box, $package ) {
        $api_key = get_option( 'veeqo_api_key' );
        $rates_to_return = array();

        $raw_dhl_names = $this->get_option( 'dhl_api_names', 'DHL Express International, DHL' );
        $dhl_names = array_map( 'trim', explode( ',', $raw_dhl_names ) );

        $raw_usps_names = $this->get_option( 'usps_api_names', 'USPS First Class International, USPS Priority Mail International' );
        $usps_names = array_map( 'trim', explode( ',', $raw_usps_names ) );

        if ( ! empty( $api_key ) ) {
            $cart_value = 0;
            foreach ( $package['contents'] as $item ) {
                $cart_value += isset($item['line_total']) ? (float)$item['line_total'] : 0;
            }
            if ( $cart_value <= 0 ) { $cart_value = 50.00; }
            
            $length = (float)($box['l'] ?? 1);
            $width  = (float)($box['w'] ?? 1);
            $height = (float)($box['h'] ?? 1);

            $admin_email = get_option( 'admin_email', 'quote@shippingquote.com' );

            $payload = [
                'customer_reference' => 'CART-INTL-' . time(),
                'ship_date'          => gmdate('Y-m-d'), 
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
                'to_address'   => ['name'=>'Rate Quote', 'line1'=>$address_1, 'town'=>$city, 'county'=>$state, 'postcode'=>$zip_code, 'country_code'=>$country_code, 'phone'=>'18005551234', 'email'=>$admin_email],
                'parcels'      => [['weight'=>round($weight_oz/16,4), 'weight_unit'=>'lb', 'length'=>$length, 'width'=>$width, 'height'=>$height, 'dimension_unit'=>'in']],
                'estimated_value' => (float)$cart_value, 
                'currency_code'   => 'USD', 
                'contents'        => 'Merchandise',
                'contents_type'   => 'Merchandise',
                'channel_items'   => $this->build_channel_items( $package['contents'] ) 
            ];

            $response = wp_remote_post( 'https://api.veeqo.com/shipping/api/v1/rates', [
                'method'  => 'POST',
                'timeout' => 8, 
                'headers' => ['Content-Type'=>'application/json', 'Accept'=>'application/json', 'x-api-key'=>$api_key],
                'body'    => wp_json_encode( $payload )
            ]);

            if ( is_wp_error( $response ) ) {
                wc_get_logger()->error( 'Veeqo API Error: ' . $response->get_error_message(), array( 'source' => 'veeqo_shipping' ) ); 
                return $rates_to_return;
            }

            if ( wp_remote_retrieve_response_code( $response ) === 200 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['quotes'] ) ) {
                    $found_dhl = [];
                    $found_usps = [];
                    
                    foreach ( $body['quotes'] as $rate_option ) {
                        $service_name = $rate_option['service_name'] ?? 'Unknown';
                        $cost = (float)($rate_option['total_charge'] ?? 0);
                        
                        foreach( $dhl_names as $k ) {
                            if( !empty($k) && stripos($service_name, $k) !== false ) { 
                                $found_dhl[] = $cost; 
                                break; 
                            }
                        }
                        foreach( $usps_names as $k ) {
                            if( !empty($k) && stripos($service_name, $k) !== false ) { 
                                $found_usps[] = $cost; 
                                break; 
                            }
                        }
                    }
                    
                    $dhl_cost = !empty($found_dhl) ? min($found_dhl) : false;
                    $usps_cost = !empty($found_usps) ? min($found_usps) : false;
                    
                    if ( $dhl_cost !== false ) {
                        $rates_to_return[] = [ 
                            'id'       => $this->id . '_dhl', 
                            'label'    => $this->get_option( 'dhl_title', 'DHL Express (2-4 Days)' ), 
                            'cost'     => $dhl_cost, 
                            'calc_tax' => 'per_order' 
                        ];
                    }
                    
                    if ( $usps_cost !== false && ($dhl_cost === false || $usps_cost < $dhl_cost) ) {
                        $rates_to_return[] = [ 
                            'id'       => $this->id . '_usps', 
                            'label'    => $this->get_option( 'usps_title', 'USPS International (7-14 Days)' ), 
                            'cost'     => $usps_cost, 
                            'calc_tax' => 'per_order' 
                        ];
                    }
                } else {
                     wc_get_logger()->warning( 'Veeqo API returned empty quotes for payload: ' . wp_json_encode($payload), array( 'source' => 'veeqo_shipping' ) ); 
                }
            } else {
                wc_get_logger()->error( 'Veeqo API returned invalid response code: ' . wp_remote_retrieve_response_code( $response ), array( 'source' => 'veeqo_shipping' ) ); 
            }
        }
        
        return $rates_to_return;
    }
}