<?php

/**
 * Plugin Name: IWB Affiliate for Woocommerce customizations
 * Plugin URI: https://innerwebblueprint.com/iwb-affiliate-for-woocommerce-custom
 * Description: Custom code for affiliate for woocommerce: 
 * Version: 1.2.4
 * Author: IWB
 * License: MIT
 * License URI:  
 */

/** This plugin does 3 things:
 * 
 *  1.  Assign an affiliate tag to a customer based on the product purchased. 
 *  2.  Assign a new affiliates 'parent affiliate' (because I am not using regular the regular signup method. Purchasing specific producets creates an 'affiliate'.)
 *  3.  Add a self-referral comission.
 */


// Hook into the WooCommerce order completed hook to perform some customizations
add_action('woocommerce_order_status_completed', 'iwb_affiliate_order_completed_customizations');

/**
 * function iwb_affiliate_order_completed_customizations($order_id)
 *  1.  Assign an affiliate tag to a customer based on the product purchased. 
 *  2.  Assign a new affiliates 'parent affiliate' (because I am not using regular the regular signup method. Purchasing specific producets creates an 'affiliate'.)
 *  3.  Add a self-referral comission.
 * 
 * @param int $order_id The WooCommerce order ID.
 */
function iwb_affiliate_order_completed_customizations($order_id)
{
    global $wpdb;

    // Load the order.
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("Order ID {$order_id} not found.");
        return;
    }

    // Get the customer ID.
    $customer_id = $order->get_customer_id();
    if (empty($customer_id)) {
        error_log("No customer found for Order ID {$order_id}.");
        return;
    }

    // Check if the customer is an affiliate.
    if ('yes' !== afwc_is_user_affiliate($customer_id)) {
        error_log("Customer ID {$customer_id} is not an affiliate.");
        return;
    }

    // Define the table name for referrals
    $table_name = $wpdb->prefix . 'afwc_referrals';

    // Check if a commission for this order already exists
    $existing_referral = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE post_id = %d AND affiliate_id = %d",
        $order_id,
        $customer_id
    ));

    if ($existing_referral) {
        error_log("Self-referral commission for Order ID {$order_id} already exists. Skipping.");
        return;
    }

    // Define self-referral rates based on product categories.
    $product_rates = [
        'beginner' => 0.30, // 30%
        'basic'    => 0.35, // 35%
        'advanced' => 0.40, // 40%
        'mastery'  => 0.40, // 40%
    ];

    // Determine the self-referral rate based on the purchased product.
    $applicable_rate = 0;
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);

        if ($product) {
            $product_slug = sanitize_title($product->get_slug());
            if (isset($product_rates[$product_slug])) {
                $applicable_rate = $product_rates[$product_slug];
                break; // Stop searching once a match is found.
            }
        }
    }

    if ($applicable_rate === 0) {
        error_log("No valid self-referral rate found for Customer ID {$customer_id}. Skipping.");
        return;
    }

    // Calculate the commission amount.
    $order_total = $order->get_total(); // Adjust this if needed (e.g., exclude taxes/shipping).
    $commission_amount = $order_total * $applicable_rate;

    // Prepare the data for the new commission.
    $referral_data = [
        'affiliate_id' => $customer_id,
        'post_id'      => $order_id, // Use post_id as the order ID
        'datetime'     => current_time('mysql'),
        'amount'       => $commission_amount,
        'currency_id'  => 'USD', // Adjust if dynamic currencies are supported
        'status'       => 'unpaid', // Adjust status as necessary
        'type'         => 'selfref', // Optional, adjust as per your use case
    ];

    // Insert the referral into the database.
    $result = $wpdb->insert($table_name, $referral_data);

    if ($result === false) {
        error_log("Failed to add self-referral commission for Affiliate ID {$customer_id}. Error: {$wpdb->last_error}");
        error_log("Query: " . $wpdb->last_query);
        error_log("Data: " . print_r($referral_data, true));
        $order->add_order_note(
            "Failed to add self-referral commission for Affiliate ID {$customer_id}."
        );
    } else {
        $order->add_order_note(
            "Self-referral commission added for Affiliate ID {$customer_id}. Amount: $commission_amount."
        );
        //error_log("Self-referral commission added for Affiliate ID {$customer_id}. Amount: $commission_amount.");
    }

    // call the set tag based on product ordered function
    iwb_assign_tag_on_order($order);

    // call the set the parent affiliate functioin
    iwb_set_referring_affiliate_as_parent($order, $customer_id);
}

/**
 * Assign a tag to the new customer affiliate based on purchased product.
 *
 * @param WC_Order $order The WooCommerce order.
 */
function iwb_assign_tag_on_order($order)
{
    $customer_id = $order->get_customer_id();
    if (empty($customer_id)) {
        return;
    }

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);

        if ($product) {
            $tag = 'product-' . sanitize_title($product->get_slug());
            //error_log("Will assign tag: '{$tag}' to affiliate ID {$customer_id}.");

            $result = wp_set_object_terms($customer_id, $tag, 'afwc_user_tags', true);

            if (is_wp_error($result)) {
                error_log("Failed to assign tag '{$tag}' to affiliate ID {$customer_id}. Error: " . $result->get_error_message());
            } else {
                $order->add_order_note(
                    "Successfully assigned tag '{$tag}' to new affiliate ID {$customer_id}."
                );
            }
        }
    }
}

/**
 * Set the referring affiliate as the parent affiliate for the customer.
 *
 * @param WC_Order $order        The WooCommerce order.
 * @param int      $customer_id  The customer ID.
 */
function iwb_set_referring_affiliate_as_parent($order, $customer_id)
{
    // Get referring affiliate ID from the order.
    $order_id = $order->get_id();
    $afwc_api = AFWC_API::get_instance();
    $affiliate_data = $afwc_api->get_affiliate_by_order($order_id);

    //error_log("Affiliate Data: " . print_r($affiliate_data));
    if (isset($affiliate_data['affiliate_id'])) {
        $referring_affiliate_id = (int)$affiliate_data['affiliate_id'];

        //error_log("Customer ID: $customer_id, Order ID: $order_id , Referring affiliate: {$referring_affiliate_id}");

        // to remove if my tests works..
        // // Get the current parent affiliate using the plugin's method.
        // $affiliate = new AFWC_Affiliate($customer_id);
        // $existing_parent_affiliate = $affiliate->get_parent_affiliate();

        // // Log Current Parent affiliate
        // error_log("Current parent affiliate: '{$existing_parent_affiliate}'");

        // Check if customer has existing parent affilaite
        $multi_tier = AFWC_Multi_Tier::get_instance();

        // Current Parent affiliate
        $pre_existing_parent_affiliate = $multi_tier->get_parents($customer_id);
        //error_log("Current parent affiliate: " . print_r($pre_existing_parent_affiliate));

        // Assign new parent affiliate
        $multi_tier->assign_parent($customer_id, $referring_affiliate_id);
        // $new_result = $multi_tier->assign_parent($customer_id, $referring_affiliate_id);
        //error_log(" value of new_result: " . print_r($new_result, true));

        // If not empty, we are going to override? Yes, we are:
        if (!empty($pre_existing_parent_affiliate)) {
            $order->add_order_note(
                "Parent affiliate already exists for Customer ID {$customer_id} (Affiliate ID: " . print_r($pre_existing_parent_affiliate, true) . " Overwriting with new parent Affiliate ID {$referring_affiliate_id}."
            );
        }

        $new_parent = $multi_tier->get_parents($customer_id);

        //error_log("New parent affiliate: " . print_r($new_parent, true));

        $order->add_order_note(
            "Multi-Tier parent affiliate set for Customer ID {$customer_id} to Affiliate ID " . print_r($new_parent, true)
        );
    } else {
        $order->add_order_note(
            "No referring affiliate found for this order. No parent affiliate set."
        );
    }
}
