<?php
/**
 * Plugin Name: Supply Kit WooCommerce Integration
 * Plugin URI: https://supplykit.com
 * Description: Integrates WooCommerce with Supply Kit to sync products, customers, and orders.
 * Version: 1.0
 * Author: Supply Kit Team
 * Author URI: https://supplykit.com
 */

// Ensure the plugin is accessed within WordPress.
if (!defined('ABSPATH')) {
    exit;
}

class SupplyKitIntegration {
    private $supplykit_api_url = 'https://comenzi.fabricadeasternuturi.ro/api/v2/products/supply-kit';
    private $errors = array();
    private $token;

    public function __construct() {
        // Add settings page in the WordPress admin menu
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Handle form submission to generate and send API keys
        add_action('admin_post_generate_keys', [$this, 'generate_and_send_keys']);

        // Add custom admin styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);

        // Add admin notice for missing API keys
        add_action('admin_notices', [$this, 'check_and_display_admin_notice']);

        // Generate API keys automatically if they don't exist
        if (!$this->api_keys_exist()) {
            $this->generate_and_send_keys();
        }
    }

    // Add the settings page to the admin menu
    public function add_settings_page() {
        add_menu_page(
            'Supply Kit Integration', // Page title
            'Supply Kit', // Menu title
            'manage_options', // Required capability
            'supply-kit-integration-settings', // Menu slug
            [$this, 'settings_page_html'], // Callback function for page content
            'dashicons-cloud', // Icon
            56 // Menu position
        );
    }

    // Check if API keys exist in WooCommerce
    private function api_keys_exist() {
        $site_url = esc_url(get_site_url()); // Get site_url
        $response = $this->verify_site_url($site_url);
        
        if (is_wp_error($response)) {
            $this->log_and_display_error('Failed to verify site URL: ' . $response->get_error_message());
            return false;
        }

    
        $response_data = json_decode(wp_remote_retrieve_body($response), true);
    
        if (wp_remote_retrieve_response_code($response) !== 200 || isset($response_data['error'])) {
            $this->log_and_display_error('Site URL verification failed: ' . wp_remote_retrieve_body($response));
            return false;
        }

        $this->token = $response_data['token'];
    
        $consumer_key = $this->decrypt_key($response_data['consumer_key'], $response_data['token']);
        $consumer_secret = $this->decrypt_key($response_data['consumer_secret'], $response_data['token']);
    
        return $this->check_existing_keys($consumer_key);
    }
    
    // Send site_url to Supply Kit for verification
    private function verify_site_url($site_url) {
        return wp_remote_post('https://comenzi.fabricadeasternuturi.ro/api/v2/products/supply-kit-verify-site', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['store_url' => $site_url]),
        ]);
    }

    // Compare API keys form Supply Kit in WooCommerce
    private function check_existing_keys($consumer_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';
        $existing_key = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE consumer_key = %s", $consumer_key));
        return $existing_key !== null;
    }
    
    // Display and store errors
    private function log_and_display_error($message) {
        error_log($message);
        array_push($this->errors, $message);

        //TODO: Logging logic
    }

    // Display admin notice if API keys are missing
    public function check_and_display_admin_notice() {
        if (!$this->api_keys_exist()) {
            $plugin_url = admin_url('admin.php?page=supply-kit-integration-settings&generate_keys=1');
            echo '<div class="notice notice-error supply-kit-notice-global">
                    <p>API keys for Supply Kit are missing or have been deleted. <a href="' . esc_url($plugin_url) . '">Click here</a> to generate new keys.</p>
                </div>';
        }
    }

    // HTML content for the settings page
    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Supply Kit Integration</h1>
            <p>Welcome to the Supply Kit plugin! Connect your WooCommerce store to Supply Kit for seamless synchronization.</p>
            <?php if (empty($this->errors)): ?>
                <div class="notice notice-success">
                    <p>Store is successfully connected to Supply Kit!</p>
                </div>
            <?php else: 
                $this->display_errors();
            endif; ?>
        </div>
        <?php
    }

    // Generate and send API keys to the Supply Kit backend
    public function generate_and_send_keys() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';

        if (!function_exists('wp_generate_password')) {
            require_once ABSPATH . WPINC . '/pluggable.php';
        }
    
        // Generează new API keys
        $consumer_key = 'ck_' . wp_generate_password(32, false);
        $consumer_secret = 'cs_' . wp_generate_password(32, false);
    
        // Insert new API keys în WooCommerce
        $result = $wpdb->insert($table_name, [
            'user_id' => get_current_user_id(),
            'description' => 'API Key for Supply Kit',
            'permissions' => 'read_write',
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'truncated_key' => substr($consumer_key, -7),
            'last_access' => null,
            'nonces' => serialize([]),
        ]);
    
        if ($result === false) {
            $this->log_and_display_error('Error generating API key: ' . $wpdb->last_error);
            return false;
        }
    
        // Encrypt the new API keys
        $encrypted_consumer_key = $this->encrypt_key($consumer_key, $this->token);
        $encrypted_consumer_secret = $this->encrypt_key($consumer_secret, $this->token);
    
        // Send the encrypted API keys to the Supply Kit backend
        $response = wp_remote_post($this->supplykit_api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'store_url' => get_site_url(),
                'store_name' => get_bloginfo('name'),
                'admin_email' => get_option('admin_email'),
                'consumer_key' => $encrypted_consumer_key,
                'consumer_secret' => $encrypted_consumer_secret,
            ]),
        ]);
    
        // Check the response for errors
        if (is_wp_error($response)) {
            $this->log_and_display_error('Failed to send API keys to Supply Kit: ' . $response->get_error_message());
            return false;
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
    
        if ($response_code !== 200) {
            $this->log_and_display_error('Unexpected response from Supply Kit API: ' . $response_code . ' - ' . $response_body);
            return false;
        }
    
        return true;
    }
    


    function encrypt_key($key, $token) {
        return openssl_encrypt($key, 'aes-256-cbc', $token, 0, '1234567890123456');
    }
    
    function decrypt_key($encrypted_key, $token) {
        return openssl_decrypt($encrypted_key, 'aes-256-cbc', $token, 0, '1234567890123456');
    }

    private function add_error($message) {
        $this->errors[] = $message;
    }

    private function display_errors() {
        if (!empty($this->errors)) {
            foreach ($this->errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        }
    }

    // Enqueue custom CSS styles for the admin panel
    public function enqueue_admin_styles() {
        wp_enqueue_style('supply-kit-admin-style', plugins_url('supply-kit-styles.css', __FILE__));
        wp_enqueue_script('supply-kit-admin-script', plugins_url('supply-kit-script.js', __FILE__), ['jquery'], null, true);
    }
}

// Initialize the plugin
new SupplyKitIntegration();