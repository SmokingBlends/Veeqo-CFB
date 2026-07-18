<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Veeqo_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
        add_action( 'admin_notices', array( $this, 'display_test_results' ) );
        
        // Cleanly adds the single "Settings" link to the WordPress Plugins page
        add_filter( 'plugin_action_links_veeqo-custom-bridge/veeqo-custom-bridge.php', array( $this, 'add_settings_link' ) );
    }

    public function add_settings_link( $links ) {
        // Routes to our hidden admin page
        $settings_url = admin_url( 'admin.php?page=veeqo-bridge-setting' );
        $settings_link = '<a href="' . esc_url( $settings_url ) . '">Settings</a>';
        array_unshift( $links, $settings_link ); 
        return $links;
    }

    public function add_plugin_page() {
        // Passing 'null' as the first parameter creates the page but completely hides it from the sidebar
        add_submenu_page( 
            null, 
            'Veeqo Bridge Settings', 
            'Veeqo Bridge', 
            'manage_options', 
            'veeqo-bridge-setting', 
            array( $this, 'create_admin_page' ) 
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Veeqo Fulfillment Bridge</h1>
            <?php settings_errors( 'veeqo_messages' ); // Only show standard save messages ?>
            <form method="post" action="options.php">
            <?php
                settings_fields( 'veeqo_option_group' );
                do_settings_sections( 'veeqo-bridge-setting' );
                submit_button( 'Save Settings' );
            ?>
            </form>
            <hr>
            <h2>Diagnostic Tools</h2>
            <?php 
            // SECURITY FIX: Generate a secure nonce URL for the test button and escape it
            $test_url = wp_nonce_url( admin_url( 'admin.php?page=veeqo-bridge-setting&test_veeqo_api=1' ), 'veeqo_test_action', 'veeqo_test_nonce' );
            ?>
            <a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary">Test Veeqo API Connection</a>
        </div>
        <?php
    }

    public function page_init() {
        // Native WP API automatically handles the sanitization and saving cleanly
        register_setting( 'veeqo_option_group', 'veeqo_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'veeqo_option_group', 'veeqo_channel_id', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
        register_setting( 'veeqo_option_group', 'veeqo_google_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
        
        // NEW: Register the Tracking Message setting
        register_setting( 'veeqo_option_group', 'veeqo_tracking_message', array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ) );
        
        // NEW: Register the Autocomplete API Key
        register_setting( 'veeqo_option_group', 'veeqo_google_autocomplete_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );

        add_settings_section( 'veeqo_setting_section', 'API & Store Configuration', null, 'veeqo-bridge-setting' );

        add_settings_field( 'veeqo_api_key', 'Veeqo API Key', array( $this, 'api_key_callback' ), 'veeqo-bridge-setting', 'veeqo_setting_section' );
        add_settings_field( 'veeqo_channel_id', 'Veeqo Channel ID', array( $this, 'channel_id_callback' ), 'veeqo-bridge-setting', 'veeqo_setting_section' );
        
        // UPDATED: Label changed to Validation Key
        add_settings_field( 'veeqo_google_api_key', 'Google Address Validation Key', array( $this, 'google_api_key_callback' ), 'veeqo-bridge-setting', 'veeqo_setting_section' );
        
        // NEW: Add the UI field for the Autocomplete Key
        add_settings_field( 'veeqo_google_autocomplete_key', 'Google Places Autocomplete Key', array( $this, 'google_autocomplete_key_callback' ), 'veeqo-bridge-setting', 'veeqo_setting_section' );
        
        // NEW: Add the UI field for the Tracking Message
        add_settings_field( 'veeqo_tracking_message', 'Tracking Email Message', array( $this, 'tracking_message_callback' ), 'veeqo-bridge-setting', 'veeqo_setting_section' );
    }

    public function api_key_callback() {
        $api_key = get_option( 'veeqo_api_key' );
        echo '<input type="password" id="veeqo_api_key" name="veeqo_api_key" value="' . esc_attr( $api_key ) . '" size="50" />';
    }

    public function channel_id_callback() {
        $channel_id = get_option( 'veeqo_channel_id' );
        echo '<input type="text" id="veeqo_channel_id" name="veeqo_channel_id" value="' . esc_attr( $channel_id ) . '" size="15" />';
        echo '<p class="description">Look at the URL of your Custom Store in Veeqo. The ID is the number at the end (e.g., app.veeqo.com/channels/<strong>123456</strong>).</p>';
    }

    public function google_api_key_callback() {
        $google_api_key = get_option( 'veeqo_google_api_key' );
        echo '<input type="password" id="veeqo_google_api_key" name="veeqo_google_api_key" value="' . esc_attr( $google_api_key ) . '" size="50" />';
        echo '<p class="description">Required to format US addresses and append the ZIP+4 code.</p>';
    }

    public function google_autocomplete_key_callback() {
        $autocomplete_key = get_option( 'veeqo_google_autocomplete_key' );
        echo '<input type="password" id="veeqo_google_autocomplete_key" name="veeqo_google_autocomplete_key" value="' . esc_attr( $autocomplete_key ) . '" size="50" />';
        echo '<p class="description">Used on the frontend checkout to predict and auto-fill addresses as the customer types.</p>';
    }

    public function tracking_message_callback() {
        $default_message = "Thank you for your business.\n\nHere is your tracking information:\n[tracking_link]\n\nIf you have any issues with your order we are here to help! Please allow at least 24 hours for your tracking # to become active in the system.";
        $message = get_option( 'veeqo_tracking_message', $default_message );
        echo '<textarea id="veeqo_tracking_message" name="veeqo_tracking_message" rows="12" cols="80">' . esc_textarea( $message ) . '</textarea>';
        echo '<p class="description">Use <strong>[tracking_link]</strong> where you want the clickable tracking link to appear.</p>';
    }

    public function display_test_results() {
        if ( isset( $_GET['test_veeqo_api'] ) && current_user_can( 'manage_options' ) ) {
            
            // SECURITY FIX: Verify the nonce from the button click
            if ( ! isset( $_GET['veeqo_test_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['veeqo_test_nonce'] ) ), 'veeqo_test_action' ) ) {
                return; 
            }

            $api_key = trim( get_option( 'veeqo_api_key' ) );
            if ( empty( $api_key ) ) return;

            $response = wp_remote_get( 'https://api.veeqo.com/orders', array(
                'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-api-key' => $api_key ),
                'timeout' => 15
            ));

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) == 200 ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> API Key is valid and connected to Veeqo!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Veeqo rejected the connection.</p></div>';
            }
        }
    }
}