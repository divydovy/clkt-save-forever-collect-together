<?php
/**
 * Fired during plugin deactivation
 *
 * @link       https://contentcloud.com
 * @since      1.0.0
 *
 * @package    CLKT
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    CLKT
 * @author     Your Name
 */
class CLKT_Deactivator {

    /**
     * Handles plugin deactivation tasks
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Remove any plugin-specific options if needed
        // delete_option('clkt_settings');
        
        // Remove any scheduled events if they exist
        wp_clear_scheduled_hook('clkt_cron_hook');
        
        // Clean up any transients
        delete_transient('clkt_init_check');
        
        // Note: We don't delete the transaction table or stored transaction IDs
        // to preserve the link between posts and their Permaweb versions
    }
} 