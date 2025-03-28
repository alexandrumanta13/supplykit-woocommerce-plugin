<?php
function send_product_update_to_api($product_id) {
    // Verifică dacă sincronizarea inițială a fost completată
    if (get_option('products_sync_completed')) {
        $wc_product = wc_get_product($product_id);

        $product_details = array(
            'id' => (string) $wc_product->get_id(),
            'title' => get_the_title($product_id),
            'type' => $wc_product->get_type(),
            'status' => get_post_status($product_id),
            'stock' => $wc_product->get_stock_quantity(),
            'date' => get_post_field('post_date', $product_id),
            'modified' => get_post_field('post_modified', $product_id),
            'content' => get_post_field('post_content', $product_id),
            'excerpt' => get_post_field('post_excerpt', $product_id),
            'sku' => $wc_product->get_sku(),
            'regular_price' => $wc_product->get_regular_price(),
            'sale_price' => $wc_product->get_sale_price(),
            'stock_status' => $wc_product->get_stock_status(),
            'weight' => $wc_product->get_weight(),
            'dimensions' => $wc_product->get_dimensions(false),
            'images' => get_product_images($product_id),
            'featured_image' => get_featured_image($product_id),
            'metadata' => get_product_metadata($product_id),
            'global_unique_id' => get_post_meta($product_id, '_global_unique_id', true),
            'taxonomies' => get_product_taxonomies($product_id),
        );

        $products_with_id = array(
            'productId' => (int) $wc_product->get_id(),
            'product_details' => $product_details
        );

        error_log("asdasdsada" . json_encode(array('product' => (object) $products_with_id)));

        $url = 'https://api.inventorypal.io/woocommerce-products/update';
        $api_key = get_api_key();

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'body'      => json_encode(array('product' => (object) $products_with_id, 'productId' => (int) $wc_product->get_id())), 
            'headers'   => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'Origin' => get_site_url()
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Something went wrong: $error_message");
        } else {
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            error_log('response data: ' . print_r($response_data, true));

            if (isset($response_data['success']) && $response_data['success'] === true) {
                error_log('Product updated successfully.');
            } else {
                error_log('Failed to update product.');
            }
        }
    }
}
add_action('woocommerce_update_product', 'send_product_update_to_api');

function send_product_status_change_to_api($new_status, $old_status, $post) {
    // Verificăm dacă post-ul este de tip 'product' și dacă statusul nu este 'auto-draft'
    if ($post->post_type === 'product' && $new_status !== 'auto-draft') {
        error_log('send_product_status_change_to_api called with product_id: ' . $post->ID . ', new_status: ' . $new_status . ', old_status: ' . $old_status);

        if (get_option('products_sync_completed')) {
            $wc_product = wc_get_product($post->ID);

            $product_details = array(
                'id' => (string) $wc_product->get_id(),
                'title' => get_the_title($post->ID),
                'type' => $wc_product->get_type(),
                'status' => $new_status,
                'stock' => $wc_product->get_stock_quantity(),
                'date' => get_post_field('post_date', $post->ID),
                'modified' => get_post_field('post_modified', $post->ID),
                'content' => get_post_field('post_content', $post->ID),
                'excerpt' => get_post_field('post_excerpt', $post->ID),
                'sku' => $wc_product->get_sku(),
                'regular_price' => $wc_product->get_regular_price(),
                'sale_price' => $wc_product->get_sale_price(),
                'stock_status' => $wc_product->get_stock_status(),
                'weight' => $wc_product->get_weight(),
                'dimensions' => $wc_product->get_dimensions(false),
                'images' => get_product_images($post->ID),
                'featured_image' => get_featured_image($post->ID),
                'metadata' => get_product_metadata($post->ID),
                'global_unique_id' => get_post_meta($post->ID, '_global_unique_id', true),
                'taxonomies' => get_product_taxonomies($post->ID),
            );

            $products_with_id = array(
                'productId' => (int) $wc_product->get_id(),
                'product_details' => $product_details
            );

            error_log("Product status change: " . json_encode(array('product' => (object) $products_with_id)));

            $url = 'https://api.inventorypal.io/woocommerce-products/update';
            $api_key = get_api_key();

            $response = wp_remote_post($url, array(
                'method'    => 'POST',
                'body'      => json_encode(array('product' => (object) $products_with_id, 'productId' => (int) $wc_product->get_id())), 
                'headers'   => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Origin' => get_site_url()
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log("Something went wrong: $error_message");
            } else {
                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);

                error_log('response data: ' . print_r($response_data, true));

                if (isset($response_data['success']) && $response_data['success'] === true) {
                    error_log('Product status changed successfully.');
                } else {
                    error_log('Failed to change product status.');
                }
            }
        } else {
            error_log('products_sync_completed is false');
        }
    }
}
add_action('transition_post_status', 'send_product_status_change_to_api', 10, 3);

function send_product_delete_to_api($post_id) {
    // Verificăm dacă post-ul este de tip 'product'
    if (get_post_type($post_id) === 'product') {
        error_log('send_product_delete_to_api called with product_id: ' . $post_id);

        if (get_option('products_sync_completed')) {
            $url = 'https://api.inventorypal.io/woocommerce-products/delete';
            $api_key = get_api_key();

            $response = wp_remote_post($url, array(
                'method'    => 'POST',
                'body'      => json_encode(array('productId' => $post_id)),
                'headers'   => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Origin' => get_site_url()
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log("Something went wrong: $error_message");
            } else {
                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);

                error_log('response data: ' . print_r($response_data, true));

                if (isset($response_data['success']) && $response_data['success'] === true) {
                    error_log('Product deleted successfully.');
                } else {
                    error_log('Failed to delete product.');
                }
            }
        } else {
            error_log('products_sync_completed is false');
        }
    }
}
add_action('before_delete_post', 'send_product_delete_to_api');



function get_product_images($product_id) {
    $images = array();
    $attachment_ids = get_post_meta($product_id, '_product_image_gallery', true);
    if ($attachment_ids) {
        $attachment_ids = explode(',', $attachment_ids);
        foreach ($attachment_ids as $attachment_id) {
            $images[] = wp_get_attachment_url($attachment_id);
        }
    }
    return $images;
}

function get_featured_image($product_id) {
    $featured_image_id = get_post_thumbnail_id($product_id);
    return wp_get_attachment_url($featured_image_id);
}

function get_product_metadata($product_id) {
    $metadata = array();
    $meta_keys = array('_global_unique_id', '_custom_meta_key'); // Adaugă cheile meta relevante
    foreach ($meta_keys as $key) {
        $metadata[$key] = get_post_meta($product_id, $key, true);
    }
    return $metadata;
}

function get_product_taxonomies($product_id) {
    $taxonomies = array();
    $taxonomy_names = get_object_taxonomies('product');
    foreach ($taxonomy_names as $taxonomy) {
        $terms = wp_get_post_terms($product_id, $taxonomy);
        $taxonomies[$taxonomy] = wp_list_pluck($terms, 'name');
    }
    return $taxonomies;
}