<?php

/**
 * Plugin Name: IWB affiliate for woocommerce custom
 * Plugin URI: 
 * Description: Custom code for affiliate for woocommerce
 * Version: 1.2.1
 * Author: 
 * License: 
 * License URI: 
 */

// iwb-affiliate-for-woocommerce-custom

// Hook into WooCommerce order status change.
add_action('woocommerce_order_status_changed', 'iwb_handle_order_status_change', 10, 3);

function iwb_handle_order_status_change($order_id, $old_status, $new_status)
{
    // Only act on pending payment orders to handle before payment processing.
    if ($new_status !== 'pending') {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("Order ID {$order_id} not found.");
        return;
    }

    $customer_id = $order->get_customer_id();
    if (empty($customer_id)) {
        error_log("No customer found for Order ID {$order_id}.");
        return;
    }

    // Step 1: Assign affiliate tag based on product purchase.
    iwb_assign_tag_on_order($order);

    // Step 2: Set referring affiliate as parent affiliate.
    iwb_set_referring_affiliate_as_parent($order, $customer_id);
}


// Hook into the WooCommerce order status change to add self-referral commissions
add_action('woocommerce_order_status_completed', 'iwb_add_self_referral_commission');

/**
 * Add self-referral commission for an affiliate when their order is completed.
 *
 * @param int $order_id The WooCommerce order ID.
 */
function iwb_add_self_referral_commission($order_id)
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
        'type'         => 'self-referral', // Optional, adjust as per your use case
    ];

    // Insert the referral into the database.
    $result = $wpdb->insert($table_name, $referral_data);

    if ($result !== false) {
        $order->add_order_note(
            "Self-referral commission added for Affiliate ID {$customer_id}. Amount: $commission_amount."
        );
        error_log("Self-referral commission added for Affiliate ID {$customer_id}. Amount: $commission_amount.");
    } else {
        $order->add_order_note(
            "Failed to add self-referral commission for Affiliate ID {$customer_id}."
        );
        error_log("Failed to add self-referral commission for Affiliate ID {$customer_id}. Error: {$wpdb->last_error}");
    }
}

/**
 * Assign a tag to the customer affiliate based on the purchased product.
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
            iwb_assign_affiliate_tag($customer_id, $tag);
        }
    }
}

/**
 * Assign a tag to an affiliate.
 *
 * @param int    $user_id   The user ID of the affiliate.
 * @param string $tag       The tag to assign.
 */
function iwb_assign_affiliate_tag($user_id, $tag)
{
    if (empty($user_id) || !is_int($user_id)) {
        return;
    }

    if ('yes' !== afwc_is_user_affiliate($user_id)) {
        return;
    }

    $result = wp_set_object_terms($user_id, $tag, 'afwc_user_tags', true);

    if (is_wp_error($result)) {
        error_log("Failed to assign tag '{$tag}' to affiliate ID {$user_id}. Error: " . $result->get_error_message());
    } else {
        error_log("Successfully assigned tag '{$tag}' to affiliate ID {$user_id}.");
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
    $afwc_api = AFWC_API::get_instance();
    $affiliate_data = $afwc_api->get_affiliate_by_order($order->get_id());

    if (empty($affiliate_data) || empty($affiliate_data['affiliate_id'])) {
        $order->add_order_note(
            "No referring affiliate found for this order. No parent affiliate set."
        );
        return;
    }

    $referring_affiliate_id = (int)$affiliate_data['affiliate_id'];

    // Get the current parent affiliate using the plugin's method.
    $affiliate = new AFWC_Affiliate($customer_id);
    $existing_parent_affiliate = $affiliate->get_parent_affiliate();

    if (!empty($existing_parent_affiliate)) {
        $order->add_order_note(
            "Parent affiliate already exists for Customer ID {$customer_id} (Affiliate ID: {$existing_parent_affiliate}). Overwriting with Affiliate ID {$referring_affiliate_id}."
        );
    }

    // Assign the parent affiliate using the multi-tier class method.
    $multi_tier = AFWC_Multi_Tier::get_instance();
    $multi_tier->assign_parent($customer_id, $referring_affiliate_id);

    // Validate if the parent was set correctly.
    $new_parent_affiliate = $affiliate->get_parent_affiliate();
    if ((int)$new_parent_affiliate === $referring_affiliate_id) {
        $order->add_order_note(
            "Parent affiliate successfully set for Customer ID {$customer_id} to Affiliate ID {$referring_affiliate_id}."
        );
    } else {
        $order->add_order_note(
            "Failed to set Parent affiliate for Customer ID {$customer_id}. Expected: {$referring_affiliate_id}, Found: {$new_parent_affiliate}."
        );
    }
}
