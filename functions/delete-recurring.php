<?php

/**
 * This functions file handles the deletion of recurring Adyen payment methods
 * 
 * STEPS:
 * 1. Setup new cron action to run every 6 months
 * 1.1 Query orders and build array of PSP refs if they exist
 * 1.2 If PSP refs exist, loop through resultant array, send request to Adyen and delete attached recurring payment method, if any
 * 1.2.1 Batch process 100 PSP refs at a time to avoid server timeouts
 * 
 * @author WC Bessinger <dev@silverbackdev.co.za>
 */
// register custom WP Cron interval
add_filter('cron_schedules', 'atdel_cron_interval');

function atdel_cron_interval($schedules) {
    $schedules['6_months'] = [
        'interval' => 15778800,
        'display'  => esc_html__('Every Six Months')
    ];
    return $schedules;
}

// add custom WP Cron hook
add_action('atdel_cron_hook', 'atdel_cron_execute');

// if our cron hook is not already scheduled, schedule it
if (!wp_next_scheduled('atdel_cron_hook')):
    wp_schedule_event(time(), '6_months', 'atdel_cron_hook');
endif;

// execution function
function atdel_cron_execute() {

    // query completed orders with _transaction_id meta key
    $completed_orders = new WP_Query([
        'post_type'      => 'shop_order',
        'post_status'    => 'wc-completed',
        'posts_per_page' => -1,
        'meta_key'       => '_transaction_id'
    ]);

    if ($completed_orders->have_posts()):

        // sleep counter
        $sleep_counter = 1;

        // loop
        while ($completed_orders->have_posts()): $completed_orders->the_post();

            // check if recurring payment token has already been removed before proceeding
            if (!get_post_meta($completed_orders->post->ID, '_atdel_recurring_removal_processed', true)):

                // retrieve order number
                $order_no = get_post_meta($completed_orders->post->ID, '_order_number_formatted', true);

                // push relevant data to $psp_refs array for further processing
                $psp_refs[$completed_orders->post->ID] = [
                    'order_no' => $order_no,
                    'psp_ref'  => get_post_meta($completed_orders->post->ID, '_transaction_id', true)
                ];

            endif;

            // sleep counter increment
            $sleep_counter++;

            // if $sleep_counter reaches 100, stop execution for 60 seconds and then continue to avoid server overload
            if ($sleep_counter % 100 == 0):
                sleep(120);
            endif;

        endwhile;
        wp_reset_postdata();
    endif;

    // if psp refs present
    if ($psp_refs && is_array($psp_refs) && !empty($psp_refs)):

        $adyen_api_key      = wp_specialchars_decode(get_option('sb_adyen_api_key'));
        $adyen_merchant_acc = get_option('sb_adyen_merchant_account');
        $adyen_gateway_mode = get_option('sb_adyen_gateway_mode');
        $adyen_url_prefix   = get_option('sb_adyen_url_prefix');

        // initial setup
        $client = new Adyen\Client();
        $client->setXApiKey($adyen_api_key);

        // environment setup
        if ('test' === $adyen_gateway_mode) :
            $client->setEnvironment(Adyen\Environment::TEST);
        else :
            $client->setEnvironment(Adyen\Environment::LIVE, $adyen_url_prefix);
        endif;

        // setup recurring
        $recurring = new Adyen\Service\Recurring($client);

        // setup processing counter
        $process_counter = 1;
        
        // loop through psp refs
        foreach ($psp_refs as $order_id => $psp_data):

            // disable/delete recurring payment data
            $params = [
                'merchantAccount'          => $adyen_merchant_acc,
                'shopperReference'         => $psp_data['order_no'],
                'recurringDetailReference' => $psp_data['psp_ref'],
                'contract'                 => 'ONECLICK'
            ];

            // send request and log any exceptions to file
            try {
                $response = $recurring->disable($params);
                update_post_meta($order_id, '_atdel_recurring_removal_processed', true);
                update_post_meta($order_id, '_atdel_recurring_removal_status', json_encode($response));
            } catch (Exception $ex) {
                update_post_meta($order_id, '_atdel_recurring_removal_processed', true);
                update_post_meta($order_id, '_atdel_recurring_removal_status', $ex->getMessage());
            }
            
            // increment process counter
            $process_counter++;
            
            // if $process_counter reaches 100, stop execution for 60 seconds and then continue to avoid server overload
            if ($process_counter % 100 == 0):
                sleep(120);
            endif;
            
        endforeach;

    endif;
}