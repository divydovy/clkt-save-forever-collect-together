<?php
/**
 * Fired during plugin activation
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
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    CLKT
 * @author     Your Name
 */
class CLKT_Activator {

    /**
     * Handles plugin activation tasks
     *
     * @since    1.0.0
     */
    public static function activate() {
        // Create necessary database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Set a transient to trigger init checks
        set_transient('clkt_init_check', true, 30);
    }

    /**
     * Creates necessary database tables
     *
     * @since    1.0.0
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'clkt_transactions';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            transaction_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'confirmed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Sets default plugin options
     *
     * @since    1.0.0
     */
    private static function set_default_options() {
        $defaults = array(
            'token' => 'arweave',
            'debug_mode' => false
        );

        // Only set if not already set
        if (!get_option('clkt_settings')) {
            add_option('clkt_settings', $defaults);
        }
        
        // Set wallet configuration defaults.
        if (!get_option('clkt_wallet_type')) {
            add_option('clkt_wallet_type', 'self'); // Default to self-managed wallet
        }
        if (!get_option('clkt_wallet_provider')) {
            add_option('clkt_wallet_provider', 'evm');
        }
        if (!get_option('clkt_x402_spend_cap_microusdc')) {
            add_option('clkt_x402_spend_cap_microusdc', 100000);
        }
        if (!get_option('clkt_show_cost_estimate')) {
            add_option('clkt_show_cost_estimate', '1');
        }
        if (!get_option('clkt_max_allowed_upload_mb')) {
            add_option('clkt_max_allowed_upload_mb', 100);
        }

        if (!get_option('clkt_free_dispatch_limit_kb')) {
            add_option('clkt_free_dispatch_limit_kb', 100);
        }
    }
}
