<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_API {

    public function __construct() {
        add_action( 'woocommerce_order_status_processing', array( $this, 'push_order_to_veeqo' ), 10, 1 );
    }

    // THE ENGINE: Calculates the perfect box and total weight.
    public static function get_box_for_items( $items, $is_cart = true ) {
        $boxes = array(
            array( 'name' => '6x4x4',          'l' => 6,    'w' => 4,    'h' => 4,    'oz' => 1.1,  'vol' => 96 ),
            array( 'name' => '8x6x4',          'l' => 8,    'w' => 6,    'h' => 4,    'oz' => 2.8,  'vol' => 192 ),
            array( 'name' => '7x7x7',          'l' => 7,    'w' => 7,    'h' => 7,    'oz' => 4.3,  'vol' => 343 ),
            array( 'name' => '11x11x7.25',     'l' => 11,   'w' => 11,   'h' => 7.25, 'oz' => 11.3, 'vol' => 877.25 ),
            array( 'name' => '16.5x12.5x6.5',  'l' => 16.5, 'w' => 12.5, 'h' => 6.5,  'oz' => 13.4, 'vol' => 1340.63 ),
            array( 'name' => '15.5x11x11.5',   'l' => 15.5, 'w' => 11,   'h' => 11.5, 'oz' => 14.5, 'vol' => 1960.75 ),
            array( 'name' => '22x15x13.5',     'l' => 22,   'w' => 15,   'h' => 13.5, 'oz' => 24.7, 'vol' => 4455 ),
        );

        $total_volume = 0; 
        $total_weight_oz = 0; 
        $max_item_sides = array(0, 0, 0);

        foreach ( $items as $item ) {
            $product = $is_cart ? $item['data'] : (is_callable(array($item, 'get_product')) ? $item->get_product() : false);
            $qty     = $is_cart ? $item['quantity'] : $item->get_quantity();

            if ( ! $product ) continue;

            $w_oz = $product->get_weight() ? wc_get_weight( $product->get_weight(), 'oz' ) : 0;
            $total_weight_oz += ( $w_oz * $qty );
            
            $l = $product->get_length() ? wc_get_dimension( $product->get_length(), 'in' ) : 0;
            $w = $product->get_width() ? wc_get_dimension( $product->get_width(), 'in' ) : 0;
            $h = $product->get_height() ? wc_get_dimension( $product->get_height(), 'in' ) : 0;
            
            $total_volume += ( $l * $w * $h * $qty );
            
            $sides = array( $l, $w, $h ); 
            rsort( $sides );
            
            $max_item_sides[0] = max( $max_item_sides[0], $sides[0] );
            $max_item_sides[1] = max( $max_item_sides[1], $sides[1] );
            $max_item_sides[2] = max( $max_item_sides[2], $sides[2] );
        }

        $chosen_box = $boxes[6]; 
        foreach ( $boxes as $box ) {
            $box_sides = array( $box['l'], $box['w'], $box['h'] ); 
            rsort( $box_sides );
            
            if ( $total_volume <= $box['vol'] && 
                 $max_item_sides[0] <= $box_sides[0] && 
                 $max_item_sides[1] <= $box_sides[1] && 
                 $max_item_sides[2] <= $box_sides[2] ) {
                $chosen_box = $box; 
                break;
            }
        }

        $final_weight_oz = $total_weight_oz + $chosen_box['oz'];

        return array(
            'weight_oz' => $final_weight_oz, 
            'l'         => $chosen_box['l'],
            'w'         => $chosen_box['w'],
            'h'         => $chosen_box['h'],
            'name'      => $chosen_box['name']
        );
    }

    private function validate_address_with_google( $address_data, $google_api_key ) {
        $endpoint = 'https://addressvalidation.googleapis.com/v1:validateAddress?key=' . $google_api_key;
        
        $payload = array(
            'address' => array(
                'regionCode'         => $address_data['country'],
                'locality'           => $address_data['city'],
                'administrativeArea' => $address_data['state'],
                'postalCode'         => $address_data['zip'],
                'addressLines'       => array( $address_data['address1'], $address_data['address2'] )
            ),
            'enableUspsCass' => true 
        );
        
        $response = wp_remote_post( $endpoint, array(
            'method'  => 'POST',
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15
        ));
        
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return $address_data; 
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['result']['address'] ) ) {
            return $address_data; 
        }
        
        $valid_address = $body['result']['address']['postalAddress'];
        
        $clean_data = array(
            'address1' => isset($valid_address['addressLines'][0]) ? $valid_address['addressLines'][0] : $address_data['address1'],
            'address2' => isset($valid_address['addressLines'][1]) ? $valid_address['addressLines'][1] : '',
            'city'     => isset($valid_address['locality']) ? $valid_address['locality'] : $address_data['city'],
            'state'    => isset($valid_address['administrativeArea']) ? $valid_address['administrativeArea'] : $address_data['state'],
            'country'  => isset($valid_address['regionCode']) ? $valid_address['regionCode'] : $address_data['country'],
            'zip'      => isset($valid_address['postalCode']) ? $valid_address['postalCode'] : $address_data['zip']
        );

        if ( $clean_data['country'] === 'US' && ! empty( $body['result']['uspsData']['standardizedAddress'] ) ) {
            $usps = $body['result']['uspsData']['standardizedAddress'];
            if ( ! empty( $usps['zipCode'] ) ) {
                $clean_data['zip'] = $usps['zipCode'];
                if ( ! empty( $usps['zipCodeExtension'] ) ) {
                    $clean_data['zip'] .= '-' . $usps['zipCodeExtension']; 
                }
            }
        }
        
        return $clean_data;
    }

    private function get_or_create_veeqo_sellable( $sku, $title, $price, $weight_grams, $length_cm, $width_cm, $height_cm, $veeqo_api_key, $hs_code, $country_of_origin ) {
        $search_url = "https://api.veeqo.com/products?query=" . urlencode( $sku );
        $search_response = wp_remote_get( $search_url, array(
            'headers' => array( 'x-api-key' => $veeqo_api_key, 'Accept' => 'application/json' ),
            'timeout' => 15
        ) );

        if ( ! is_wp_error( $search_response ) && wp_remote_retrieve_response_code( $search_response ) == 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $search_response ), true );
            if ( ! empty( $body ) && is_array( $body ) ) {
                foreach ( $body as $product ) {
                    if ( isset( $product['sellables'] ) && is_array( $product['sellables'] ) ) {
                        foreach ( $product['sellables'] as $sellable ) {
                            if ( $sellable['sku_code'] === $sku ) {
                                
                                $update_payload = array(
                                    'product' => array(
                                        'product_variants_attributes' => array(
                                            array(
                                                'id'               => $sellable['id'],
                                                'weight_grams'     => $weight_grams,
                                                'hs_tariff_number' => $hs_code, 
                                                'origin_country'   => $country_of_origin, 
                                                'measurement_attributes' => array(
                                                    'width'  => $width_cm,
                                                    'height' => $height_cm,
                                                    'depth'  => $length_cm, 
                                                    'dimensions_unit' => 'cm'
                                                )
                                            )
                                        )
                                    )
                                );

                                wp_remote_request( 'https://api.veeqo.com/products/' . $product['id'], array(
                                    'method'  => 'PUT',
                                    'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-api-key' => $veeqo_api_key ),
                                    'body'    => wp_json_encode( $update_payload ),
                                    'timeout' => 10
                                ) );

                                return $sellable['id']; 
                            }
                        }
                    }
                }
            }
        }

        $product_payload = array(
            'product' => array(
                'title' => $title,
                'product_variants_attributes' => array(
                    array(
                        'sku_code'         => $sku,
                        'price'            => $price,
                        'weight_grams'     => $weight_grams,
                        'title'            => 'Default',
                        'hs_tariff_number' => $hs_code, 
                        'origin_country'   => $country_of_origin, 
                        'measurement_attributes' => array(
                            'width'  => $width_cm,
                            'height' => $height_cm,
                            'depth'  => $length_cm, 
                            'dimensions_unit' => 'cm'
                        )
                    )
                )
            )
        );

        $create_response = wp_remote_post( 'https://api.veeqo.com/products', array(
            'method'  => 'POST',
            'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-api-key' => $veeqo_api_key ),
            'body'    => wp_json_encode( $product_payload ),
            'timeout' => 20
        ) );

        if ( ! is_wp_error( $create_response ) ) {
            $create_code = wp_remote_retrieve_response_code( $create_response );
            if ( $create_code < 400 ) {
                $created_body = json_decode( wp_remote_retrieve_body( $create_response ), true );
                if ( isset( $created_body['sellables'][0]['id'] ) ) {
                    return $created_body['sellables'][0]['id']; 
                }
            }
        }

        return false;
    }

    public function push_order_to_veeqo( $order_id ) {
        $veeqo_api_key    = trim( get_option( 'veeqo_api_key' ) );
        $veeqo_channel_id = get_option( 'veeqo_channel_id' );
        $google_api_key   = trim( get_option( 'veeqo_google_api_key' ) ); 
        
        if ( empty( $veeqo_api_key ) || empty( $veeqo_channel_id ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $box_data = self::get_box_for_items( $order->get_items(), false );
        $weight_grams = round( (float)($box_data['weight_oz'] ?? 0) * 28.3495, 2 );

        $line_items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product || ! $product->get_sku() ) continue;

            $sku   = $product->get_sku();
            $title = $product->get_name();
            $price = $order->get_item_subtotal( $item, false, false );
            
            $weight = $product->get_weight();
            $prod_weight_grams = $weight ? wc_get_weight( floatval( $weight ), 'g' ) : 0;

            $length = $product->get_length();
            $width  = $product->get_width();
            $height = $product->get_height();

            $length_cm = $length ? wc_get_dimension( floatval( $length ), 'cm' ) : 0;
            $width_cm  = $width ? wc_get_dimension( floatval( $width ), 'cm' ) : 0;
            $height_cm = $height ? wc_get_dimension( floatval( $height ), 'cm' ) : 0;

            $default_hs = get_option( 'veeqo_default_hs_code', '1211.90.92' );
            $default_country = get_option( 'veeqo_default_country', 'US' );

            $hs_code = get_post_meta( $product->get_id(), '_veeqo_hs_code', true ) ?: $default_hs;
            $country_of_origin = get_post_meta( $product->get_id(), '_veeqo_country_of_origin', true ) ?: $default_country;

            $sellable_id = get_post_meta( $product->get_id(), '_veeqo_sellable_id', true );
            
            if ( empty( $sellable_id ) ) {
                $sellable_id = $this->get_or_create_veeqo_sellable( $sku, $title, $price, $prod_weight_grams, $length_cm, $width_cm, $height_cm, $veeqo_api_key, $hs_code, $country_of_origin );
                
                if ( $sellable_id ) {
                    update_post_meta( $product->get_id(), '_veeqo_sellable_id', $sellable_id );
                }
            }

            if ( ! $sellable_id ) {
                wc_get_logger()->error( 'Veeqo API Error: Could not find or create sellable ID for SKU: ' . $sku . ' on Order: ' . $order_id, array( 'source' => 'veeqo-bridge' ) );
                continue;
            }

            $line_items[] = array(
                'sellable_id'    => $sellable_id, 
                'quantity'       => $item->get_quantity(),
                'price_per_unit' => $price,
            );
        }

        if ( empty( $line_items ) ) return;

        $shipping_method_name = 'Standard Shipping';
        $shipping_items = $order->get_items( 'shipping' );
        if ( ! empty( $shipping_items ) ) {
            foreach ( $shipping_items as $item_id => $item ) {
                $shipping_method_name = $item->get_name();
                break; 
            }
        }
        $shipping_cost = $order->get_shipping_total();

        $raw_shipping_address = array(
            'address1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'address2' => (string) ($order->get_shipping_address_2() ?: $order->get_billing_address_2()),
            'city'     => $order->get_shipping_city() ?: $order->get_billing_city(),
            'state'    => $order->get_shipping_state() ?: $order->get_billing_state(),
            'zip'      => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'country'  => $order->get_shipping_country() ?: $order->get_billing_country()
        );

        $clean_shipping_address = $raw_shipping_address;
        if ( ! empty( $google_api_key ) ) {
            $clean_shipping_address = $this->validate_address_with_google( $raw_shipping_address, $google_api_key );
        }

        $default_email = get_option( 'veeqo_default_email', 'no-reply@store.com' );
        $default_phone = get_option( 'veeqo_default_phone', '0000000000' );

        // --- STEP 1: THE JAB (Create the order) ---
        $payload = array(
            'order' => array(
                'channel_id'      => absint( $veeqo_channel_id ),
                'number'          => $order->get_order_number(),
                'delivery_cost'   => floatval( $shipping_cost ), 
                'weight_grams'    => $weight_grams,
                'customer_note'   => "System Quoted: " . ($box_data['name'] ?? 'Unknown') . " Box",
                'delivery_method' => array(
                    'name' => $shipping_method_name,
                    'cost' => floatval( $shipping_cost )
                ),
                'customer_attributes' => array(
                    'email'      => $order->get_billing_email() ?: $default_email,
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'phone'      => $order->get_billing_phone() ?: $default_phone,
                    'billing_address_attributes' => array( 
                        'first_name' => $order->get_billing_first_name(),
                        'last_name'  => $order->get_billing_last_name(),
                        'address1'   => $order->get_billing_address_1(),
                        'address2'   => (string) $order->get_billing_address_2(), 
                        'city'       => $order->get_billing_city(),
                        'country'    => $order->get_billing_country(),
                        'state'      => $order->get_billing_state(),
                        'zip'        => $order->get_billing_postcode(),
                    )
                ),
                'deliver_to_attributes' => array(
                    'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
                    'last_name'  => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
                    'address1'   => $clean_shipping_address['address1'],
                    'address2'   => $clean_shipping_address['address2'], 
                    'city'       => $clean_shipping_address['city'],
                    'country'    => $clean_shipping_address['country'],
                    'state'      => $clean_shipping_address['state'],
                    'zip'        => $clean_shipping_address['zip'],
                ),
                'line_items_attributes' => $line_items,
                'payment_attributes' => array(
                    'payment_type' => 'credit_card'
                )
            )
        );

        $response = wp_remote_post( 'https://api.veeqo.com/orders', array(
            'method'  => 'POST',
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'x-api-key'    => $veeqo_api_key
            ),
            'body' => wp_json_encode( $payload )
        ) );

        if ( is_wp_error( $response ) ) {
            $logger = wc_get_logger();
            $logger->error( 'Veeqo Bridge WP HTTP Error: ' . $response->get_error_message(), array( 'source' => 'veeqo-bridge' ) );
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code >= 400 ) {
            $logger = wc_get_logger();
            $logger->error( "Veeqo API Error ({$response_code}): " . wp_remote_retrieve_body( $response ), array( 'source' => 'veeqo-bridge' ) );
            return;
        }

        // --- STEP 2: THE RIGHT HOOK (Force the allocation package size and weight) ---
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( ! empty( $response_body['allocations'] ) && is_array( $response_body['allocations'] ) ) {
            $allocation_id = $response_body['allocations'][0]['id'];

            $allocation_payload = array(
                'allocation_package' => array(
                    'weight'                     => floatval( $box_data['weight_oz'] ?? 0 ),
                    'weight_unit'                => 'oz',
                    'width'                      => floatval( $box_data['w'] ?? 1 ),
                    'height'                     => floatval( $box_data['h'] ?? 1 ),
                    'depth'                      => floatval( $box_data['l'] ?? 1 ), 
                    'dimensions_unit'            => 'in',
                    'package_provider'           => 'CUSTOM',
                    'package_selection_source'   => 'ONE_OFF',
                    'save_for_similar_shipments' => false
                )
            );

            $put_response = wp_remote_request( 'https://api.veeqo.com/allocations/' . $allocation_id . '/allocation_package', array(
                'method'  => 'PUT',
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'x-api-key'    => $veeqo_api_key
                ),
                'body' => wp_json_encode( $allocation_payload )
            ) );

            if ( is_wp_error( $put_response ) || wp_remote_retrieve_response_code( $put_response ) >= 400 ) {
                $logger = wc_get_logger();
                $logger->error( 'Veeqo Allocation Update Error: Failed to push correct box dimensions.', array( 'source' => 'veeqo-bridge' ) );
            }
        }
    }
}
// Paste the clean speed-up code right here:
add_filter( 'woocommerce_cart_ready_to_calc_shipping', 'veeqo_speed_up_cart_flow', 99 );

function veeqo_speed_up_cart_flow( $ready ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( 'POST' === $request_method && isset( $_POST['add-to-cart'] ) ) {
        return false;
    }

    if ( function_exists( 'is_cart' ) && is_cart() ) {
        return false;
    }

    return $ready;
}