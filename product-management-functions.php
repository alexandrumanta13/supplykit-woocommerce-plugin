<?php

function get_all_products_with_details() {
    global $wpdb;

    // Începe măsurarea timpului
    $start_time = microtime(true);

    // Query pentru a extrage toate produsele, indiferent de status
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'product'");

    // Array pentru a stoca detaliile produselor
    $products_details = array();

    // Iterează prin rezultate pentru a obține detaliile fiecărui produs
    foreach ($results as $product) {
        $product_id = $product->ID;
        $wc_product = wc_get_product($product_id);

        // Obține atributele produsului
        $attributes = array();
        foreach ($wc_product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product_id, $attribute->get_name(), array('fields' => 'names'));
                $attributes[$attribute->get_name()] = $terms;
            } else {
                $attributes[$attribute->get_name()] = $attribute->get_options();
            }
        }

        // Obține imaginile produsului
        $images = array();
        $attachment_ids = $wc_product->get_gallery_image_ids();
        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_url($attachment_id);
            if ($image_url) {
                $images[] = $image_url;
            }
        }

        // Adaugă imaginea principală a produsului
        $featured_image_id = $wc_product->get_image_id();
        if ($featured_image_id) {
            $featured_image_url = wp_get_attachment_url($featured_image_id);
            if ($featured_image_url) {
                $images[] = $featured_image_url;
            }
        }

        // Obține metadata produsului
        $metadata = array();
        $meta_data = $wc_product->get_meta_data();
        foreach ($meta_data as $meta) {
            $metadata[$meta->key] = $meta->value;
        }

        // Obține proprietățile suplimentare
        $brand = get_post_meta($product_id, '_brand', true);
        $gtin = get_post_meta($product_id, '_gtin', true); // GTIN, UPC, EAN sau ISBN

        // Construiește array-ul cu detaliile produsului
        $product_data = array(
            'ID' => $product_id,
            'title' => $product->post_title,
            'type' => $wc_product->get_type(),
            'categories' => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
            'attributes' => $attributes,
            'price' => $wc_product->get_price(),
            'regular_price' => $wc_product->get_regular_price(),
            'sale_price' => $wc_product->get_sale_price(),
            'stock_status' => $wc_product->get_stock_status(),
            'sku' => $wc_product->get_sku(),
            'description' => $wc_product->get_description(),
            'short_description' => $wc_product->get_short_description(),
            'weight' => $wc_product->get_weight(),
            'dimensions' => $wc_product->get_dimensions(),
            'images' => $images,
            'metadata' => $metadata,
            'brand' => $brand,
            'gtin' => $gtin, // GTIN, UPC, EAN sau ISBN
        );

        $products_details[] = $product_data;
    }

    // Termină măsurarea timpului după construirea obiectului
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;

    // Afișează timpul de execuție în consola browserului
    echo '<script>';
    echo 'console.log("Object construction executed in ' . $execution_time . ' seconds.");';
    echo 'console.log(' . json_encode($products_details) . ');';
    echo '</script>';

    // Returnează detaliile produselor
    return $products_details;
}