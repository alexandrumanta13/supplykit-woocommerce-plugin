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

require_once 'general-functions.php';
require_once 'product-management-functions.php';
require_once 'event-hooks.php';

class SupplyKitIntegration {
    private $supplykit_api_url = 'https://api.inventorypal.io/authorized-domains/sync-domain';
    private $errors = array();
    private $token;
    private $description = "API Key for Supply Kit";

    public function __construct() {
        // update_option('products_sync_completed', false);
        $this->initialize_hooks();
        $this->handle_generate_keys_request();

        add_action('admin_init', array($this, 'execute_get_all_products_with_details'));
    }

    private function initialize_hooks() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_verify_domain', [$this, 'verify_domain']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
        add_action('admin_notices', [$this, 'check_and_display_admin_notice']);
    }

    public function add_settings_page() {
        add_menu_page(
            'Supply Kit Integration',
            'Supply Kit',
            'manage_options',
            'supply-kit-integration-settings',
            [$this, 'settings_page_html'],
            'dashicons-cloud',
            56
        );
    }

    public function settings_page_html() {
        $this->display_settings_form();
        $this->display_status_message();
    }

    public function display_settings_form() {
        $form_hidden = get_option('supplykit_form_hidden', false);
        ?>
        <div class="wrap">
            <h1>Supply Kit Integration</h1>
            <?php if ($form_hidden): ?>
                <p>Store is successfully connected to Supply Kit!</p>
            <?php else: ?>
                <p>Introduceți API key-ul pentru verificarea domeniului:</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="verify_domain">
                    <label for="api_key">API Key:</label>
                    <input type="text" name="api_key" id="api_key" required>
                    <button type="submit">Verifică Domeniul</button>
                </form>
            <?php endif; ?>
            <?php if (!empty($this->errors)): ?>
                <?php display_errors($this->errors); ?>
            <?php endif; ?>
        </div>
        <?php
    }


    private function display_status_message() {
        if (empty($this->errors)) {
            echo '<div class="notice notice-success"><p>Store is successfully connected to Supply Kit!</p></div>';
        } else {
            display_errors($this->errors);
        }
    }

    public function verify_domain() {
        $api_key = sanitize_text_field($_POST['api_key']);
        $site_url = esc_url(get_site_url());
        $response = send_verification_request($site_url, $api_key);
    
        if (is_response_valid($response, $this->errors)) {
            save_domain_validation($site_url, $api_key);
    
            if (!$this->api_keys_exist()) {
                $this->generate_and_send_keys();
            }
    
            update_option('supplykit_form_hidden', true);
    
            wp_redirect(admin_url('admin.php?page=supply-kit-integration-settings'));
            exit;
        } else {
            foreach ($this->errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        }
    }

    private function api_keys_exist() {
        global $wpdb;
    
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';
    
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log("Tabela $table_name nu există.");
            return false;
        }
    
        $existing_key = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE description = %s", $this->description));
        return $existing_key !== null;
    }

    public function generate_and_send_keys() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'inventorypal';
        $site_url = esc_url(get_site_url());
    
        $api_key = $wpdb->get_var($wpdb->prepare("SELECT api_key FROM $table_name WHERE domain = %s", $site_url));
    
        if (!$api_key) {
            log_and_display_error('API key not found for the domain: ' . $site_url, $this->errors);
            return false;
        }
    
        $keys = generate_keys();
        store_keys_in_woocommerce($keys, $this->errors);
        send_keys_to_supplykit($keys, $this->token, $this->supplykit_api_url, $this->errors, $api_key);
        wp_redirect(admin_url('admin.php?page=supply-kit-integration-settings'));
    }

    public function check_and_display_admin_notice() {
        if (!$this->api_keys_exist()) {
            $plugin_url = admin_url('admin.php?page=supply-kit-integration-settings&generate_and_send_keys=1');
            echo '<div class="notice notice-error supply-kit-notice-global">
                    <p>API keys for Supply Kit are missing or have been deleted. <a href="' . esc_url($plugin_url) . '">Click here</a> to generate new keys. </p>
                </div>';
        }
    }

    public function handle_generate_keys_request() {
        if (isset($_GET['generate_and_send_keys']) && $_GET['generate_and_send_keys'] == '1') {
            $this->generate_and_send_keys();
        }
    }

    public function enqueue_admin_styles() {
        wp_enqueue_style('supply-kit-admin-style', plugins_url('supply-kit-styles.css', __FILE__));
        wp_enqueue_script('supply-kit-admin-script', plugins_url('supply-kit-script.js', __FILE__), ['jquery'], null, true);
    }


    public function execute_get_all_products_with_details() {
        // Verifică dacă sincronizarea a fost deja realizată
        $sync_completed = get_option('products_sync_completed', false);
    
        if ($sync_completed) {
            return;
        }
    
        $products_details = get_all_products_with_details();
        send_products_to_api($products_details);
    }
}

new SupplyKitIntegration();