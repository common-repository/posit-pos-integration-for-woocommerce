<?php

/**
 * Plugin Name: POSIT POS integration for WooCommerce
 * Plugin URI: https://www.posit.co.il
 * Description: POSIT POS integration for WooCommerce
 * Author:  POSIT
 * Version: 1.06
 */
if (!class_exists('WC_Posit')) :
    class WC_Posit
    {
        /**
         * Construct the plugin.
         */
        public function __construct()
        {
            add_action('plugins_loaded', array($this, 'init'));
        }

        /**
         * Initialize the plugin.
         */
        public function init()
        {
            // Set the plugin slug
            define('POSIT_PLUGIN_SLUG', 'wc-settings');
            // Setting action for plugin
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_settings_link_to_plugins');
            // Checks if WooCommerce is installed.
            if (class_exists('WC_Integration')) {
                // Include our integration class.
                include_once 'posit-wc-integration.php';
                // Register the integration.
                add_filter('woocommerce_integrations', array($this, 'add_integration'));
            }
        }

        /**
         * Add a new integration to WooCommerce.
         */
        public function add_integration($integrations)
        {
            $integrations[] = 'WC_POSIT_Integration';

            return $integrations;
        }

        public static function posit_log($message): void
        {
            $log_dir = WP_CONTENT_DIR;
            $log_file = $log_dir . '/posit.log';
            $max_size = 5 * 1024 * 1024; // 5MB

            // Check if log file size is above $max_size
            if (file_exists($log_file) && filesize($log_file) > $max_size) {
                rename($log_file, $log_dir . '/posit-' . time() . '.log');
            }

            // Delete files older than 7 days
            $files = glob($log_dir . "/posit-*.log");
            $now = time();

            foreach ($files as $file) {
                if (is_file($file)) {
                    if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
                        unlink($file);
                    }
                }
            }

            $time = date('Y-m-d H:i:s');
            $args = func_get_args();
            $message = implode(' - ', $args);
            $log_entry = $time . ' - ' . $message . PHP_EOL;

            // Append to the current log file or create one if it doesn't exist
            file_put_contents($log_file, $log_entry, FILE_APPEND);
        }

    }

    $WC_Posit = new WC_Posit(__FILE__);

    function add_settings_link_to_plugins($links)
    {

        $links[] = '<a href="' . menu_page_url(POSIT_PLUGIN_SLUG, false) . '&tab=integration&section=posit-integration">הגדרות</a>';

        return $links;
    }


endif;
