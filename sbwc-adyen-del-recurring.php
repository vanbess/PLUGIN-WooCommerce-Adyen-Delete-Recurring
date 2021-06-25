<?php

/**
 * Plugin Name: SBWC Adyen Periodic Token Deleter
 * Description: Deletes stored Adyen payment methods from Adyen server once every 6 months using WP Cron
 * Author: WC Bessinger
 * Version: 1.0.0
 */
defined('ABSPATH') || defined('ADYEN_API_KEY') ?: exit();

// init
add_action('init', 'sbwc_atdel_init');

function sbwc_atdel_init() {

    // constants
    defined('ATDEL_PATH') ?: define('ATDEL_PATH', plugin_dir_path(__FILE__));

    // load adyen if not loaded already
    if (!class_exists('ComposerAutoloaderInitddf77701b55b98174830194aa67e917e')):
        require_once ATDEL_PATH . 'vendor/autoload.php';
    endif;

    // delete recurring function
    include ATDEL_PATH . 'functions/delete-recurring.php';
}
