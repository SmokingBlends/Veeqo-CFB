<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_Tracking_Sync {

    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'veeqo_cron_schedule' ) );
        add_action( 'veeqo_sync_tracking_action', array( $this, 'pull_tracking_from_veeqo' ) );
        
        // 1. Email: Placed cleanly under the order summary table
        add_action( 'woocommerce_email_after_order_table', array( $this, 'inject_tracking_email' ), 10, 4 );
        
        // 2. Frontend Customer View: Placed in the customer address section
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'display_tracking_frontend' ), 10, 1 );
        
        // 3. Admin View: Placed in the backend order screen
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_tracking_in_admin' ), 10, 1 );
        
        if ( ! wp_next_scheduled( 'veeqo_sync_tracking_action' ) ) {
            wp_schedule_event( time(), 'veeqo_fifteen', 'veeqo_sync_tracking_action' );
        }
    }

    public function veeqo_cron_schedule( $schedules ) {
        $schedules['veeqo_fifteen'] = array(
            'interval' => 900, 
            'display'  => 'Every 15 Minutes'
        );
        return $schedules;
    }

    public function pull_tracking_from_veeqo() {
        if ( get_transient( 'veeqo_tracking_sync_running' ) ) {
            return;
        }
        set_transient( 'veeqo_tracking_sync_running', true, 10 * MINUTE_IN_SECONDS );

        try {
            $veeqo_api_key    = trim( get_option( 'veeqo_api_key' ) );
            $veeqo_channel_id = get_option( 'veeqo_channel_id' );
            $logger           = wc_get_logger();
            
            if ( empty( $veeqo_api_key ) || empty( $veeqo_channel_id ) ) {
                return;
            }

            $last_check = gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) );
            $url = 'https://api.veeqo.com/orders?status=shipped&channel_id=' . absint( $veeqo_channel_id ) . '&updated_at_min=' . urlencode( $last_check );

            $response = wp_remote_get( $url, array(
                'headers' => array( 'Accept' => 'application/json', 'x-api-key' => $veeqo_api_key ),
                'timeout' => 15
            ));

            if ( is_wp_error( $response ) ) {
                $logger->error( 'Veeqo Sync API Error: ' . $response->get_error_message(), array( 'source' => 'veeqo-tracking-sync' ) );
                return;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            if ( $response_code >= 400 ) {
                $logger->error( "Veeqo Sync Server Error ({$response_code}): " . wp_remote_retrieve_body( $response ), array( 'source' => 'veeqo-tracking-sync' ) );
                return;
            }

            $orders = json_decode( wp_remote_retrieve_body( $response ), true );
            
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $logger->error( 'Veeqo Sync JSON Parsing Error: ' . json_last_error_msg(), array( 'source' => 'veeqo-tracking-sync' ) );
                return;
            }

            if ( empty( $orders ) || ! is_array( $orders ) ) {
                return;
            }

            foreach ( $orders as $v_order ) {
                $v_number = isset( $v_order['number'] ) ? $v_order['number'] : '';
                
                if ( function_exists( 'wc_get_order_id_by_order_number' ) ) {
                    $wc_order_id = wc_get_order_id_by_order_number( $v_number );
                } else {
                    $wc_order_id = absint( $v_number );
                }
                
                $order = wc_get_order( $wc_order_id );
                
                if ( ! $order ) {
                    $logger->warning( 'Veeqo Sync Error: Could not find matching WooCommerce order for Veeqo number ' . $v_number, array( 'source' => 'veeqo-tracking-sync' ) );
                    continue;
                }

                // === IMPROVED: Find the LATEST allocation (by updated_at) that currently has an active shipment with tracking.
                // This fixes the bug where a canceled/voided label's old tracking number gets locked in,
                // and ensures that when you delete a label and purchase a new one, the NEW tracking is picked up
                // even on already-completed orders (we update the meta + add a note).
                $latest_shipment   = null;
                $latest_alloc_time = '0000-00-00T00:00:00Z';

                if ( ! empty( $v_order['allocations'] ) && is_array( $v_order['allocations'] ) ) {
                    foreach ( $v_order['allocations'] as $allocation ) {
                        if ( ! empty( $allocation['shipment'] ) ) {
                            $shipment = $allocation['shipment'];
                            // Quick check if this shipment has usable tracking
                            $tn_check = $shipment['tracking_number'] ?? '';
                            if ( is_array( $tn_check ) ) {
                                $tn_check = isset( $tn_check['tracking_number'] ) ? $tn_check['tracking_number'] : ( ! empty( $tn_check ) ? reset( $tn_check ) : '' );
                            }
                            if ( ! empty( $tn_check ) ) {
                                $alloc_time = $allocation['updated_at'] ?? $allocation['created_at'] ?? '0000-00-00T00:00:00Z';
                                if ( $alloc_time > $latest_alloc_time ) {
                                    $latest_alloc_time = $alloc_time;
                                    $latest_shipment   = $shipment;
                                }
                            }
                        }
                    }
                }

                if ( ! $latest_shipment ) {
                    // No active/current shipment with tracking in Veeqo's recent data for this order
                    continue;
                }

                // Extract tracking + carrier robustly from the LATEST shipment
                $tracking_number = '';
                $tn_raw = $latest_shipment['tracking_number'] ?? '';
                if ( is_array( $tn_raw ) ) {
                    if ( isset( $tn_raw['tracking_number'] ) ) {
                        $tracking_number = $tn_raw['tracking_number'];
                    } else {
                        $tracking_number = ! empty( $tn_raw ) ? reset( $tn_raw ) : '';
                    }
                } else {
                    $tracking_number = (string) $tn_raw;
                }
                if ( is_array( $tracking_number ) ) {
                    $tracking_number = reset( $tracking_number );
                }
                $tracking_number = trim( (string) $tracking_number );

                if ( empty( $tracking_number ) ) {
                    $logger->warning( 'Veeqo Sync Warning: Shipped order ' . $v_number . ' is missing tracking details in Veeqo.', array( 'source' => 'veeqo-tracking-sync' ) );
                    continue;
                }

                $carrier_name = $latest_shipment['sub_carrier_id'] ?? $latest_shipment['service_carrier_name'] ?? '';
                if ( empty( $carrier_name ) && isset( $latest_shipment['carrier'] ) && is_array( $latest_shipment['carrier'] ) ) {
                    $carrier_name = $latest_shipment['carrier']['name'] ?? '';
                }
                if ( is_array( $carrier_name ) ) {
                    $carrier_name = reset( $carrier_name );
                }
                $carrier_name = strtoupper( trim( (string) $carrier_name ) );

                // Only update if this is new/different tracking (prevents duplicate notes on every cron run)
                $existing_tracking = $order->get_meta( '_veeqo_tracking_number' );
                if ( is_array( $existing_tracking ) ) {
                    $existing_tracking = reset( $existing_tracking );
                }
                $existing_tracking = trim( (string) $existing_tracking );

                if ( $existing_tracking === $tracking_number ) {
                    continue; // Already have the current correct tracking
                }

                // Update / correct the tracking meta
                $order->update_meta_data( '_veeqo_tracking_number', $tracking_number );
                $order->update_meta_data( '_veeqo_carrier', $carrier_name );
                $order->save();

                if ( $order->get_status() !== 'completed' ) {
                    $order->update_status( 'completed', 'Order shipped in Veeqo. Tracking: ' . $tracking_number );
                }
                // Silent update only — just replace the tracking meta. No order note added.

                $logger->info( sprintf( 'Success: Order #%s tracking synced/updated. Carrier: %s. Tracking: %s.', $order->get_id(), $carrier_name, $tracking_number ), array( 'source' => 'veeqo-tracking-sync' ) );
            }
        } finally {
            delete_transient( 'veeqo_tracking_sync_running' );
        }
    }

    private function get_formatted_message( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return '';

        $tracking_number = $order->get_meta( '_veeqo_tracking_number' );
        $carrier         = $order->get_meta( '_veeqo_carrier' );

        if ( is_array( $tracking_number ) ) $tracking_number = reset( $tracking_number );
        if ( is_array( $carrier ) )         $carrier         = reset( $carrier );

        if ( empty( $tracking_number ) ) return '';

        $carrier_lower = strtolower( (string) $carrier );
        $tracking_url  = '';

        $carrier_map = array(
            'usps'  => 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=',
            'ups'   => 'https://www.ups.com/track?tracknum=',
            'dhl'   => 'https://www.dhl.com/en/express/tracking.html?AWB=',
            'fedex' => 'https://www.fedex.com/apps/fedextrack/?tracknumbers='
        );

        foreach ( $carrier_map as $key => $base_url ) {
            if ( strpos( $carrier_lower, $key ) !== false ) {
                $tracking_url = $base_url . $tracking_number;
                break;
            }
        }

        $link_html = '<strong>Carrier:</strong> ' . esc_html( $carrier ) . '<br>';
        $link_html .= '<strong>Tracking #:</strong> ' . esc_html( $tracking_number ) . '<br>';
        if ( ! empty( $tracking_url ) ) {
            $link_html .= '<a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">Click here to track your package</a>';
        }

        $default_message = "[tracking_link]\n\nPlease allow up to 24 hours for the tracking number to become active in their system. If you have any issues with your order, we are here to help!";
        $message = get_option( 'veeqo_tracking_message', $default_message );
        
        $message = wp_kses_post( $message );
        $message = str_replace( '[tracking_link]', $link_html, $message );

        return nl2br( $message );
    }

    public function inject_tracking_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $email->id === 'customer_completed_order' && ! $sent_to_admin ) {
            $message = $this->get_formatted_message( $order->get_id() );
            if ( ! empty( $message ) ) {
                echo '<div style="margin-top: 40px; margin-bottom: 40px;">';
                echo '<h2 style="margin-bottom: 15px;">Tracking Information</h2>';
                // SECURITY FIX: Run the message through wp_kses_post to satisfy the escaping rule safely
                echo '<div style="color: #636363; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 150%;">' . wp_kses_post( $message ) . '</div>';
                echo '</div>';
            }
        }
    }

    public function display_tracking_frontend( $order ) {
        $tracking_number = $order->get_meta( '_veeqo_tracking_number' );
        $carrier         = $order->get_meta( '_veeqo_carrier' );

        if ( is_array( $tracking_number ) ) $tracking_number = reset( $tracking_number );
        if ( is_array( $carrier ) )         $carrier         = reset( $carrier );

        if ( ! empty( $tracking_number ) ) {
            $carrier_lower = strtolower( (string) $carrier );
            $tracking_url  = '';
            $carrier_map = array(
                'usps'  => 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=',
                'ups'   => 'https://www.ups.com/track?tracknum=',
                'dhl'   => 'https://www.dhl.com/en/express/tracking.html?AWB=',
                'fedex' => 'https://www.fedex.com/apps/fedextrack/?tracknumbers='
            );
            foreach ( $carrier_map as $key => $base_url ) {
                if ( strpos( $carrier_lower, $key ) !== false ) {
                    $tracking_url = $base_url . $tracking_number;
                    break;
                }
            }

            echo '<section class="woocommerce-customer-details" style="margin-top: 2em;">';
            echo '<h2 class="woocommerce-column__title">Tracking</h2>';
            echo '<address>';
            if ( ! empty( $carrier ) ) {
                echo '<strong>Carrier:</strong> ' . esc_html( $carrier ) . '<br>';
            }
            echo '<strong>Tracking #:</strong> ' . esc_html( $tracking_number ) . '<br>';
            if ( ! empty( $tracking_url ) ) {
                echo '<a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">Click here to track your package</a>';
            }
            echo '</address>';
            echo '</section>';
        }
    }

    public function display_tracking_in_admin( $order ) {
        $tracking_number = $order->get_meta( '_veeqo_tracking_number' );
        $carrier         = $order->get_meta( '_veeqo_carrier' );

        if ( is_array( $tracking_number ) ) $tracking_number = reset( $tracking_number );
        if ( is_array( $carrier ) )         $carrier         = reset( $carrier );

        if ( ! empty( $tracking_number ) ) {
            echo '<div class="order_data_column">';
            echo '<h3>Tracking</h3>'; 
            echo '<p>';
            if ( ! empty( $carrier ) ) {
                echo '<strong>Carrier:</strong> ' . esc_html( $carrier ) . '<br>';
            }
            echo '<strong>Tracking #:</strong> ' . esc_html( $tracking_number );
            echo '</p>';
            echo '</div>';
        }
    }
}