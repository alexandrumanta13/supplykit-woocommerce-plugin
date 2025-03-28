<?php

function encrypt_key($key, $token) {
    return openssl_encrypt($key, 'aes-256-cbc', $token, 0, '1234567890123456');
}

function decrypt_key($encrypted_key, $token) {
    return openssl_decrypt($encrypted_key, 'aes-256-cbc', $token, 0, '1234567890123456');
}

function log_and_display_error($message, &$errors) {
    if (!is_array($errors)) {
        $errors = array();
    }
    error_log($message);
    array_push($errors, $message);
}

function display_errors($errors) {
    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }
}

function send_verification_request($site_url, $api_key) {
    $response = wp_remote_post('https://api.inventorypal.io/authorized-domains/verify-domain', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'Origin' => get_site_url()
        ],
        'body' => json_encode(['store_url' => $site_url]),
    ]);

    return $response;
}


function is_response_valid($response, &$errors) {
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);

    error_log('Response Code: ' . $response_code);
    error_log('Response Body: ' . $response_body);

    if (($response_code !== 200 && $response_code !== 201) || (isset($response_data['error']) && $response_data['error'])) {
        log_and_display_error('Site URL verification failed: ' . $response_body, $errors);
        return false;
    }

    if (isset($response_data['statusCode']) && ($response_data['statusCode'] === 200 || $response_data['statusCode'] === 201) && isset($response_data['message']) && $response_data['message'] === 'Domain verified successfully') {
        return true;
    }

    log_and_display_error('Unexpected response: ' . $response_body, $errors);
    return false;
}


function generate_keys() {
    if (!function_exists('wp_generate_password')) {
        require_once ABSPATH . WPINC . '/pluggable.php';
    }
    return [
        'consumer_key' => 'ck_' . wp_generate_password(32, false),
        'consumer_secret' => 'cs_' . wp_generate_password(32, false)
    ];
}

function store_keys_in_woocommerce($keys, &$errors) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'woocommerce_api_keys';
    $result = $wpdb->insert($table_name, [
        'user_id' => get_current_user_id(),
        'description' => 'API Key for Supply Kit',
        'permissions' => 'read_write',
        'consumer_key' => $keys['consumer_key'],
        'consumer_secret' => $keys['consumer_secret'],
        'truncated_key' => substr($keys['consumer_key'], -7),
        'last_access' => null,
        'nonces' => serialize([]),
    ]);
    if ($result === false) {
        log_and_display_error('Error generating API key: ' . $wpdb->last_error, $errors);
        return false;
    }
    return true;
}

function send_keys_to_supplykit($keys, $token, $supplykit_api_url, &$errors, $api_key) {
    $encrypted_keys = [
        'consumer_key' => encrypt_key($keys['consumer_key'], $token),
        'consumer_secret' => encrypt_key($keys['consumer_secret'], $token)
    ];
    $response = wp_remote_post($supplykit_api_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'Origin' => get_site_url()
        ],
        'body' => json_encode([
            'store_url' => get_site_url(),
            'store_name' => get_bloginfo('name'),
            'admin_email' => get_option('admin_email'),
            'consumer_key' => $encrypted_keys['consumer_key'],
            'consumer_secret' => $encrypted_keys['consumer_secret'],
        ]),
    ]);

    if (is_wp_error($response)) {
        log_and_display_error('Failed to send API keys to Supply Kit: ' . $response->get_error_message(), $errors);
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    if ($response_code !== 200) {
        log_and_display_error('Unexpected response from Supply Kit API: ' . $response_code . ' - ' . $response_body, $errors);
        return false;
    }
    return true;
}

function save_domain_validation($site_url, $api_key) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'inventorypal';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            domain varchar(255) NOT NULL,
            api_key varchar(255) NOT NULL,
            validated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'api_key'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD api_key varchar(255) NOT NULL");
        }
    }

    $wpdb->insert(
        $table_name,
        [
            'domain' => $site_url,
            'api_key' => $api_key,
            'validated_at' => current_time('mysql')
        ]
    );
}

function get_api_key() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'inventorypal';

    $api_key = $wpdb->get_var("SELECT api_key FROM $table_name LIMIT 1");
    return $api_key;
}

function send_products_to_api($products_details) {
    $url = 'https://api.inventorypal.io/woocommerce-products/sync';

    $api_key = get_api_key();
    $products_with_id = array();
    foreach ($products_details as $product) {
        $products_with_id[] = array(
            'productId' => $product['id'],
            'product_details' => $product
        );
    }

    $response = wp_remote_post($url, array(
        'method'    => 'POST',
        'body'      => json_encode(array('products' => $products_with_id)),
        'headers'   => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'Origin' => get_site_url()
        ),
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        echo "Something went wrong: $error_message";
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (isset($response_data['success']) && $response_data['success'] === true) {
            // Setează un flag în baza de date pentru a preveni execuțiile viitoare
            update_option('products_sync_completed', true);
            echo 'Products synchronized successfully.';
        } else {
            echo 'Failed to synchronize products.';
        }
    }
}