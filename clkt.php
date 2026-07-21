<?php

/**
 * Plugin Name: CLKT - Save Forever, Collect Together
 * Plugin URI: https://getclkt.com
 * Description: Your content deserves to outlive your website. CLKT permanently preserves your WordPress posts and media on the decentralized web. One click, saved forever. No recurring fees, no expiration dates.
 * Version: 1.3.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Lockie
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clkt-save-forever-collect-together
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLKT_VERSION', '1.3.0');
define('CLKT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLKT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Return post types CLKT should expose on the authoring/public content surface.
 *
 * CLKT keeps attachments on the media upload path and leaves built-in pages opt-in
 * via the filter. Public custom post types with editor support are included by
 * default so editorial CPTs like photo archives can be collected.
 *
 * @return string[]
 */
function clkt_get_supported_post_types()
{
    $post_types = array('post');
    $public_post_types = get_post_types(array('public' => true), 'objects');

    foreach ($public_post_types as $post_type => $post_type_object) {
        if ($post_type === 'post' || $post_type === 'attachment') {
            continue;
        }

        if (!empty($post_type_object->_builtin)) {
            continue;
        }

        if (!post_type_supports($post_type, 'editor')) {
            continue;
        }

        $post_types[] = $post_type;
    }

    /**
     * Filter the post types supported by CLKT.
     *
     * Return post type slugs. Include `page` here if pages should also expose
     * CLKT controls on a site.
     *
     * @param string[] $post_types Supported post type slugs.
     */
    $post_types = apply_filters('clkt_supported_post_types', $post_types);
    $post_types = array_map('sanitize_key', (array) $post_types);
    $post_types = array_values(array_unique(array_filter($post_types)));

    return !empty($post_types) ? $post_types : array('post');
}

/**
 * Check whether CLKT supports a specific post type.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function clkt_is_supported_post_type($post_type)
{
    return in_array(sanitize_key((string) $post_type), clkt_get_supported_post_types(), true);
}

/**
 * Get the current admin screen post type when available.
 *
 * @return string
 */
function clkt_get_current_admin_post_type()
{
    if (function_exists('get_current_screen')) {
        $screen = get_current_screen();
        if ($screen && !empty($screen->post_type)) {
            return sanitize_key($screen->post_type);
        }
    }

    global $post_type, $post;

    if (!empty($post_type)) {
        return sanitize_key($post_type);
    }

    if ($post && !empty($post->post_type)) {
        return sanitize_key($post->post_type);
    }

    return '';
}

// Schedule auto-upload when a published post should be saved to Permaweb.
function clkt_schedule_auto_upload($post_id, $post, $update)
{
    // Skip if this is not a post save or if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Skip if user doesn't have permission to edit posts
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Skip unsupported post types.
    if (!$post || !clkt_is_supported_post_type($post->post_type)) {
        return;
    }

    // Skip if post is not published
    if ($post->post_status !== 'publish') {
        return;
    }

    // Check if auto-upload should happen based on settings
    $global_auto_save = get_option('clkt_global_auto_save', false);
    $per_post_setting = get_post_meta($post_id, '_clkt_per_post_save', true);

    $should_upload = false;
    if ($per_post_setting === 'always') {
        $should_upload = true;
    } elseif ($per_post_setting === 'never') {
        $should_upload = false;
    } elseif (empty($per_post_setting) || $per_post_setting === 'default') {
        $should_upload = $global_auto_save;
    }

    // If auto-upload is not enabled for this post, exit
    if (!$should_upload) {
        return;
    }

    // Note: We intentionally do NOT check for existing TX ID here
    // because we want to allow re-uploads when content changes (e.g., after editing).
    // The JavaScript UploadManager will handle deduplication by comparing content hashes.

    update_post_meta($post_id, '_clkt_pending_upload', array(
        'timestamp' => time(),
        'encrypt' => (bool) get_post_meta($post_id, '_clkt_encrypt_content', true),
    ));
}

function clkt_schedule_auto_upload_from_save_post($post_id, $post, $update)
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    clkt_schedule_auto_upload($post_id, $post, $update);
}

function clkt_schedule_auto_upload_rest($post, $request, $creating)
{
    clkt_schedule_auto_upload($post->ID, $post, !$creating);
}

add_action('save_post', 'clkt_schedule_auto_upload_from_save_post', 20, 3);

function clkt_register_supported_post_type_hooks()
{
    static $registered_post_types = array();

    foreach (clkt_get_supported_post_types() as $post_type) {
        if (isset($registered_post_types[$post_type])) {
            continue;
        }

        add_filter("manage_{$post_type}_posts_columns", 'clkt_add_posts_columns');
        add_action("manage_{$post_type}_posts_custom_column", 'clkt_populate_posts_columns', 10, 2);
        add_action("rest_after_insert_{$post_type}", 'clkt_schedule_auto_upload_rest', 10, 3);

        if ($post_type === 'post') {
            add_filter('manage_posts_columns', 'clkt_add_posts_columns');
            add_action('manage_posts_custom_column', 'clkt_populate_posts_columns', 10, 2);
        }

        $registered_post_types[$post_type] = true;
    }
}
add_action('init', 'clkt_register_supported_post_type_hooks', 100);
add_action('admin_init', 'clkt_register_supported_post_type_hooks', 20);
add_action('rest_api_init', 'clkt_register_supported_post_type_hooks', 20);

// Add Permaweb status column to posts list
function clkt_add_posts_columns($columns)
{
    // Insert the column before the date column
    $new_columns = array();
    foreach ($columns as $key => $value) {
        if ($key === 'date') {
            $new_columns['clkt_permaweb_status'] = '<span title="Permaweb Upload Status">⛅ Permaweb</span>';
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
}

// Populate the Permaweb status column
function clkt_populate_posts_columns($column, $post_id)
{
    if ($column === 'clkt_permaweb_status') {
        $pending_upload = get_post_meta($post_id, '_clkt_pending_upload', true);

        if (!empty($pending_upload)) {
            echo '<span class="clkt-status-pending">📤 Auto-upload</span>';
            return;
        }

        // Check for valid uploaded versions using same logic as bulk upload
        $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
        $transaction_id = null;

        if (!empty($versions) && is_array($versions)) {
            // Find the most recent VALID version (non-TEST in production, TEST in test mode)
            $latest_valid_version = null;
            $latest_timestamp = 0;

            foreach ($versions as $version) {
                // Check all possible key variations for transaction ID
                $version_tx_id = '';
                if (!empty($version['transactionId'])) {
                    $version_tx_id = $version['transactionId'];
                } elseif (!empty($version['tx_id'])) {
                    $version_tx_id = $version['tx_id'];
                } elseif (!empty($version['id'])) {
                    $version_tx_id = $version['id'];
                }

                // Only consider versions with valid transaction IDs for current mode
                if (clkt_is_valid_tx_id($version_tx_id)) {
                    $version_timestamp = isset($version['timestamp']) ? $version['timestamp'] : 0;
                    if ($version_timestamp >= $latest_timestamp) {
                        $latest_timestamp = $version_timestamp;
                        $latest_valid_version = $version;
                        $transaction_id = $version_tx_id;
                    }
                }
            }
        }

        // Display status based on valid version
        if ($transaction_id) {
            $short_id = substr($transaction_id, 0, 8);
            echo '<span class="clkt-status-uploaded" title="Uploaded to Permaweb: ' . esc_attr($transaction_id) . '">';
            echo '✅ <small>' . esc_html($short_id) . '</small>';
            echo '</span>';
        } else {
            echo '<span class="clkt-status-not-uploaded">⚪ Not uploaded</span>';
        }
    }
}

// Add CSS for status indicators
function clkt_admin_head_posts_list($hook)
{
    if ($hook !== 'edit.php') {
        return;
    }
    $screen = get_current_screen();
    if ($screen && !empty($screen->post_type) && clkt_is_supported_post_type($screen->post_type)) {
        $css = '
            .column-clkt_permaweb_status { width: 120px; }
            .clkt-status-uploaded { color: #46b450; font-weight: bold; }
            .clkt-status-pending { color: #00a0d2; font-weight: bold; }
            .clkt-status-not-uploaded { color: #666; }
            .clkt-status-uploaded small { font-family: monospace; background: #f0f0f0; padding: 1px 3px; border-radius: 2px; }
        ';
        wp_register_style('clkt-posts-list', false, array(), CLKT_VERSION);
        wp_enqueue_style('clkt-posts-list');
        wp_add_inline_style('clkt-posts-list', $css);
    }
}
add_action('admin_enqueue_scripts', 'clkt_admin_head_posts_list');

// Remove references to class-based implementation
function content_cloud_init()
{
    // No need to initialize classes anymore
}
add_action('plugins_loaded', 'content_cloud_init');

/**
 * Activation hook
 */
function content_cloud_activate()
{
    // Create custom tables if needed
    require_once CLKT_PLUGIN_DIR . 'includes/class-clkt-activator.php';
    CLKT_Activator::activate();
}
register_activation_hook(__FILE__, 'content_cloud_activate');

/**
 * Deactivation hook
 */
function content_cloud_deactivate()
{
    // Cleanup if needed
    require_once CLKT_PLUGIN_DIR . 'includes/class-clkt-deactivator.php';
    CLKT_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'content_cloud_deactivate');

function clkt_enqueue_plugin_admin_assets($hook)
{
    // Only load on supported post edit screens, bulk upload page, and settings page.
    $post_edit_pages = array('post.php', 'post-new.php');
    $clkt_admin_pages = array('settings_page_clkt-settings', 'toplevel_page_clkt-bulk-upload');
    $is_post_edit_page = in_array($hook, $post_edit_pages, true);
    $is_clkt_admin_page = in_array($hook, $clkt_admin_pages, true);

    // Check if we're on an allowed page and user can edit posts
    if (!current_user_can('edit_posts') || (!$is_post_edit_page && !$is_clkt_admin_page)) {
        return;
    }

    if ($is_post_edit_page && !clkt_is_supported_post_type(clkt_get_current_admin_post_type())) {
        return;
    }

    // Enqueue jQuery
    wp_enqueue_script('jquery');

    // Enqueue our bundled JavaScript with timestamp for cache busting
    wp_enqueue_script(
        'clkt-bundle',
        plugin_dir_url(__FILE__) . 'dist/bundle.js',
        array('jquery'),
        CLKT_VERSION . '-' . filemtime(plugin_dir_path(__FILE__) . 'dist/bundle.js'), // Version with file modification time
        true // Load in footer - ensures DOM is ready
    );

    // Enqueue upload components CSS with timestamp for cache busting
    wp_enqueue_style(
        'clkt-upload-components',
        plugin_dir_url(__FILE__) . 'assets/css/upload-components.css',
        array(),
        CLKT_VERSION . '-' . filemtime(plugin_dir_path(__FILE__) . 'assets/css/upload-components.css') // Version with file modification time
    );

    // Get current user info
    $current_user = wp_get_current_user();

    // Localize script with AJAX URL, nonce, and user info
    // JavaScript global object for AJAX operations
    wp_localize_script('clkt-bundle', 'clkt_ajax_obj', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('clkt_ajax_nonce'),
            'plugin_url' => plugin_dir_url(__FILE__),
            'version' => CLKT_VERSION,

            // Wallet configuration
            'wallet_type' => 'self', // Browser wallet mode.
            'wallet_provider' => get_option('clkt_wallet_provider', 'evm'),
            'x402_spend_cap_microusdc' => strval(max(0, absint(get_option('clkt_x402_spend_cap_microusdc', 100000)))),

            // Cost control settings
            'show_cost_estimate' => get_option('clkt_show_cost_estimate', '1'),
            'free_dispatch_limit_kb' => intval(get_option('clkt_free_dispatch_limit_kb', 100)), // Max size for FREE uploads via dispatch
            'max_allowed_upload_mb' => intval(get_option('clkt_max_allowed_upload_mb', 100)), // Maximum size user is allowed to upload

            // Advanced settings
            'arweave_gateway' => 'https://arweave.net',

            // Auto-save settings
            'global_auto_save' => get_option('clkt_global_auto_save', false) ? '1' : '0',
            // TODO SECURITY: Password exposed to client-side (low-medium risk for logged-in users)
            // Future: Refactor encryption flow to retrieve password via secure AJAX (clkt_get_encryption_password)
            'global_encryption_password' => get_option('clkt_global_encryption_password', ''),

            // Test mode
            'test_mode' => get_option('clkt_test_mode', false) ? true : false,
            'debug' => defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options'),

            'user' => array(
                'id' => $current_user->ID,
                'name' => $current_user->display_name
                // SECURITY: Email removed - not needed in client-side code
            )
        ));

    // Add inline script to check WebCrypto availability
    wp_add_inline_script('clkt-bundle', '
        // Ensure proper initialization order
        document.addEventListener("DOMContentLoaded", function() {
            var clktDebugLog = function() {
                if (window.clkt_ajax_obj && window.clkt_ajax_obj.debug) {
                    console.log.apply(console, arguments);
                }
            };

            // Diagnostic information
            clktDebugLog("CLKT - Security Context Check:");
            clktDebugLog("- Is Secure Context:", window.isSecureContext);
            clktDebugLog("- Protocol:", window.location.protocol);
            clktDebugLog("- Crypto Object:", typeof window.crypto !== "undefined");
            clktDebugLog("- Subtle Crypto:", window.crypto && typeof window.crypto.subtle !== "undefined");

            // Check WebCrypto availability
            function checkWebCrypto() {
                try {
                    // Check for secure context first
                    if (!window.isSecureContext) {
                        console.error("WebCrypto requires a secure context (HTTPS)");
                        return false;
                    }

                    if (window.crypto && window.crypto.subtle) {
                        clktDebugLog("Native WebCrypto is available");
                        return true;
                    }

                    console.error("WebCrypto API components missing:", {
                        crypto: !!window.crypto,
                        subtle: !!(window.crypto && window.crypto.subtle)
                    });
                    return false;
                } catch (e) {
                    console.error("Error checking WebCrypto:", e);
                    return false;
                }
            }

            if (!checkWebCrypto()) {
                var notice = document.createElement("div");
                notice.className = "notice notice-error";
                
                // Different message based on context
                var message = window.isSecureContext 
                    ? "Your browser is blocking access to required cryptographic features. This might be due to security settings or browser configuration."
                    : "WebCrypto requires a secure context (HTTPS). Please access the WordPress admin over HTTPS.";
                
                notice.innerHTML = "<p><strong>CLKT Error:</strong> " + message + "</p>";
                
                // Insert the notice at the top of the editor
                var targetElement = document.querySelector(".wrap h1") || document.querySelector("#wpbody-content");
                if (targetElement) {
                    targetElement.insertAdjacentElement("afterend", notice);
                }
            }
        });

    ');
}

// Include the consolidated settings page
require_once plugin_dir_path(__FILE__) . 'includes/admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/permaweb-integration.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend-display.php';
require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';

// Add settings page
function clkt_add_settings_page()
{
    // Add main settings page
    add_options_page(
        'CLKT Settings',      // Page title
        'CLKT',               // Menu title
        'manage_options',              // Capability
        'clkt-settings',      // Menu slug
        'clkt_render_consolidated_settings_page'  // Use new consolidated page
    );

}
add_action('admin_menu', 'clkt_add_settings_page');

// Register settings
function clkt_register_settings()
{
    register_setting('clkt_settings', 'clkt_show_permaweb_on_public', array(
        'type' => 'string',
        'description' => 'Show Permaweb versions on public posts',
        'default' => 'yes',
        'sanitize_callback' => 'sanitize_text_field'
    ));

    register_setting('clkt_settings', 'clkt_permaweb_public_heading', array(
        'type' => 'string',
        'description' => 'Heading for Permaweb section on public posts',
        'default' => 'CLKT - Permanent Record',
        'sanitize_callback' => 'sanitize_text_field'
    ));

    register_setting('clkt_settings', 'clkt_max_allowed_upload_mb', array(
        'type' => 'integer',
        'description' => 'Maximum file size in MB allowed for uploads',
        'default' => 100,
        'sanitize_callback' => 'absint'
    ));

    register_setting('clkt_settings', 'clkt_free_dispatch_limit_kb', array(
        'type' => 'integer',
        'description' => 'Maximum file size in KB for FREE uploads via ArConnect dispatch',
        'default' => 100,
        'sanitize_callback' => 'absint'
    ));

    register_setting('clkt_settings', 'clkt_global_auto_save', array(
        'type' => 'boolean',
        'description' => 'Globally enable auto-save to Permaweb for all posts',
        'default' => false,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));

    register_setting('clkt_settings', 'clkt_global_encryption_password', array(
        'type' => 'string',
        'description' => 'Global password for encrypting posts',
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field'
    ));

    register_setting('clkt_settings', 'clkt_test_mode', array(
        'type' => 'boolean',
        'description' => 'Enable test mode (simulates uploads without spending AR)',
        'default' => false,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));

    register_setting('clkt_settings', 'clkt_show_cost_estimate', array(
        'type' => 'boolean',
        'description' => 'Display estimated AR costs before upload',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));

    register_setting('clkt_settings', 'clkt_wallet_provider', array(
        'type' => 'string',
        'description' => 'Browser wallet provider for uploads',
        'default' => 'evm',
        'sanitize_callback' => function ($value) {
            return in_array($value, array('evm', 'wander'), true) ? $value : 'evm';
        }
    ));

    register_setting('clkt_settings', 'clkt_x402_spend_cap_microusdc', array(
        'type' => 'integer',
        'description' => 'Maximum Base USDC spend per Turbo x402 upload, denominated in microUSDC',
        'default' => 100000,
        'sanitize_callback' => 'absint'
    ));

    register_setting('clkt_settings', 'clkt_show_powered_by', array(
        'type' => 'string',
        'description' => 'Show Powered by CLKT credit link on public posts',
        'default' => 'no',
        'sanitize_callback' => 'sanitize_text_field'
    ));
}
add_action('admin_init', 'clkt_register_settings');

// DEPRECATED: Old settings page functions have been moved to consolidated settings
// See includes/admin/settings-page.php for the new implementation

// Add nonce refresh handler
function clkt_get_nonce()
{
    $nonce = wp_create_nonce('clkt_ajax_nonce');
    wp_send_json_success(['nonce' => $nonce]);
}
add_action('wp_ajax_clkt_get_nonce', 'clkt_get_nonce');
add_action('wp_ajax_nopriv_clkt_get_nonce', 'clkt_get_nonce');

/**
 * Save the latest public CLKT identity-link transaction for this site.
 */
function clkt_save_identity_link()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $tx_id = isset($_POST['tx_id']) ? sanitize_text_field(wp_unslash($_POST['tx_id'])) : '';
    $evm_address = isset($_POST['evm_address']) ? sanitize_text_field(wp_unslash($_POST['evm_address'])) : '';
    $arweave_address = isset($_POST['arweave_address']) ? sanitize_text_field(wp_unslash($_POST['arweave_address'])) : '';
    $record_json = isset($_POST['record']) ? wp_unslash($_POST['record']) : '';

    if (empty($tx_id) || empty($evm_address) || empty($arweave_address)) {
        wp_send_json_error('Missing identity link fields');
        return;
    }

    $record = json_decode($record_json, true);

    update_option('clkt_identity_link', array(
        'tx_id' => $tx_id,
        'evm_address' => $evm_address,
        'arweave_address' => $arweave_address,
        'record' => is_array($record) ? $record : null,
        'updated_at' => current_time('mysql', true),
    ));

    wp_send_json_success(array(
        'tx_id' => $tx_id,
        'evm_address' => $evm_address,
        'arweave_address' => $arweave_address,
    ));
}
add_action('wp_ajax_clkt_save_identity_link', 'clkt_save_identity_link');

/**
 * Securely retrieve global encryption password
 * Requires authentication and nonce verification
 */
function clkt_get_encryption_password()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $password = get_option('clkt_global_encryption_password', '');
    wp_send_json_success(['password' => $password]);
}
add_action('wp_ajax_clkt_get_encryption_password', 'clkt_get_encryption_password');

/**
 * Check if a transaction ID is valid for current mode
 * In production mode: Ignore TEST_ transaction IDs
 * In test mode: Accept all transaction IDs
 *
 * @param string $tx_id The transaction ID to check
 * @return bool True if valid for current mode
 */
function clkt_is_valid_tx_id($tx_id)
{
    if (empty($tx_id)) {
        return false;
    }

    // Check if it's a test transaction
    $is_test_tx = (strpos($tx_id, 'TEST_') === 0);

    // If not test mode and this is a test transaction, reject it
    $test_mode_enabled = get_option('clkt_test_mode', false);
    if (!$test_mode_enabled && $is_test_tx) {
        return false;
    }

    // If test mode is enabled and this is NOT a test transaction, reject it
    if ($test_mode_enabled && !$is_test_tx) {
        return false;
    }

    return true;
}

/**
 * Calculate actual bundle size for a post (returns detailed breakdown)
 * Attachments are uploaded separately, so we return both post size and attachment info
 */
function clkt_calculate_post_bundle_size($post_id, $upload_type = 'all_posts', $editor_content = null, $editor_featured_media = null, $editor_attachment_ids = null)
{
    $post = get_post($post_id);
    if (!$post) {
        return array(
            'post_size' => 0,
            'attachments' => array(),
            'total_attachment_size' => 0,
            'will_cost_money' => false
        );
    }

    // Use editor content if provided, otherwise use saved content
    $content_to_use = ($editor_content !== null) ? $editor_content : $post->post_content;

    // Start with post data that would be in the bundle
    $bundle_data = array(
        'id' => $post_id,
        'title' => get_the_title($post_id),
        'content' => $content_to_use,
        'excerpt' => get_the_excerpt($post_id),
        'url' => get_permalink($post_id),
        'date' => $post->post_date,
        'author' => get_the_author_meta('display_name', $post->post_author),
        'categories' => wp_get_post_categories($post_id, array('fields' => 'names')),
        'tags' => wp_get_post_tags($post_id, array('fields' => 'names'))
    );

    // Calculate JSON size of the post data
    $json_size = strlen(json_encode($bundle_data));

    // Add realistic overhead for atomic asset structure
    // - Arweave tags: ~1-2KB
    // - Atomic asset metadata wrapper: ~500 bytes
    // - JSON formatting overhead: ~10% of content size
    $overhead = 2048 + ($json_size * 0.1);
    $post_size = $json_size + $overhead;

    // Get the max upload size setting (in KB, convert to bytes)
    $max_free_size_kb = intval(get_option('clkt_free_dispatch_limit_kb', 100));
    $max_free_size_bytes = $max_free_size_kb * 1024;

    // Collect attachment info (uploaded as separate transactions)
    $attachments = array();
    $total_attachment_size = 0;
    $will_cost_money = ($post_size >= $max_free_size_bytes); // Post itself costs money if over limit
    $processed_attachment_ids = array(); // Track to prevent duplicates

    // In new_posts_only mode, skip attachments that already have Arweave metadata
    $skip_uploaded_attachments = ($upload_type === 'new_posts_only');

    // Debug info array to track attachment skipping
    $debug_attachments = array();

    // Featured image - use editor state if provided
    $featured_image_id = ($editor_featured_media !== null && $editor_featured_media > 0)
        ? $editor_featured_media
        : get_post_thumbnail_id($post_id);

    if ($featured_image_id) {
        // Skip if in new_posts_only mode and attachment already has Arweave metadata
        $should_skip = false;
        if ($skip_uploaded_attachments) {
            $existing_tx_id = get_post_meta($featured_image_id, '_clkt_current_tx_id', true);
            $existing_hash = get_post_meta($featured_image_id, '_clkt_content_hash', true);

            // Validate transaction ID (ignore TEST_ IDs in production mode)
            $tx_id_valid = clkt_is_valid_tx_id($existing_tx_id);

            $debug_attachments[] = array(
                'id' => $featured_image_id,
                'type' => 'featured_image',
                'has_tx_id' => !empty($existing_tx_id),
                'tx_id_valid' => $tx_id_valid,
                'has_hash' => !empty($existing_hash),
                'tx_id' => $existing_tx_id,
                'hash' => $existing_hash ? substr($existing_hash, 0, 10) . '...' : null
            );
            if ($tx_id_valid && !empty($existing_hash)) {
                $should_skip = true;
                $debug_attachments[count($debug_attachments) - 1]['skipped'] = true;
            } else {
                $debug_attachments[count($debug_attachments) - 1]['skipped'] = false;
            }
        }

        $file_path = get_attached_file($featured_image_id);
        if ($file_path && file_exists($file_path)) {
            $raw_size = filesize($file_path);

            // Add Arweave transaction overhead for accurate size estimate
            // Each attachment is uploaded as a separate transaction with:
            // - Transaction signature: ~512 bytes
            // - Arweave tags (content-type, filename, etc.): ~500-1000 bytes
            // - Transaction structure overhead: ~500 bytes
            $arweave_tx_overhead = 1536; // ~1.5 KB conservative estimate
            $size = $raw_size + $arweave_tx_overhead;

            // Calculate cost only if attachment will cost money (over free size limit with overhead)
            $estimated_ar_cost = 0;
            $estimated_usd_cost = 0;
            $is_free = $size < $max_free_size_bytes;

            if (!$is_free) {
                $size_mb = $size / (1024 * 1024);
                $estimated_ar_cost = $size_mb * 0.01;
                $ar_price_usd = 5.72;
                $estimated_usd_cost = $estimated_ar_cost * $ar_price_usd;
            }

            $attachment_info = array(
                'id' => $featured_image_id,
                'type' => 'featured_image',
                'title' => get_the_title($featured_image_id),
                'filename' => basename($file_path),
                'mime_type' => get_post_mime_type($featured_image_id),
                'url' => wp_get_attachment_url($featured_image_id),
                'size' => $size,
                'free' => $is_free,
                'within_limit' => $size <= $max_free_size_bytes,
                'skipped' => $should_skip,
                'cost' => array(
                    'ar' => $estimated_ar_cost,
                    'usd' => $estimated_usd_cost,
                    'free' => $is_free
                )
            );

            if ($should_skip) {
                // Add reuse info
                $attachment_info['arweave_tx_id'] = $existing_tx_id;
                $attachment_info['arweave_url'] = 'https://arweave.net/' . $existing_tx_id;
                $attachment_info['reusing_existing'] = true;
            }

            $attachments[] = $attachment_info;

            if (!$should_skip) {
                $total_attachment_size += $size;
                if (!$is_free)
                    $will_cost_money = true;
            }

            $processed_attachment_ids[] = $featured_image_id;
        }
    }

    // Content images - use editor attachment IDs if provided, otherwise extract from content
    $content_attachment_ids = array();
    if ($editor_attachment_ids !== null && is_array($editor_attachment_ids)) {
        // Use directly provided attachment IDs from editor
        $content_attachment_ids = $editor_attachment_ids;
    } else {
        // Fall back to regex extraction from content
        preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/', $content_to_use, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $img_url) {
                $attachment_id = attachment_url_to_postid($img_url);

                // If attachment_url_to_postid fails, try matching by filename (for resized images)
                if (!$attachment_id) {
                    // Extract filename from URL
                    $filename = basename($img_url);
                    // Remove size suffix (e.g., -300x200) to get original filename
                    $filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $filename);

                    // Search for attachment by filename
                    global $wpdb;
                    $attachment_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                        '%' . $wpdb->esc_like($filename)
                    ));
                }

                if ($attachment_id) {
                    $content_attachment_ids[] = $attachment_id;
                }
            }
        }
    }

    // Process content attachments
    if (!empty($content_attachment_ids)) {
        foreach ($content_attachment_ids as $attachment_id) {
            // Skip if already processed (e.g., as featured image)
            if (in_array($attachment_id, $processed_attachment_ids)) {
                continue;
            }
                // Skip if in new_posts_only mode and attachment already has Arweave metadata
                $should_skip = false;
                if ($skip_uploaded_attachments) {
                    $existing_tx_id = get_post_meta($attachment_id, '_clkt_current_tx_id', true);
                    $existing_hash = get_post_meta($attachment_id, '_clkt_content_hash', true);

                    // Validate transaction ID (ignore TEST_ IDs in production mode)
                    $tx_id_valid = clkt_is_valid_tx_id($existing_tx_id);

                    $file_path_temp = get_attached_file($attachment_id);
                    $debug_attachments[] = array(
                        'id' => $attachment_id,
                        'filename' => basename($file_path_temp),
                        'has_tx_id' => !empty($existing_tx_id),
                        'tx_id_valid' => $tx_id_valid,
                        'has_hash' => !empty($existing_hash),
                        'tx_id' => $existing_tx_id ?: null,
                        'hash' => $existing_hash ? substr($existing_hash, 0, 10) . '...' : null
                    );
                    if ($tx_id_valid && !empty($existing_hash)) {
                        $should_skip = true;
                        $debug_attachments[count($debug_attachments) - 1]['skipped'] = true;
                    } else {
                        $debug_attachments[count($debug_attachments) - 1]['skipped'] = false;
                    }
                }

                // ALWAYS get the original full-size file, not the resized version
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $raw_size = filesize($file_path);

                    // Add Arweave transaction overhead for accurate size estimate
                    $arweave_tx_overhead = 1536; // ~1.5 KB
                    $size = $raw_size + $arweave_tx_overhead;

                    // Calculate cost only if attachment will cost money (over free size limit with overhead)
                    $estimated_ar_cost = 0;
                    $estimated_usd_cost = 0;
                    $is_free = $size < $max_free_size_bytes;

                    if (!$is_free) {
                        $size_mb = $size / (1024 * 1024);
                        $estimated_ar_cost = $size_mb * 0.01;
                        $ar_price_usd = 5.72;
                        $estimated_usd_cost = $estimated_ar_cost * $ar_price_usd;
                    }

                    $attachment_info = array(
                        'id' => $attachment_id,
                        'type' => 'content_image',
                        'title' => get_the_title($attachment_id),
                        'filename' => basename($file_path),
                        'mime_type' => get_post_mime_type($attachment_id),
                        'url' => wp_get_attachment_url($attachment_id),
                        'size' => $size,
                        'free' => $is_free,
                        'within_limit' => $size <= $max_free_size_bytes,
                        'skipped' => $should_skip,
                        'cost' => array(
                            'ar' => $estimated_ar_cost,
                            'usd' => $estimated_usd_cost,
                            'free' => $is_free
                        )
                    );

                    if ($should_skip) {
                        $attachment_info['arweave_tx_id'] = $existing_tx_id;
                        $attachment_info['arweave_url'] = 'https://arweave.net/' . $existing_tx_id;
                        $attachment_info['reusing_existing'] = true;
                    }

                    $attachments[] = $attachment_info;

                    if (!$should_skip) {
                        $total_attachment_size += $size;
                        if ($size >= 100 * 1024)
                            $will_cost_money = true;
                    }

                    $processed_attachment_ids[] = $attachment_id;
                }
        }
    }

    // Content attachments (PDFs, docs, images, media files, etc)
    // This catches files linked with <a href="..."> tags (not <img> tags which are handled above)
    preg_match_all('/href=[\'"]([^\'"]+\.(pdf|doc|docx|xls|xlsx|zip|mp3|mp4|mov|avi|webm|gif|png|jpg|jpeg|webp|svg))[\'"]/', $post->post_content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $file_url) {
            $attachment_id = attachment_url_to_postid($file_url);

            // If attachment_url_to_postid fails, try matching by filename
            if (!$attachment_id) {
                // Extract filename from URL (remove query params)
                $url_parts = wp_parse_url($file_url);
                $filename = basename($url_parts['path']);

                // Search for attachment by filename
                global $wpdb;
                $attachment_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                    '%' . $wpdb->esc_like($filename)
                ));
            }

            if ($attachment_id && !in_array($attachment_id, $processed_attachment_ids)) {
                // Skip if in new_posts_only mode and attachment already has Arweave metadata
                $should_skip = false;
                if ($skip_uploaded_attachments) {
                    $existing_tx_id = get_post_meta($attachment_id, '_clkt_current_tx_id', true);
                    $existing_hash = get_post_meta($attachment_id, '_clkt_content_hash', true);

                    // Validate transaction ID (ignore TEST_ IDs in production mode)
                    $tx_id_valid = clkt_is_valid_tx_id($existing_tx_id);

                    $file_path_temp = get_attached_file($attachment_id);
                    $debug_attachments[] = array(
                        'id' => $attachment_id,
                        'filename' => basename($file_path_temp),
                        'has_tx_id' => !empty($existing_tx_id),
                        'tx_id_valid' => $tx_id_valid,
                        'has_hash' => !empty($existing_hash),
                        'tx_id' => $existing_tx_id ?: null,
                        'hash' => $existing_hash ? substr($existing_hash, 0, 10) . '...' : null
                    );
                    if ($tx_id_valid && !empty($existing_hash)) {
                        $should_skip = true;
                        $debug_attachments[count($debug_attachments) - 1]['skipped'] = true;
                    } else {
                        $debug_attachments[count($debug_attachments) - 1]['skipped'] = false;
                    }
                }

                // ALWAYS get the original full-size file
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $raw_size = filesize($file_path);

                    // Add Arweave transaction overhead for accurate size estimate
                    $arweave_tx_overhead = 1536; // ~1.5 KB
                    $size = $raw_size + $arweave_tx_overhead;

                    // Calculate cost only if attachment will cost money (over free size limit with overhead)
                    $estimated_ar_cost = 0;
                    $estimated_usd_cost = 0;
                    $is_free = $size < $max_free_size_bytes;

                    if (!$is_free) {
                        $size_mb = $size / (1024 * 1024);
                        $estimated_ar_cost = $size_mb * 0.01;
                        $ar_price_usd = 5.72;
                        $estimated_usd_cost = $estimated_ar_cost * $ar_price_usd;
                    }

                    $attachment_info = array(
                        'id' => $attachment_id,
                        'type' => 'attachment',
                        'title' => get_the_title($attachment_id),
                        'filename' => basename($file_path),
                        'mime_type' => get_post_mime_type($attachment_id),
                        'url' => wp_get_attachment_url($attachment_id),
                        'size' => $size,
                        'free' => $is_free,
                        'within_limit' => $size <= $max_free_size_bytes,
                        'skipped' => $should_skip,
                        'cost' => array(
                            'ar' => $estimated_ar_cost,
                            'usd' => $estimated_usd_cost,
                            'free' => $is_free
                        )
                    );

                    if ($should_skip) {
                        $attachment_info['arweave_tx_id'] = $existing_tx_id;
                        $attachment_info['arweave_url'] = 'https://arweave.net/' . $existing_tx_id;
                        $attachment_info['reusing_existing'] = true;
                    }

                    $attachments[] = $attachment_info;

                    if (!$should_skip) {
                        $total_attachment_size += $size;
                        if (!$is_free)
                            $will_cost_money = true;
                    }

                    $processed_attachment_ids[] = $attachment_id;
                }
            }
        }
    }

    return array(
        'post_size' => $post_size,
        'attachments' => $attachments,
        'total_attachment_size' => $total_attachment_size,
        'attachment_count' => count($attachments),
        'will_cost_money' => $will_cost_money,
        'debug_attachments' => $debug_attachments  // Debug info for attachment skipping
    );
}

/**
 * Format a post object into a data array compatible with the REST API response format.
 * Replaces direct use of WP_REST_Posts_Controller which requires including core files.
 *
 * @param WP_Post $post Post object
 * @return array Formatted post data
 */
function clkt_format_post_data($post)
{
    $post_id = $post->ID;

    return array(
        'id'             => $post_id,
        'date'           => $post->post_date,
        'date_gmt'       => $post->post_date_gmt,
        'modified'       => $post->post_modified,
        'modified_gmt'   => $post->post_modified_gmt,
        'slug'           => $post->post_name,
        'status'         => $post->post_status,
        'type'           => $post->post_type,
        'link'           => get_permalink($post_id),
        'title'          => array('rendered' => get_the_title($post_id), 'raw' => $post->post_title),
        'content'        => array('rendered' => apply_filters('the_content', $post->post_content), 'raw' => $post->post_content),
        'excerpt'        => array('rendered' => has_excerpt($post_id) ? apply_filters('the_excerpt', $post->post_excerpt) : '', 'raw' => $post->post_excerpt),
        'author'         => (int) $post->post_author,
        'featured_media' => (int) get_post_thumbnail_id($post_id),
        'categories'     => wp_get_post_categories($post_id),
        'tags'           => wp_get_post_tags($post_id, array('fields' => 'ids')),
        'meta'           => clkt_get_post_meta_for_upload($post_id),
    );
}

function clkt_get_post_meta_for_upload($post_id)
{
    $meta = array(
        '_clkt_arweave_versions' => get_post_meta($post_id, '_clkt_arweave_versions', true),
        '_clkt_current_tx_id'    => get_post_meta($post_id, '_clkt_current_tx_id', true),
        '_clkt_encrypt_content'  => get_post_meta($post_id, '_clkt_encrypt_content', true),
        '_clkt_encryption_method' => get_post_meta($post_id, '_clkt_encryption_method', true),
        '_clkt_per_post_password' => get_post_meta($post_id, '_clkt_per_post_password', true),
    );

    $license = get_post_meta($post_id, '_clkt_license', true);
    if (!empty($license) && is_array($license)) {
        $meta['_clkt_license'] = array_intersect_key($license, array_flip(array('spdx', 'url', 'note')));
    }

    $derived_from = get_post_meta($post_id, '_clkt_derived_from', true);
    if (!empty($derived_from) && is_array($derived_from)) {
        $meta['_clkt_derived_from'] = $derived_from;
    }

    return $meta;
}

/**
 * Get post data for upload (extracted from clkt_get_post_data for reuse)
 * Returns the same data structure that JavaScript receives
 */
function clkt_get_post_data_for_upload($post_id)
{
    $post = get_post($post_id);

    if (!$post) {
        return null;
    }

    $data = clkt_format_post_data($post);

    // Enhance with attachment information for CLKT processing
    $data['clkt_attachments'] = clkt_get_post_attachments($post_id);

    return $data;
}

/**
 * Handle bulk upload preview AJAX request
 */
function clkt_preview_bulk_upload()
{
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'clkt_ajax_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $upload_type = sanitize_text_field(wp_unslash($_POST['upload_type']));
    $date_filter = sanitize_text_field(wp_unslash($_POST['date_filter']));
    $category_filter = intval($_POST['category_filter']);
    $author_filter = intval($_POST['author_filter']);

    // Debug logging


    // Build query args
    $query_args = array(
        'post_type' => clkt_get_supported_post_types(),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'ASC'
    );

    $result = array(
        'posts' => array(),
        'attachments' => array(),
        'skipped_posts' => array(
            'never_upload' => array(),
            'already_uploaded' => array()
        ),
        'debug' => array(
            'upload_type' => $upload_type,
            'test_mode' => get_option('clkt_test_mode'),
            'query_args' => $query_args,
            'post_checks' => array(),
            'suspicious_skips' => array()
        )
    );

    // Apply date filter
    switch ($date_filter) {
        case 'last_month':
            $query_args['date_query'] = array(
                array(
                    'after' => '1 month ago'
                )
            );
            break;
        case 'last_3_months':
            $query_args['date_query'] = array(
                array(
                    'after' => '3 months ago'
                )
            );
            break;
        case 'last_6_months':
            $query_args['date_query'] = array(
                array(
                    'after' => '6 months ago'
                )
            );
            break;
        case 'last_year':
            $query_args['date_query'] = array(
                array(
                    'after' => '1 year ago'
                )
            );
            break;
    }

    // Apply category filter
    if ($category_filter) {
        $query_args['cat'] = $category_filter;
    }

    // Apply author filter
    if ($author_filter) {
        $query_args['author'] = $author_filter;
    }

    // Get posts based on upload type
    if ($upload_type !== 'attachments_only') {
        $posts_query = new WP_Query($query_args);

        foreach ($posts_query->posts as $post) {
            $include_post = false;

            // ALWAYS check per-post setting first - if set to 'never', exclude from bulk upload
            $per_post_setting = get_post_meta($post->ID, '_clkt_per_post_save', true);
            if ($per_post_setting === 'never') {
                // Track this as a skipped post
                $result['skipped_posts']['never_upload'][] = array(
                    'id' => $post->ID,
                    'title' => get_the_title($post->ID),
                    'edit_url' => get_edit_post_link($post->ID),
                    'reason' => 'Set to "Never upload" in individual post settings'
                );
                continue;
            }

            if ($upload_type === 'all_posts' || $upload_type === 'date_range' || $upload_type === 'category') {
                // Include all posts for re-upload, date range, or category filters
                $include_post = true;
            } else if ($upload_type === 'new_posts_only') {
                // Check if current version has been uploaded
                // Use the same approach as the edit screen: check if current content has a matching version

                // Get all versions for this post
                $versions = get_post_meta($post->ID, '_clkt_arweave_versions', true);

                // Debug variables
                $latest_version_time = 0;
                $has_valid_version = false;
                $post_modified_time = 0;

                if (empty($versions) || !is_array($versions)) {
                    // No versions at all = needs upload
                    $include_post = true;
                } else {
                    // Find the most recent VALID (non-TEST_ in production) version
                    foreach ($versions as $version) {
                        // Check all possible key variations for transaction ID
                        $version_tx_id = '';
                        if (!empty($version['transactionId'])) {
                            $version_tx_id = $version['transactionId'];
                        } elseif (!empty($version['tx_id'])) {
                            $version_tx_id = $version['tx_id'];
                        } elseif (!empty($version['id'])) {
                            $version_tx_id = $version['id'];
                        }

                        // Only consider versions with valid transaction IDs
                        if (clkt_is_valid_tx_id($version_tx_id)) {
                            $has_valid_version = true;
                            if (isset($version['timestamp']) && $version['timestamp'] > $latest_version_time) {
                                $latest_version_time = $version['timestamp'];
                            }
                        }
                    }

                    // If no valid versions found, needs upload
                    if (!$has_valid_version) {
                        $include_post = true;
                    } else {
                        // Get post modified time as timestamp
                        $post_modified_gmt = !empty($post->post_modified_gmt) && $post->post_modified_gmt !== '0000-00-00 00:00:00'
                            ? $post->post_modified_gmt
                            : $post->post_modified;

                        $post_modified_time = strtotime($post_modified_gmt . ' UTC');

                        // If strtotime failed or no version timestamp, include the post
                        if ($post_modified_time === false || $latest_version_time === 0) {
                            $include_post = true;
                        } else if ($post_modified_time > ($latest_version_time + 5)) {
                            // Post has been modified since last upload (with 5 second buffer)
                            $include_post = true;
                        } else {
                            // Post hasn't been modified since last upload
                            // BUT: check if any attachments have failed/never uploaded
                            $has_failed_attachments = false;

                            // Check featured image
                            $featured_image_id = get_post_thumbnail_id($post->ID);
                            if ($featured_image_id) {
                                $attachment_tx_id = get_post_meta($featured_image_id, '_clkt_current_tx_id', true);
                                if (!clkt_is_valid_tx_id($attachment_tx_id)) {
                                    $has_failed_attachments = true;
                                }
                            }

                            // Check content images if no failed attachment found yet
                            if (!$has_failed_attachments) {
                                preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $post->post_content, $matches);
                                if (!empty($matches[1])) {
                                    foreach ($matches[1] as $img_url) {
                                        $attachment_id = attachment_url_to_postid($img_url);
                                        if ($attachment_id) {
                                            $attachment_tx_id = get_post_meta($attachment_id, '_clkt_current_tx_id', true);
                                            if (!clkt_is_valid_tx_id($attachment_tx_id)) {
                                                $has_failed_attachments = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }

                            if ($has_failed_attachments) {
                                // Include post because it has failed/never-uploaded attachments
                                $include_post = true;
                            } else {
                                // Post and all attachments successfully uploaded
                                $include_post = false;
                                $result['skipped_posts']['already_uploaded'][] = array(
                                    'id' => $post->ID,
                                    'title' => get_the_title($post->ID),
                                    'edit_url' => get_edit_post_link($post->ID),
                                    'reason' => 'Current version already uploaded to Arweave'
                                );
                            }
                        }
                    }
                }

                // Debug logging for first 5 posts
                if (count($result['debug']['post_checks']) < 5) {
                    $check_data = array(
                        'id' => $post->ID,
                        'title' => get_the_title($post->ID),
                        'versions_count' => is_array($versions) ? count($versions) : 0,
                        'has_valid_version' => $has_valid_version,
                        'latest_version_time' => $latest_version_time,
                        'post_modified_time' => $post_modified_time,
                        'include_post' => $include_post
                    );

                    $result['debug']['post_checks'][] = $check_data;
                }

                // Check for suspicious skips (skipped but only has TEST versions)
                if (!$include_post && !empty($versions) && is_array($versions)) {
                    $only_test_versions = true;
                    foreach ($versions as $version) {
                        $v_tx_id = '';
                        if (!empty($version['transactionId'])) {
                            $v_tx_id = $version['transactionId'];
                        } elseif (!empty($version['tx_id'])) {
                            $v_tx_id = $version['tx_id'];
                        } elseif (!empty($version['id'])) {
                            $v_tx_id = $version['id'];
                        }

                        if (strpos($v_tx_id, 'TEST_') !== 0) {
                            $only_test_versions = false;
                            break;
                        }
                    }

                    if ($only_test_versions) {
                        $result['debug']['suspicious_skips'][] = array(
                            'id' => $post->ID,
                            'title' => get_the_title($post->ID),
                            'versions' => $versions
                        );
                    }
                }


            }

            if ($include_post) {
                // Use the accurate bundle size calculation (returns array with details)
                $size_info = clkt_calculate_post_bundle_size($post->ID, $upload_type);

                // Get some metadata for display
                $title = get_the_title($post->ID);
                $content_length = strlen($post->post_content);

                // Calculate estimated cost only if POST itself is over 100KB
                $estimated_ar_cost = 0;
                $estimated_usd_cost = 0;

                if ($size_info['post_size'] >= 100 * 1024) {
                    // Arweave typically costs around 0.01 AR per MB (rough estimate)
                    $size_mb = $size_info['post_size'] / (1024 * 1024);
                    $estimated_ar_cost = $size_mb * 0.01;

                    // Get AR price (simplified - in real system would fetch from price oracle)
                    $ar_price_usd = 5.72; // This should be fetched dynamically
                    $estimated_usd_cost = $estimated_ar_cost * $ar_price_usd;
                }

                $result['posts'][] = array(
                    'type' => $post->post_type,
                    'type_label' => get_post_type_object($post->post_type) ? get_post_type_object($post->post_type)->labels->singular_name : $post->post_type,
                    'id' => $post->ID,
                    'title' => $title,
                    'date' => $post->post_date,
                    'author' => get_the_author_meta('display_name', $post->post_author),
                    'permalink' => get_permalink($post->ID),
                    'edit_url' => get_edit_post_link($post->ID),
                    'size' => $size_info['post_size'],
                    'content_length' => $content_length,
                    'media_count' => $size_info['attachment_count'],
                    'attachments' => $size_info['attachments'],
                    'total_attachment_size' => $size_info['total_attachment_size'],
                    'will_cost_money' => $size_info['will_cost_money'],
                    'bundle_size_formatted' => size_format($size_info['post_size'], 2),
                    'cost' => array(
                        'ar' => $estimated_ar_cost,
                        'usd' => $estimated_usd_cost,
                        'free' => $size_info['post_size'] < 100 * 1024 // Free if post itself is under 100KB
                    ),
                    'debug_attachments' => $size_info['debug_attachments'] ?? array()  // Debug info
                );
            }
        }
    }

    // Get media library items if requested
    if ($upload_type === 'attachments_only' || $upload_type === 'posts_and_attachments') {
        $attachment_args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        );

        // Apply date filter to attachments
        if ($date_filter !== 'all') {
            $attachment_args['date_query'] = $query_args['date_query'] ?? array();
        }

        // Apply author filter to attachments
        if ($author_filter) {
            $attachment_args['author'] = $author_filter;
        }

        $attachments_query = new WP_Query($attachment_args);

        foreach ($attachments_query->posts as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            $raw_size = filesize($file_path);

            // Add Arweave transaction overhead for accurate size estimate
            $arweave_tx_overhead = 1536; // ~1.5 KB
            $file_size = $raw_size + $arweave_tx_overhead;

            // Calculate cost
            $estimated_ar_cost = 0;
            $estimated_usd_cost = 0;
            $is_free = true;

            if ($file_size >= 100 * 1024) {
                $size_mb = $file_size / (1024 * 1024);
                $estimated_ar_cost = $size_mb * 0.01;
                $ar_price_usd = 5.72;
                $estimated_usd_cost = $estimated_ar_cost * $ar_price_usd;
                $is_free = false;
            }

            // Only include small files for free upload via browser wallet
            if ($file_size <= 100000) {
                $result['attachments'][] = array(
                    'type' => 'attachment',
                    'id' => $attachment->ID,
                    'title' => get_the_title($attachment->ID),
                    'filename' => basename($file_path),
                    'mime_type' => get_post_mime_type($attachment->ID),
                    'size' => $file_size,
                    'date' => $attachment->post_date,
                    'url' => wp_get_attachment_url($attachment->ID),
                    'cost' => array(
                        'ar' => $estimated_ar_cost,
                        'usd' => $estimated_usd_cost,
                        'free' => $is_free
                    )
                );
            }
        }
    }

    // Calculate summary totals
    $total_size = 0;
    $total_ar_cost = 0;
    $total_usd_cost = 0;
    $signature_count = 0;
    $has_paid_items = false;

    // Sum up post sizes and costs
    foreach ($result['posts'] as $post) {
        $total_size += $post['size'] ?? 0;
        $total_ar_cost += $post['cost']['ar'] ?? 0;
        $total_usd_cost += $post['cost']['usd'] ?? 0;
        $signature_count++; // Each post requires a signature

        // Check if any post costs money
        if (!empty($post['cost']['free']) && $post['cost']['free'] === false) {
            $has_paid_items = true;
        }

        // Sum up attachment sizes and costs (only non-skipped ones)
        if (!empty($post['attachments'])) {
            foreach ($post['attachments'] as $attachment) {
                if (empty($attachment['skipped'])) {
                    $total_size += $attachment['size'] ?? 0;
                    $total_ar_cost += $attachment['cost']['ar'] ?? 0;
                    $total_usd_cost += $attachment['cost']['usd'] ?? 0;
                    $signature_count++; // Each attachment requires a signature

                    if (!empty($attachment['cost']['free']) && $attachment['cost']['free'] === false) {
                        $has_paid_items = true;
                    }
                }
            }
        }
    }

    // Sum up standalone attachments
    foreach ($result['attachments'] as $attachment) {
        $total_size += $attachment['size'] ?? 0;
        $total_ar_cost += $attachment['cost']['ar'] ?? 0;
        $total_usd_cost += $attachment['cost']['usd'] ?? 0;
        $signature_count++;

        if (!empty($attachment['cost']['free']) && $attachment['cost']['free'] === false) {
            $has_paid_items = true;
        }
    }

    // Add summary
    $result['summary'] = array(
        'totalSize' => $total_size,
        'cost' => array(
            'ar' => $total_ar_cost,
            'usd' => $total_usd_cost,
            'free' => !$has_paid_items && $total_size < 100 * 1024 // Free if under 100KB total and no paid items
        ),
        'signatureCount' => $signature_count,
        'debug' => array(
            'posts_processed' => count($result['posts']),
            'attachments_processed' => count($result['attachments']),
            'total_size_calculated' => $total_size,
            'has_paid_items' => $has_paid_items
        )
    );

    // Add debug info
    // Add debug info
    $result['debug'] = array_merge($result['debug'], array(
        'upload_type' => $upload_type,
        'total_posts_in_db' => wp_count_posts('post')->publish,
        'posts_with_versions' => 0,
        'posts_without_versions' => 0,
        'summary_calculation' => array(
            'posts_in_result' => count($result['posts']),
            'attachments_in_result' => count($result['attachments']),
            'calculated_total_size' => $total_size,
            'calculated_signature_count' => $signature_count,
            'has_paid_items' => $has_paid_items
        )
    ));

    // Quick count of posts with/without versions
    $all_posts_check = get_posts(array(
        'post_type' => clkt_get_supported_post_types(),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));

    foreach ($all_posts_check as $post_id) {
        $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
        if (!empty($versions) && is_array($versions)) {
            $result['debug']['posts_with_versions']++;
        } else {
            $result['debug']['posts_without_versions']++;
        }
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_clkt_preview_bulk_upload', 'clkt_preview_bulk_upload');
// add_action('wp_ajax_nopriv_clkt_bulk_upload', 'clkt_preview_bulk_upload'); // Disabled for security

// Temporary debug endpoint to create a post
add_action('wp_ajax_clkt_create_debug_post', 'clkt_create_debug_post');

function clkt_create_debug_post()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $post_id = wp_insert_post(array(
        'post_title' => 'Antigravity Debug Post ' . time(),
        'post_content' => 'This is a test post for debugging bulk upload.',
        'post_status' => 'publish',
        'post_author' => 1
    ));

    if (is_wp_error($post_id)) {
        wp_send_json_error($post_id->get_error_message());
    } else {
        $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
        wp_send_json_success(array(
            'post_id' => $post_id,
            'versions_count' => is_array($versions) ? count($versions) : 0
        ));
    }
}

add_action('wp_ajax_clkt_add_test_version', 'clkt_add_test_version');

function clkt_add_test_version()
{
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'clkt_ajax_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $post_id = intval($_POST['post_id']);
    if (!$post_id) {
        wp_send_json_error('Missing post_id');
    }

    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }

    // Add a TEST version with current timestamp
    $versions[] = array(
        'transactionId' => 'TEST_' . time(),
        'timestamp' => time(),
        'status' => 'success'
    );

    update_post_meta($post_id, '_clkt_arweave_versions', $versions);

    wp_send_json_success(array(
        'post_id' => $post_id,
        'versions_count' => count($versions)
    ));
}


// AJAX handler for saving per-post settings from the dropdown
add_action('wp_ajax_clkt_save_per_post_setting', 'clkt_save_per_post_setting');
add_action('wp_ajax_clkt_save_encryption_settings', 'clkt_save_encryption_settings');
function clkt_save_per_post_setting()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $setting = sanitize_text_field(wp_unslash($_POST['setting']));
    if (in_array($setting, ['default', 'always', 'never'])) {
        update_post_meta($post_id, '_clkt_per_post_save', $setting);
    }

    wp_send_json_success(array('setting' => $setting));
}

function clkt_save_encryption_settings()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $encrypt_content = isset($_POST['encrypt_content']) ? sanitize_text_field(wp_unslash($_POST['encrypt_content'])) : '0';
    $encryption_method = sanitize_text_field(wp_unslash($_POST['encryption_method']));
    $per_post_password = isset($_POST['per_post_password']) ? sanitize_text_field(wp_unslash($_POST['per_post_password'])) : '';

    update_post_meta($post_id, '_clkt_encrypt_content', $encrypt_content);
    update_post_meta($post_id, '_clkt_encryption_method', $encryption_method);

    // SECURITY WARNING: Password stored in plaintext for client-side encryption
    // This is a known limitation of client-side encryption architecture.
    // For maximum security, users should:
    // 1. Use wallet_key encryption method (most secure)
    // 2. NOT store truly sensitive data that requires password encryption
    // Future: Implement client-side-only password storage (localStorage) or prompt-based entry
    update_post_meta($post_id, '_clkt_per_post_password', $per_post_password);

    wp_send_json_success();
}

add_action('wp_ajax_clkt_save_attribution', 'clkt_save_attribution');
function clkt_save_attribution()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!isset($_POST['post_id'])) {
        wp_send_json_error('Missing post_id');
        return;
    }
    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $license_spdx = isset($_POST['license_spdx']) ? sanitize_text_field(wp_unslash($_POST['license_spdx'])) : '';
    $license_url = isset($_POST['license_url']) ? esc_url_raw(wp_unslash($_POST['license_url'])) : '';
    $license_note = isset($_POST['license_note']) ? sanitize_text_field(wp_unslash($_POST['license_note'])) : '';

    if (!empty($license_spdx)) {
        $license = array('spdx' => $license_spdx);
        if (!empty($license_url)) {
            $license['url'] = $license_url;
        }
        if (!empty($license_note)) {
            $license['note'] = $license_note;
        }
        update_post_meta($post_id, '_clkt_license', $license);
    } else {
        delete_post_meta($post_id, '_clkt_license');
    }

    $derived_from_json = isset($_POST['derived_from']) ? sanitize_text_field(wp_unslash($_POST['derived_from'])) : '[]';
    $derived_from = json_decode($derived_from_json, true);

    if (is_array($derived_from) && !empty($derived_from)) {
        $sanitized = array();
        $allowed_relationships = array('citation', 'response', 'translation', 'remix', 'fork');
        foreach ($derived_from as $ref) {
            if (!is_array($ref) || empty($ref['relationship'])) {
                continue;
            }
            $relationship = sanitize_text_field($ref['relationship']);
            if (!in_array($relationship, $allowed_relationships)) {
                continue;
            }
            $entry = array('relationship' => $relationship);
            if (!empty($ref['title'])) {
                $entry['title'] = sanitize_text_field($ref['title']);
            }
            if (!empty($ref['author'])) {
                $entry['author'] = sanitize_text_field($ref['author']);
            }
            if (!empty($ref['url'])) {
                $entry['url'] = esc_url_raw($ref['url']);
            }
            if (!empty($ref['txId'])) {
                $entry['txId'] = sanitize_text_field($ref['txId']);
            }
            $sanitized[] = $entry;
        }
        if (!empty($sanitized)) {
            update_post_meta($post_id, '_clkt_derived_from', $sanitized);
        } else {
            delete_post_meta($post_id, '_clkt_derived_from');
        }
    } else {
        delete_post_meta($post_id, '_clkt_derived_from');
    }

    wp_send_json_success();
}

// AJAX handler for single post preview
add_action('wp_ajax_clkt_preview_single_post', 'clkt_preview_single_post');
function clkt_preview_single_post()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('Post not found');
        return;
    }

    // Get upload mode (default to 'new_only')
    $upload_mode = isset($_POST['upload_mode']) ? sanitize_text_field(wp_unslash($_POST['upload_mode'])) : 'new_only';

    // Get editor state if provided (for real-time preview before save)
    $editor_content = isset($_POST['editor_content']) ? wp_kses_post(wp_unslash($_POST['editor_content'])) : null;
    $editor_featured_media = isset($_POST['editor_featured_media']) ? intval($_POST['editor_featured_media']) : null;
    $editor_attachment_ids = isset($_POST['editor_attachment_ids']) ? array_map('absint', (array) $_POST['editor_attachment_ids']) : null;

    // Check if post has already been uploaded
    // Always check this, regardless of upload mode, so we can show correct state
    $post_already_uploaded = false;
    $latest_valid_version = null;

    // Clear cache to ensure we get fresh version data
    wp_cache_delete($post_id, 'post_meta');

    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!empty($versions) && is_array($versions)) {
        // Check if current content hash matches latest VALID version
        // Use editor content if provided (unsaved changes), otherwise use saved post
        if ($editor_content !== null) {
            // Calculate hash from editor content (unsaved draft)
            $hash_data = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $editor_content,
                'excerpt' => $post->post_excerpt
            );
            $json_string = json_encode($hash_data, JSON_UNESCAPED_UNICODE);
            $current_hash = hash('sha256', $json_string);
        } else {
            // Use saved post content
            $current_hash = clkt_calculate_post_content_hash($post);
        }

        // Find the most recent VALID version (non-TEST in production, TEST in test mode)
        $latest_timestamp = 0;

        foreach ($versions as $version) {
            // Check all possible key variations for transaction ID
            $version_tx_id = '';
            if (!empty($version['transactionId'])) {
                $version_tx_id = $version['transactionId'];
            } elseif (!empty($version['tx_id'])) {
                $version_tx_id = $version['tx_id'];
            } elseif (!empty($version['id'])) {
                $version_tx_id = $version['id'];
            }

            // Only consider versions with valid transaction IDs for current mode
            if (clkt_is_valid_tx_id($version_tx_id)) {
                $version_timestamp = isset($version['timestamp']) ? $version['timestamp'] : 0;
                if ($version_timestamp >= $latest_timestamp) {
                    $latest_timestamp = $version_timestamp;
                    $latest_valid_version = $version;
                }
            }
        }

        if ($latest_valid_version) {
            // Only check if latest valid version has a content_hash
            if (isset($latest_valid_version['content_hash']) && !empty($latest_valid_version['content_hash'])) {
                if ($current_hash === $latest_valid_version['content_hash']) {
                    $post_already_uploaded = true;
                }
            }
        }
    }

    // Use the same bundle size calculation as bulk upload, but with editor state if provided
    $size_info = clkt_calculate_post_bundle_size($post_id, 'all_posts', $editor_content, $editor_featured_media, $editor_attachment_ids);

    // Format attachments for UI display (matching bulk upload format)
    $formatted_attachments = array();
    foreach ($size_info['attachments'] as $att) {
        $attachment_post = get_post($att['id']);
        if ($attachment_post) {
            $file_path = get_attached_file($att['id']);

            // Check if attachment already uploaded (new_only mode)
            $attachment_already_uploaded = false;
            $existing_arweave_url = null;
            $attachment_tx_id = null;
            if ($upload_mode === 'new_only') {
                $attachment_tx_id = get_post_meta($att['id'], '_clkt_current_tx_id', true);
                $attachment_hash = get_post_meta($att['id'], '_clkt_content_hash', true);
                $attachment_title = get_the_title($att['id']);

                // Validate transaction ID (ignore TEST_ IDs in production mode)
                if (clkt_is_valid_tx_id($attachment_tx_id)) {
                    $attachment_already_uploaded = true;
                    $existing_arweave_url = 'https://arweave.net/' . $attachment_tx_id;
                }
            }
        }

        $formatted_attachments[] = array(
            'id' => $att['id'],
            'title' => get_the_title($att['id']),
            'filename' => basename($file_path),
            'name' => basename($file_path),
            'url' => wp_get_attachment_url($att['id']),  // WordPress file URL
            'size' => $att['size'],
            'type' => $att['type'],
            'free' => $att['free'],
            'within_limit' => $att['within_limit'],
            'cost' => $att['cost'],  // Include cost information
            'skipped' => $attachment_already_uploaded,
            'reusing_existing' => $attachment_already_uploaded,
            'arweave_tx_id' => $attachment_already_uploaded ? $attachment_tx_id : null,
            'arweave_url' => $existing_arweave_url
        );
    }

    // Build post data matching bulk upload format
    // Get max free size for consistency
    $max_free_size_kb = intval(get_option('clkt_free_dispatch_limit_kb', 100));
    $max_free_size_bytes = $max_free_size_kb * 1024;

    $post_data = array(
        'type' => $post->post_type,
        'type_label' => get_post_type_object($post->post_type) ? get_post_type_object($post->post_type)->labels->singular_name : $post->post_type,
        'id' => $post->ID,
        'title' => get_the_title($post_id),
        'date' => $post->post_date,
        'author' => get_the_author_meta('display_name', $post->post_author),
        'permalink' => get_permalink($post_id),
        'edit_url' => get_edit_post_link($post->ID),
        'size' => $size_info['post_size'],
        'attachments' => $formatted_attachments,  // Use formatted attachments with skipped flags
        'will_cost_money' => $size_info['will_cost_money'],
        'cost' => array(
            'ar' => 0,  // Will be calculated in summary
            'usd' => 0,  // Will be calculated in summary
            'free' => $size_info['post_size'] < $max_free_size_bytes
        )
    );

    // Handle different upload modes
    if ($post_already_uploaded && $upload_mode === 'new_only') {
        // In new_only mode, skip posts that are already uploaded
        $post_data['skipped'] = true;
        $post_data['reusing_existing'] = true;

        // Get the latest valid version's Arweave URL (use the one we found earlier)
        if (isset($latest_valid_version)) {
            $tx_id = $latest_valid_version['transactionId'] ?? ($latest_valid_version['tx_id'] ?? ($latest_valid_version['id'] ?? ''));
            if (!empty($tx_id)) {
                $post_data['arweave_url'] = 'https://arweave.net/' . $tx_id;
            }
        }

    } elseif ($post_already_uploaded && $upload_mode === 'force_reupload') {
        // In force_reupload mode, don't skip - but indicate it's currently uploaded
        $post_data['currently_uploaded'] = true;
        $post_data['force_reupload_enabled'] = true;

        // Get the latest valid version's Arweave URL (use the one we found earlier)
        if (isset($latest_valid_version)) {
            $tx_id = $latest_valid_version['transactionId'] ?? ($latest_valid_version['tx_id'] ?? ($latest_valid_version['id'] ?? ''));
            if (!empty($tx_id)) {
                $post_data['arweave_url'] = 'https://arweave.net/' . $tx_id;
            }
        }

    }

    // Calculate summary totals (matching bulk upload format)
    // In new_only mode: Only count post if not being skipped/reused
    // In force_reupload mode: Always count the post (even if already uploaded)
    $will_skip_post = ($post_already_uploaded && $upload_mode === 'new_only');
    $total_size = $will_skip_post ? 0 : ($post_data['size'] ?? 0);
    $total_ar_cost = 0;
    $total_usd_cost = 0;
    $signature_count = $will_skip_post ? 0 : 1; // The post itself requires a signature (unless skipped)
    $has_paid_items = $will_skip_post ? false : !empty($post_data['will_cost_money']);

    // Add attachment sizes and costs
    foreach ($formatted_attachments as $attachment) {
        if (empty($attachment['skipped'])) {
            $total_size += $attachment['size'] ?? 0;

            // Use pre-calculated cost from attachment (calculated in clkt_calculate_post_bundle_size)
            $att_is_free = !empty($attachment['free']);

            if (!$att_is_free) {
                $has_paid_items = true;
                // Use the cost that was already calculated correctly
                $att_ar_cost = $attachment['cost']['ar'] ?? 0;
                $att_usd_cost = $attachment['cost']['usd'] ?? 0;
            } else {
                $att_ar_cost = 0;
                $att_usd_cost = 0;
            }

            $total_ar_cost += $att_ar_cost;
            $total_usd_cost += $att_usd_cost;
            $signature_count++; // Each attachment requires a signature
        }
    }

    // Build summary object
    $summary = array(
        'totalSize' => $total_size,
        'cost' => array(
            'ar' => $total_ar_cost,
            'usd' => $total_usd_cost,
            'free' => !$has_paid_items && $total_size < $max_free_size_bytes
        ),
        'signatureCount' => $signature_count
    );

    // Return data in format expected by bulk upload UI (which our components expect)
    $response_data = array(
        'posts' => array($post_data),
        'attachments' => array(), // Attachments are inside post data, not separate
        'summary' => $summary,
        '_debug' => array(
            'function_called' => 'clkt_preview_single_post',  // MARKER TO IDENTIFY THIS FUNCTION
            'post_already_uploaded' => $post_already_uploaded,
            'post_has_skipped_flag' => isset($post_data['skipped']),
            'post_skipped_value' => $post_data['skipped'] ?? null,
            'current_hash' => isset($current_hash) ? $current_hash : 'not calculated',
            'latest_valid_version_hash' => isset($latest_valid_version['content_hash']) ? $latest_valid_version['content_hash'] : 'NOT SET',
            'hashes_match' => (isset($current_hash) && isset($latest_valid_version['content_hash'])) ? ($current_hash === $latest_valid_version['content_hash'] ? 'YES' : 'NO') : 'N/A',
            'versions_count' => isset($versions) && is_array($versions) ? count($versions) : 0,
            'upload_mode' => $upload_mode,
            'all_versions' => isset($versions) && is_array($versions) ? array_map(function($v) {
                return array(
                    'tx_id' => $v['transactionId'] ?? ($v['tx_id'] ?? ($v['id'] ?? 'NONE')),
                    'hash' => $v['content_hash'] ?? 'NO HASH',
                    'timestamp' => $v['timestamp'] ?? 0
                );
            }, $versions) : array()
        )
    );

    wp_send_json_success($response_data);
}

// AJAX handler for saving upload mode
add_action('wp_ajax_clkt_save_upload_mode', 'clkt_save_upload_mode');
function clkt_save_upload_mode()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $post_id = intval($_POST['post_id']);
    $upload_mode = sanitize_text_field(wp_unslash($_POST['upload_mode']));

    if (!$post_id || !in_array($upload_mode, array('new_only', 'force_reupload'))) {
        wp_send_json_error('Invalid parameters');
    }

    update_post_meta($post_id, '_clkt_upload_mode', $upload_mode);

    wp_send_json_success(array(
        'message' => 'Upload mode saved',
        'upload_mode' => $upload_mode
    ));
}

// For Gutenberg/Block Editor
// Disabled the inline panel since we have the proper meta box
// add_action('enqueue_block_editor_assets', 'clkt_gutenberg_editor_assets');
add_action('enqueue_block_editor_assets', 'clkt_enqueue_gutenberg_integration');

// Ensure WordPress meta box functionality
add_action('admin_enqueue_scripts', 'clkt_enqueue_admin_scripts');

// Removed debug admin notices - Gutenberg integration is working

/**
 * Enqueue admin scripts for proper meta box functionality
 */
function clkt_enqueue_admin_scripts($hook)
{
    // Only load on post edit screens
    if (!in_array($hook, ['post.php', 'post-new.php'])) {
        return;
    }

    if (!clkt_is_supported_post_type(clkt_get_current_admin_post_type())) {
        return;
    }

    // Enqueue postbox script for meta box collapse/expand functionality
    wp_enqueue_script('postbox');

    // Enqueue common admin scripts
    wp_enqueue_script('common');
    wp_enqueue_script('wp-lists');

    // Add custom script to initialize postboxes
    wp_add_inline_script('postbox', '
        jQuery(document).ready(function($) {
            if (typeof postboxes !== "undefined") {
                postboxes.add_postbox_toggles(pagenow);
            }
        });
    ');

}

/**
 * Gutenberg/Block Editor integration
 */
/**
 * Enqueue enhanced Gutenberg integration for save button
 */
function clkt_enqueue_gutenberg_integration()
{
    global $post;

    // Only load on supported post edit screens.
    if (!$post || !clkt_is_supported_post_type($post->post_type)) {
        return;
    }

    // Enqueue the Gutenberg integration script
    wp_enqueue_script(
        'clkt-gutenberg-integration',
        plugin_dir_url(__FILE__) . 'dist/gutenberg-integration.js',
        array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose'),
        CLKT_VERSION . '-' . time(), // Version with timestamp for cache busting
        true
    );

    // Make sure AJAX object is available
    wp_localize_script('clkt-gutenberg-integration', 'clkt_ajax_obj', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('clkt_ajax_nonce'),
        'debug' => defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options'),

        'global_auto_save' => get_option('clkt_global_auto_save', false) ? '1' : '0',
        'global_encryption_password' => get_option('clkt_global_encryption_password', '')
    ));
}


/**
 * Clear pending upload after successful upload
 */
function clkt_clear_pending_upload()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    delete_post_meta($post_id, '_clkt_pending_upload');

    wp_send_json_success();
}
add_action('wp_ajax_clkt_clear_pending_upload', 'clkt_clear_pending_upload');

/**
 * Calculate content hash for a post (for detecting changes)
 * Only includes fields that matter for content comparison
 * IMPORTANT: Must use RAW fields (not rendered) to match what JavaScript receives from REST API
 */
function clkt_calculate_post_content_hash($post)
{
    $hash_data = array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt
    );

    // Use JSON_UNESCAPED_UNICODE to match JavaScript's JSON.stringify behavior
    $json_string = json_encode($hash_data, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $json_string);

    return $hash;
}

/**
 * Get all attachments for a post (featured image + inline attachments)
 * Returns attachment data including file size and eligibility for Arweave upload
 */
function clkt_get_post_attachments($post_id)
{
    // Get size limits
    $max_free_size_kb = intval(get_option('clkt_free_dispatch_limit_kb', 100));
    $max_free_size_bytes = $max_free_size_kb * 1024;

    $max_allowed_mb = intval(get_option('clkt_max_allowed_upload_mb', 100));
    $max_allowed_bytes = $max_allowed_mb * 1024 * 1024;

    $attachments = array(
        'featured_image' => null,
        'inline_attachments' => array(),
        'upload_candidates' => array(),
        'max_free_size' => $max_free_size_bytes, // FREE limit for JavaScript
        'max_allowed_size' => $max_allowed_bytes // MAX limit for JavaScript
    );

    // Get featured image
    $featured_image_id = get_post_thumbnail_id($post_id);
    if ($featured_image_id) {
        $featured_attachment = clkt_get_attachment_info($featured_image_id);
        if ($featured_attachment) {
            $attachments['featured_image'] = $featured_attachment;
            // Add to upload_candidates if within max allowed size (not just free size)
            if ($featured_attachment['size'] > 0 && $featured_attachment['size'] <= $max_allowed_bytes) {
                $attachments['upload_candidates'][] = $featured_attachment;
            }
        }
    }

    // Get post content and find all WordPress attachments
    $post = get_post($post_id);
    if ($post && $post->post_content) {
        $content = $post->post_content;

        // Find all WordPress attachment URLs in content
        // Patterns: wp-content/uploads/, attachment IDs, etc.
        $attachment_patterns = array(
            // Direct wp-content/uploads URLs (full URLs with http/https)
            '/https?:\/\/[^\/\s"\'<>]+\/wp-content\/uploads\/[^\s"\'<>]+/',
            // Local wp-content/uploads URLs (relative paths)
            '/\/wp-content\/uploads\/[^\s"\'<>]+/',
            // WordPress image tags with attachment IDs
            '/wp-image-(\d+)/',
            // Gallery shortcode attachment IDs
            '/\[gallery[^\]]*ids="([^"]+)"/i',
            // Attachment shortcodes
            '/\[attachment[^\]]*id="?(\d+)"?[^\]]*\]/i'
        );

        $found_attachments = array();

        foreach ($attachment_patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);

            if (strpos($pattern, 'wp-content') !== false) {
                // Direct URLs - need to find attachment ID
                foreach ($matches[0] as $url) {
                    // Convert to full URL if needed
                    if (strpos($url, 'http') !== 0) {
                        // It's a relative path, prepend site URL
                        $url = home_url($url);
                    }

                    $attachment_id = attachment_url_to_postid($url);
                    if ($attachment_id && !in_array($attachment_id, $found_attachments)) {
                        $found_attachments[] = $attachment_id;
                    }
                }
            } else {
                // Attachment IDs from classes, shortcodes, etc.
                $ids = isset($matches[1]) ? $matches[1] : array();
                foreach ($ids as $id_string) {
                    if (strpos($id_string, ',') !== false) {
                        // Gallery IDs (comma-separated)
                        $gallery_ids = array_map('trim', explode(',', $id_string));
                        foreach ($gallery_ids as $gid) {
                            if (is_numeric($gid) && !in_array((int) $gid, $found_attachments)) {
                                $found_attachments[] = (int) $gid;
                            }
                        }
                    } else if (is_numeric($id_string)) {
                        // Single ID
                        if (!in_array((int) $id_string, $found_attachments)) {
                            $found_attachments[] = (int) $id_string;
                        }
                    }
                }
            }
        }

        // Get full attachment info for each found attachment
        foreach ($found_attachments as $attachment_id) {
            if ($attachment_id !== $featured_image_id) { // Don't duplicate featured image
                $attachment_info = clkt_get_attachment_info($attachment_id);
                if ($attachment_info) {
                    $attachments['inline_attachments'][] = $attachment_info;
                    // Add to upload_candidates if within max allowed size (not just free size)
                    if ($attachment_info['size'] > 0 && $attachment_info['size'] <= $max_allowed_bytes) {
                        $attachments['upload_candidates'][] = $attachment_info;
                    }
                }
            }
        }
    }

    return $attachments;
}

/**
 * Get detailed information about a specific attachment
 */
function clkt_get_attachment_info($attachment_id)
{
    if (!$attachment_id)
        return null;

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return null;
    }

    $file_path = get_attached_file($attachment_id);
    $file_url = wp_get_attachment_url($attachment_id);

    // Force HTTPS for attachment URLs to avoid mixed content errors
    // Check multiple conditions to ensure we catch HTTPS requests
    $is_https = is_ssl() ||
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    if ($file_url && $is_https) {
        $file_url = str_replace('http://', 'https://', $file_url);
    }

    $file_size = $file_path && file_exists($file_path) ? filesize($file_path) : 0;
    $mime_type = get_post_mime_type($attachment_id);

    // Get size limits
    $max_free_size_kb = intval(get_option('clkt_free_dispatch_limit_kb', 100));
    $max_free_size_bytes = $max_free_size_kb * 1024;

    $max_allowed_mb = intval(get_option('clkt_max_allowed_upload_mb', 100));
    $max_allowed_bytes = $max_allowed_mb * 1024 * 1024;

    // Get relative path from uploads directory (web-safe)
    $upload_dir = wp_upload_dir();
    $relative_path = '';
    if ($file_path && $upload_dir['basedir']) {
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $relative_path = ltrim($relative_path, '/\\'); // Remove leading slashes
    }

    return array(
        'id' => $attachment_id,
        'url' => $file_url,
        'relative_path' => $relative_path, // Web-safe relative path from uploads/
        'filename' => basename($file_path),
        'size' => $file_size,
        'mime_type' => $mime_type,
        'title' => $attachment->post_title,
        'alt_text' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        'eligible_for_upload' => $file_size > 0 && $file_size <= $max_allowed_bytes,
        'is_free' => $file_size > 0 && $file_size <= $max_free_size_bytes,
        'human_readable_size' => size_format($file_size)
    );
}

// Add AJAX handler for getting post data
function clkt_get_post_data()
{
    // Check nonce
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid security token'));
        return;
    }

    // Check if post ID is provided
    if (!isset($_POST['post_id'])) {
        wp_send_json_error(array('message' => 'Post ID is required'));
        return;
    }

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);

    if (!$post) {
        wp_send_json_error(array('message' => 'Post not found'));
        return;
    }

    // Check if user can edit this post
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'You do not have permission to edit this post'));
        return;
    }

    $data = clkt_format_post_data($post);

    // Enhance with attachment information for CLKT processing
    $attachments_data = clkt_get_post_attachments($post_id);

    // Get upload mode to determine if we should reuse attachments
    $global_auto_save = get_option('clkt_auto_save_on_publish', false);
    $per_post_setting = get_post_meta($post_id, '_clkt_per_post_setting', true);

    $will_auto_save = false;
    if ($per_post_setting === 'always') {
        $will_auto_save = true;
    } else if ($per_post_setting === 'never') {
        $will_auto_save = false;
    } else {
        $will_auto_save = $global_auto_save;
    }

    // Get upload mode preference
    // For bulk uploads, check if a bulk upload_type was passed (overrides per-post mode)
    $bulk_upload_type = isset($_POST['upload_type']) ? sanitize_text_field(wp_unslash($_POST['upload_type'])) : '';

    if ($bulk_upload_type === 'all_posts') {
        // Force re-upload mode - always re-upload everything
        $upload_mode = 'force_reupload';
    } else {
        // Get per-post upload mode preference
        $upload_mode = get_post_meta($post_id, '_clkt_upload_mode', true);
        if (empty($upload_mode)) {
            $upload_mode = 'new_only';
        }
    }

    // Apply reuse logic to attachments (same as preview does)
    if ($upload_mode === 'new_only' && isset($attachments_data['upload_candidates'])) {
        $reused_count = 0;
        foreach ($attachments_data['upload_candidates'] as &$attachment) {
            $attachment_id = $attachment['id'];

            // Check if this attachment already has a transaction ID (matching preview logic)
            $existing_tx_id = get_post_meta($attachment_id, '_clkt_current_tx_id', true);

            // Validate transaction ID (ignore TEST_ IDs in production mode)
            if (clkt_is_valid_tx_id($existing_tx_id)) {
                // Mark as reusing existing (multiple flags for different consumers)
                $attachment['skipped'] = true;
                $attachment['reusing_existing'] = true;
                $attachment['reused'] = true;  // For upload executor
                $attachment['arweave_tx_id'] = $existing_tx_id;
                $attachment['arweave_url'] = 'https://arweave.net/' . $existing_tx_id;
                $reused_count++;
            }
        }
    }

    $data['clkt_attachments'] = $attachments_data;

    // Include upload mode for JavaScript
    $data['_clkt_upload_mode'] = $upload_mode;

    // Calculate and include the content hash (so JavaScript doesn't have to)
    $data['clkt_content_hash'] = clkt_calculate_post_content_hash($post);

    wp_send_json_success($data);
}
add_action('wp_ajax_clkt_get_post_data', 'clkt_get_post_data');

/**
 * Check whether a URL is safe for the attachment proxy to fetch.
 *
 * @param string $url           Requested URL.
 * @param int    $attachment_id Optional attachment ID to match against.
 * @return bool
 */
function clkt_is_proxy_attachment_url_allowed($url, $attachment_id = 0)
{
    $parsed_url = wp_parse_url($url);
    if (
        empty($parsed_url['scheme']) ||
        empty($parsed_url['host']) ||
        !in_array(strtolower($parsed_url['scheme']), array('http', 'https'), true)
    ) {
        return false;
    }

    $requested_host = strtolower($parsed_url['host']);
    $requested_path = isset($parsed_url['path']) ? trailingslashit($parsed_url['path']) : '/';

    if ($attachment_id) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        $attachment_url_parts = $attachment_url ? wp_parse_url($attachment_url) : array();

        if (!empty($attachment_url_parts['host']) && !empty($attachment_url_parts['path'])) {
            $attachment_host = strtolower($attachment_url_parts['host']);
            $attachment_path = trailingslashit($attachment_url_parts['path']);

            if ($requested_host === $attachment_host && $requested_path === $attachment_path) {
                return true;
            }
        }
    }

    $uploads = wp_upload_dir();
    $uploads_base = isset($uploads['baseurl']) ? wp_parse_url($uploads['baseurl']) : array();

    if (!empty($uploads_base['host'])) {
        $uploads_host = strtolower($uploads_base['host']);
        $uploads_path = isset($uploads_base['path']) ? trailingslashit($uploads_base['path']) : '/';

        if ($requested_host === $uploads_host && strpos($requested_path, $uploads_path) === 0) {
            return true;
        }
    }

    $trusted_hosts = apply_filters('clkt_proxy_attachment_trusted_hosts', array());
    $trusted_hosts = array_map('strtolower', array_map('sanitize_text_field', (array) $trusted_hosts));

    return in_array($requested_host, $trusted_hosts, true);
}

/**
 * AJAX handler: Proxy attachment fetch (for S3 CORS bypass)
 * Fetches attachment file server-side and returns it to browser
 */
function clkt_proxy_attachment_fetch()
{
    // Check nonce
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid security token'));
        return;
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    // Check if attachment URL is provided
    if (!isset($_POST['url'])) {
        wp_send_json_error(array('message' => 'Attachment URL is required'));
        return;
    }

    $url = esc_url_raw(wp_unslash($_POST['url']));
    $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

    if (!clkt_is_proxy_attachment_url_allowed($url, $attachment_id)) {
        wp_send_json_error(array('message' => 'URL not allowed for proxying'));
        return;
    }

    // Fetch the attachment file
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => max(1, absint(get_option('clkt_max_allowed_upload_mb', 100))) * 1024 * 1024
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Failed to fetch attachment: ' . $response->get_error_message()));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error(array('message' => 'Failed to fetch attachment: HTTP ' . $status_code));
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');

    // Return the file data as base64 (easier to handle in JavaScript)
    wp_send_json_success(array(
        'data' => base64_encode($body),
        'content_type' => $content_type,
        'size' => strlen($body)
    ));
}
add_action('wp_ajax_clkt_proxy_attachment_fetch', 'clkt_proxy_attachment_fetch');

// Add AJAX handler for saving transaction ID
function clkt_save_transaction()
{
    // Check nonce
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid security token'));
        return;
    }

    // Check if post ID and transaction ID are provided
    if (!isset($_POST['post_id']) || !isset($_POST['tx_id']) || !isset($_POST['content_hash'])) {
        wp_send_json_error(array('message' => 'Post ID, transaction ID, and content hash are required'));
        return;
    }

    $post_id = intval($_POST['post_id']);

    $tx_id = sanitize_text_field(wp_unslash($_POST['tx_id']));
    $content_hash = sanitize_text_field(wp_unslash($_POST['content_hash']));
    $tags = isset($_POST['tags']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['tags'])) : array();

    // Check if user can edit this post
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'You do not have permission to edit this post'));
        return;
    }

    // Get existing versions
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }
    // Prevent duplicate uploads (but only if the matching version has a valid TX ID)
    foreach ($versions as $version) {
        if (isset($version['content_hash']) && $version['content_hash'] === $content_hash) {
            // Check if this version has a valid transaction ID (ignore TEST_ in production)
            $version_tx_id = $version['transactionId'] ?? '';
            if (clkt_is_valid_tx_id($version_tx_id)) {
                wp_send_json_error(array('message' => 'This version is already uploaded'));
                return;
            }
        }
    }
    // Get the current post and WP revision info
    $post = get_post($post_id);
    $post_title = $post ? $post->post_title : '';
    $post_modified = $post ? $post->post_modified : current_time('mysql');

    // Get current WP revision info
    $wp_revisions = wp_get_post_revisions($post_id);
    $wp_revision_count = count($wp_revisions) + 1; // +1 for current

    // For current revision, use post ID as revision ID (standard WP practice)
    $current_wp_revision_id = $post_id;

    // If there are revisions, the "current" content is represented by the post itself
    // but we track the revision ID for consistency
    if (!empty($wp_revisions)) {
        // Latest revision is first in array, but current published content is the post itself
        $current_wp_revision_id = $post_id;
    }

    // Add new version with clean structure
    $new_version = array(
        'tx_id' => $tx_id,
        'arweave_url' => 'https://arweave.net/' . $tx_id,
        'timestamp' => time(),
        'content_hash' => $content_hash,
        'wp_revision_id' => $current_wp_revision_id,
        'wp_revision_count' => $wp_revision_count,
        'post_title' => $post_title,
        'post_modified' => $post_modified
    );
    $versions[] = $new_version;

    update_post_meta($post_id, '_clkt_arweave_versions', $versions);

    // Update current status - simple TX ID presence indicates uploaded
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);

    if (!empty($tags)) {
        update_post_meta($post_id, '_clkt_arweave_tags', $tags);
    }
    clkt_log_transaction($post_id, $tx_id, $tags);
    wp_send_json_success(array(
        'message' => 'Transaction saved successfully',
        'tx_id' => $tx_id,
        'arweave_url' => 'https://arweave.net/' . $tx_id,
        'versions' => $versions
    ));
}
add_action('wp_ajax_clkt_save_transaction', 'clkt_save_transaction');

// Simple save handler for bulk uploads (temporary workaround)
function clkt_save_bulk_transaction()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'));
        return;
    }

    // Get parameters
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $tx_id = isset($_POST['tx_id']) ? sanitize_text_field(wp_unslash($_POST['tx_id'])) : '';

    if (!$post_id || !$tx_id) {
        wp_send_json_error(array('message' => 'Missing post_id or tx_id'));
        return;
    }

    // Check if user can edit this post
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Get content hash from request (optional for bulk)
    $content_hash = isset($_POST['content_hash']) ? sanitize_text_field(wp_unslash($_POST['content_hash'])) : '';

    // Get existing versions
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }

    // Get the current post and WP revision info
    $post = get_post($post_id);
    $post_title = $post ? $post->post_title : '';
    $post_modified = $post ? $post->post_modified : current_time('mysql');

    // Get current WP revision info
    $wp_revisions = wp_get_post_revisions($post_id);
    $wp_revision_count = count($wp_revisions) + 1; // +1 for current
    $current_wp_revision_id = $post_id; // For current revision, use post ID

    // Add new version with clean structure (same as individual save)
    $new_version = array(
        'tx_id' => $tx_id,
        'arweave_url' => 'https://arweave.net/' . $tx_id,
        'timestamp' => time(),
        'content_hash' => $content_hash,
        'wp_revision_id' => $current_wp_revision_id,
        'wp_revision_count' => $wp_revision_count,
        'post_title' => $post_title,
        'post_modified' => $post_modified
    );
    $versions[] = $new_version;
    update_post_meta($post_id, '_clkt_arweave_versions', $versions);

    // Update current status - this is the critical missing piece!
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);

    update_post_meta($post_id, '_clkt_upload_timestamp', time());

    // Also save URL for convenience
    $arweave_url = 'https://arweave.net/' . $tx_id;
    update_post_meta($post_id, '_clkt_arweave_url', $arweave_url);

    wp_send_json_success(array(
        'message' => 'Transaction saved successfully',
        'tx_id' => $tx_id,
        'arweave_url' => $arweave_url,
        'versions' => $versions
    ));
}
add_action('wp_ajax_clkt_save_bulk_transaction', 'clkt_save_bulk_transaction');

// AJAX endpoint to fetch all versions for a post
function clkt_get_versions()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!isset($_POST['post_id'])) {
        wp_send_json_error(array('message' => 'Post ID is required'));
        return;
    }
    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'You do not have permission to view this post'));
        return;
    }
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    if (!is_array($versions)) {
        $versions = array();
    }

    // Normalize entries created by different current upload handlers.
    // Also filter out invalid tx_ids
    if (!empty($versions)) {
        $clean_versions = array();
        foreach ($versions as $version) {
            // Skip versions with invalid tx_ids
            if (isset($version['tx_id']) && ($version['tx_id'] === '[object Object]' || strpos($version['tx_id'], '[object') !== false)) {
                continue;
            }

            if (!isset($version['id']) && isset($version['arweave_url'])) {
                // Extract transaction ID from URL
                $tx_id = str_replace('https://arweave.net/', '', $version['arweave_url']);
                // Skip if extracted ID is also invalid
                if ($tx_id === '[object Object]' || strpos($tx_id, '[object') !== false) {
                    continue;
                }
                $version['id'] = $tx_id;
                if (!isset($version['tx_id'])) {
                    $version['tx_id'] = $tx_id;
                }
            }

            $clean_versions[] = $version;
        }
        $versions = $clean_versions;
    }

    wp_send_json_success(array('versions' => $versions));
}
add_action('wp_ajax_clkt_get_versions', 'clkt_get_versions');

// Debug endpoint to check all CLKT meta fields for a post
function clkt_debug_meta()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!isset($_POST['post_id'])) {
        wp_send_json_error(array('message' => 'Post ID is required'));
        return;
    }

    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Get all meta keys related to CLKT/CC
    $meta_keys = array(
        '_clkt_arweave_versions',
        '_clkt_current_tx_id',
        '_clkt_arweave_url',
        '_clkt_arweave_block_height',
        '_clkt_arweave_tags',
        '_clkt_upload_timestamp',
        '_clkt_content_hash'
    );

    $debug_info = array();
    foreach ($meta_keys as $key) {
        $value = get_post_meta($post_id, $key, true);
        if (!empty($value)) {
            $debug_info[$key] = $value;
        }
    }

    wp_send_json_success(array(
        'post_id' => $post_id,
        'meta_fields' => $debug_info,
        'has_versions' => !empty($debug_info['_clkt_arweave_versions']),
        'has_tx_id' => !empty($debug_info['_clkt_current_tx_id'])
    ));
}
add_action('wp_ajax_clkt_debug_meta', 'clkt_debug_meta');

function clkt_render_restore_proof_button($post_id, $tx_id)
{
    if (empty($tx_id) || $tx_id === '[object Object]') {
        return '';
    }

    return '<button type="button" class="button-link clkt-restore-proof-button" data-post-id="' . esc_attr($post_id) . '" data-tx-id="' . esc_attr($tx_id) . '">Verify / Restore</button>';
}

function clkt_render_timeline_proof_summary($version)
{
    $parts = array();

    $content_hash = isset($version['content_hash']) ? sanitize_text_field($version['content_hash']) : '';
    if ($content_hash !== '') {
        $short = substr($content_hash, 0, 8) . '…' . substr($content_hash, -4);
        $parts[] = '<span title="Content hash: ' . esc_attr($content_hash) . '">Hash ' . esc_html($short) . '</span>';
    }

    if (isset($version['restore_manifest']['attachments']) && is_array($version['restore_manifest']['attachments'])) {
        $count = count($version['restore_manifest']['attachments']);
        $parts[] = esc_html($count . ' media');
    }

    if (empty($parts)) {
        return '';
    }

    return '<div class="clkt-timeline-proof-summary">' . implode(' · ', $parts) . '</div>';
}

// AJAX endpoint to get refreshed timeline HTML
function clkt_get_timeline_html()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Verify post exists
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(array('message' => 'Post not found'));
        return;
    }

    // Clear cache to ensure we get fresh version data
    wp_cache_delete($post_id, 'post_meta');

    // Get the versions and generate timeline HTML
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    // Ensure versions is an array
    if (!is_array($versions)) {
        $versions = array();
    }

    // Get WordPress revisions
    $wp_revisions = wp_get_post_revisions($post_id);
    if (!is_array($wp_revisions)) {
        $wp_revisions = array();
    }
    $revision_count = count($wp_revisions) + 1;

    // Build timeline data with proper WordPress-Arweave matching
    $timeline_html = '';

    // Create a map of Arweave uploads indexed by their WordPress revision count
    $arweave_by_wp_version = [];
    if (!empty($versions)) {
        foreach ($versions as $version) {
            if (isset($version['wp_revision_count'])) {
                // This upload knows which WordPress revision it belongs to
                $arweave_by_wp_version[$version['wp_revision_count']] = $version;
            }
        }
    }

    if (!empty($versions) || $revision_count > 1) {
        $timeline_html = ''; // Clear and start fresh

        // Current version (always the highest revision number)
        $timeline_html .= '<div class="clkt-timeline-item">';

        // Check if current version has an Arweave upload
        $current_has_upload = isset($arweave_by_wp_version[$revision_count]);
        $dot_class = $current_has_upload ? 'current uploaded' : 'current not-uploaded';

        $timeline_html .= '<div class="clkt-timeline-dot ' . esc_attr($dot_class) . '">' . intval($revision_count) . '</div>';
        $timeline_html .= '<div class="clkt-timeline-details">';
        $timeline_html .= '<div class="clkt-timeline-version">Version ' . intval($revision_count) . ' <span style="color: #8b5cf6;"> (Current)</span></div>';
        $timeline_html .= '<div class="clkt-timeline-date">' . esc_html(get_post_modified_time('Y-m-d H:i', false, $post_id)) . '</div>';

        if ($current_has_upload) {
            $version = $arweave_by_wp_version[$revision_count];
            $tx_id = '';
            if (isset($version['tx_id'])) {
                $tx_id = $version['tx_id'];
            } else if (isset($version['arweave_url'])) {
                $tx_id = basename($version['arweave_url']);
            } else if (isset($version['id'])) {
                $tx_id = $version['id'];
            }

            // Validate TX ID (also filters TEST_ IDs in production mode)
            $is_valid_tx = clkt_is_valid_tx_id($tx_id);

            if ($tx_id) {
                $timeline_html .= '<div class="clkt-timeline-status">';
                if ($is_valid_tx) {
                    $timeline_html .= '<span class="clkt-timeline-badge clkt-verify-tx" data-tx-id="' . esc_attr($tx_id) . '" data-post-id="' . esc_attr($post_id) . '">verifying...</span>';
                } else {
                    $timeline_html .= '<span class="clkt-timeline-badge invalid" style="background: #dc3545; color: white;">invalid TX</span>';
                }
                if ($is_valid_tx) {
                    $timeline_html .= '<a href="' . esc_url('https://arweave.net/' . $tx_id) . '" target="_blank" class="clkt-timeline-link">';
                    $timeline_html .= '<svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">';
                    $timeline_html .= '<path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/>';
                    $timeline_html .= '<path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/>';
                    $timeline_html .= '</svg></a>';
                }
                $timeline_html .= '</div>';
                if ($is_valid_tx) {
                    $timeline_html .= '<div class="clkt-timeline-tx">' . esc_html(substr($tx_id, 0, 8)) . '...' . esc_html(substr($tx_id, -6)) . '</div>';
                    $timeline_html .= clkt_render_timeline_proof_summary($version);
                    $timeline_html .= clkt_render_restore_proof_button($post_id, $tx_id);
                } else {
                    $timeline_html .= '<div class="clkt-timeline-tx" style="color: #dc3545; font-size: 10px;">' . esc_html(substr($tx_id, 0, 20)) . '...</div>';
                }
            }
        } else {
            $timeline_html .= '<div class="clkt-timeline-status"><span style="color: #999; font-size: 11px;">Not uploaded</span></div>';
        }

        $timeline_html .= '</div></div>';

        // Add WordPress revisions with their corresponding Arweave uploads
        $version_num = $revision_count - 1;
        foreach ($wp_revisions as $revision) {
            // Skip invalid revisions
            if (!$revision || !isset($revision->ID)) {
                $version_num--;
                continue;
            }

            $has_upload = isset($arweave_by_wp_version[$version_num]);
            $dot_class = $has_upload ? 'uploaded' : 'not-uploaded';

            $timeline_html .= '<div class="clkt-timeline-item">';
            $timeline_html .= '<div class="clkt-timeline-dot ' . esc_attr($dot_class) . '">' . intval($version_num) . '</div>';
            $timeline_html .= '<div class="clkt-timeline-details">';
            $timeline_html .= '<div class="clkt-timeline-version">Version ' . intval($version_num) . '</div>';

            // Get date safely
            $revision_date = get_post_modified_time('Y-m-d H:i', false, $revision->ID);
            if (!$revision_date) {
                $revision_date = 'Unknown date';
            }
            $timeline_html .= '<div class="clkt-timeline-date">' . esc_html($revision_date) . '</div>';

            if ($has_upload) {
                $version = $arweave_by_wp_version[$version_num];
                $v_tx_id = '';
                if (isset($version['tx_id'])) {
                    $v_tx_id = $version['tx_id'];
                } else if (isset($version['arweave_url'])) {
                    $v_tx_id = basename($version['arweave_url']);
                } else if (isset($version['id'])) {
                    $v_tx_id = $version['id'];
                }

                // Validate TX ID (also filters TEST_ IDs in production mode)
                $is_valid_tx = clkt_is_valid_tx_id($v_tx_id);

                if ($v_tx_id) {
                    $timeline_html .= '<div class="clkt-timeline-status">';
                    if ($is_valid_tx) {
                        $timeline_html .= '<span class="clkt-timeline-badge clkt-verify-tx" data-tx-id="' . esc_attr($v_tx_id) . '" data-post-id="' . esc_attr($post_id) . '">verifying...</span>';
                    } else {
                        $timeline_html .= '<span class="clkt-timeline-badge invalid" style="background: #dc3545; color: white;">invalid TX</span>';
                    }
                    if ($is_valid_tx) {
                        $timeline_html .= '<a href="' . esc_url('https://arweave.net/' . $v_tx_id) . '" target="_blank" class="clkt-timeline-link">';
                        $timeline_html .= '<svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">';
                        $timeline_html .= '<path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/>';
                        $timeline_html .= '<path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/>';
                        $timeline_html .= '</svg></a>';
                    }
                    $timeline_html .= '</div>';
                    if ($is_valid_tx) {
                        $timeline_html .= '<div class="clkt-timeline-tx">' . esc_html(substr($v_tx_id, 0, 8)) . '...' . esc_html(substr($v_tx_id, -6)) . '</div>';
                        $timeline_html .= clkt_render_timeline_proof_summary($version);
                        $timeline_html .= clkt_render_restore_proof_button($post_id, $v_tx_id);
                    } else {
                        $timeline_html .= '<div class="clkt-timeline-tx" style="color: #dc3545; font-size: 10px;">' . esc_html(substr($v_tx_id, 0, 20)) . '...</div>';
                    }
                }
            } else {
                $timeline_html .= '<div class="clkt-timeline-status"><span style="color: #999; font-size: 11px;">Not uploaded</span></div>';
            }
            $timeline_html .= '</div></div>';

            $version_num--;
        }

    }

    wp_send_json_success(array(
        'html' => $timeline_html,
        'version_count' => is_array($versions) ? count($versions) : 0
    ));
}
add_action('wp_ajax_clkt_get_timeline_html', 'clkt_get_timeline_html');

/**
 * Log transaction details to the database
 */
function clkt_log_transaction($post_id, $tx_id, $tags = array())
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'content_cloud_transactions';

    return $wpdb->insert(
        $table_name,
        array(
            'post_id' => $post_id,
            'transaction_id' => $tx_id,
            'tags' => maybe_serialize($tags),
            'created_at' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ),
        array('%d', '%s', '%s', '%s', '%d')
    );
}

// Add activation hook to create database table
function clkt_create_transaction_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'content_cloud_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        transaction_id varchar(255) NOT NULL,
        tags longtext,
        created_at datetime NOT NULL,
        user_id bigint(20) NOT NULL,
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY transaction_id (transaction_id),
        KEY user_id (user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'clkt_create_transaction_table');

// Add actions
add_action('admin_enqueue_scripts', 'clkt_enqueue_plugin_admin_assets');

function clkt_add_scripts_to_footer()
{
    $js = '
        document.addEventListener("DOMContentLoaded", function () {
            var clktDebugLog = function() {
                if (window.clkt_ajax_obj && window.clkt_ajax_obj.debug) {
                    console.log.apply(console, arguments);
                }
            };
            clktDebugLog("Initializing CLKT");

            try {
                // Initialize Arweave with default configuration
                var arweave = Arweave.init({});
                window.ContentCloud = {
                    arweave: arweave
                };

                clktDebugLog("Arweave initialized successfully");

                // Test connection
                arweave.network.getInfo().then(function(info) {
                    clktDebugLog("Connected to Arweave network:", info);
                }).catch(function(error) {
                    console.error("Error connecting to Arweave network:", error);
                });
            } catch (error) {
                console.error("Error initializing Arweave:", error);
            }
        });
    ';
    wp_register_script('clkt-arweave-init', false, array(), CLKT_VERSION, true);
    wp_enqueue_script('clkt-arweave-init');
    wp_add_inline_script('clkt-arweave-init', $js);
}


// AJAX endpoint to get verification cache for a post
function clkt_get_verification()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!isset($_POST['post_id'])) {
        wp_send_json_error(['message' => 'Post ID is required']);
        return;
    }
    $post_id = intval($_POST['post_id']);
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'You do not have permission to view this post']);
        return;
    }
    $verification = get_post_meta($post_id, '_clkt_arweave_verification', true);
    if (!is_array($verification)) {
        $verification = array();
    }
    wp_send_json_success(['verification' => $verification]);
}
add_action('wp_ajax_clkt_get_verification', 'clkt_get_verification');

// AJAX endpoint to update verification for a tx
function clkt_update_verification()
{
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token']);
        return;
    }
    if (!isset($_POST['post_id']) || !isset($_POST['tx_id']) || !isset($_POST['status'])) {
        wp_send_json_error(['message' => 'Missing parameters']);
        return;
    }
    $post_id = intval($_POST['post_id']);
    $tx_id = sanitize_text_field(wp_unslash($_POST['tx_id']));
    $status = sanitize_text_field(wp_unslash($_POST['status']));
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'You do not have permission to edit this post']);
        return;
    }
    $verification = get_post_meta($post_id, '_clkt_arweave_verification', true);
    if (!is_array($verification)) {
        $verification = array();
    }
    $now = time();
    // Only cache verified status permanently
    // Don't cache errors - let transactions retry verification
    if ($status === 'verified') {
        $verification[$tx_id] = [
            'status' => $status,
            'timestamp' => $now
        ];
        update_post_meta($post_id, '_clkt_arweave_verification', $verification);
        wp_send_json_success(['verification' => $verification[$tx_id]]);
    } elseif ($status === 'error') {
        // Remove any cached error status to allow re-verification
        if (isset($verification[$tx_id])) {
            unset($verification[$tx_id]);
            update_post_meta($post_id, '_clkt_arweave_verification', $verification);
        }
        wp_send_json_success(['verification' => null, 'message' => 'Error status not cached']);
    } else {
        wp_send_json_success(['verification' => isset($verification[$tx_id]) ? $verification[$tx_id] : null]);
    }
}
add_action('wp_ajax_clkt_update_verification', 'clkt_update_verification');

// AJAX endpoint to create test posts for automated testing
function clkt_create_test_post()
{
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token']);
        return;
    }

    if (!isset($_POST['title']) || !isset($_POST['content'])) {
        wp_send_json_error(['message' => 'Missing title or content']);
        return;
    }

    if (!current_user_can('publish_posts')) {
        wp_send_json_error(['message' => 'You do not have permission to create posts']);
        return;
    }

    $timestamp = time();
    $post_data = array(
        'post_title' => sanitize_text_field(wp_unslash($_POST['title'])) . " (Test {$timestamp})",
        'post_content' => wp_kses_post(wp_unslash($_POST['content'])),
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_type' => 'post',
        'meta_input' => array(
            '_clkt_test_post' => $timestamp
        )
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        wp_send_json_error([
            'message' => 'Failed to create test post',
            'error' => $post_id->get_error_message()
        ]);
    } else {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_data['post_title'],
            'message' => 'Test post created successfully',
            'timestamp' => $timestamp
        ]);
    }
}
add_action('wp_ajax_clkt_create_test_post', 'clkt_create_test_post');

// Check for pending uploads (auto-upload feature)
function clkt_check_pending_upload()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $pending_upload = get_post_meta($post_id, '_clkt_pending_upload', true);

    wp_send_json_success(array(
        'pending' => !empty($pending_upload),
        'encrypt' => isset($pending_upload['encrypt']) ? $pending_upload['encrypt'] : false
    ));
}
add_action('wp_ajax_clkt_check_pending_upload', 'clkt_check_pending_upload');

// Debug endpoint to check post metadata
function clkt_debug_post_meta()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $post_id = intval($_POST['post_id']);

    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    $current_tx_id = get_post_meta($post_id, '_clkt_current_tx_id', true);

    // Also check auto-upload settings
    $global_auto_save = get_option('clkt_global_auto_save', false);
    $per_post_setting = get_post_meta($post_id, '_clkt_per_post_save', true);
    $pending_upload = get_post_meta($post_id, '_clkt_pending_upload', true);

    wp_send_json_success(array(
        'post_id' => $post_id,
        'versions' => $versions,
        'current_tx_id' => $current_tx_id,
        'versions_count' => is_array($versions) ? count($versions) : 0,
        'is_uploaded' => !empty($current_tx_id),
        // Auto-upload diagnostics
        'global_auto_save' => $global_auto_save,
        'per_post_setting' => $per_post_setting,
        'pending_upload' => $pending_upload
    ));
}
add_action('wp_ajax_clkt_debug_post_meta', 'clkt_debug_post_meta');

// AJAX handler to get Arweave metadata for an attachment
function clkt_get_attachment_arweave()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $attachment_id = intval($_POST['attachment_id']);
    if (!$attachment_id) {
        wp_send_json_error(['message' => 'Invalid attachment ID']);
        return;
    }

    $arweave_tx_id = get_post_meta($attachment_id, '_clkt_current_tx_id', true);
    $content_hash = get_post_meta($attachment_id, '_clkt_content_hash', true);

    // Validate transaction ID (ignore TEST_ IDs in production mode)
    if (clkt_is_valid_tx_id($arweave_tx_id) && $content_hash) {
        wp_send_json_success([
            'arweave_tx_id' => $arweave_tx_id,
            'content_hash' => $content_hash
        ]);
    } else {
        wp_send_json_success([
            'arweave_tx_id' => null,
            'content_hash' => null
        ]);
    }
}
add_action('wp_ajax_clkt_get_attachment_arweave', 'clkt_get_attachment_arweave');

// AJAX handler to save Arweave metadata for an attachment
function clkt_save_attachment_arweave()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $attachment_id = intval($_POST['attachment_id']);
    $arweave_tx_id = sanitize_text_field(wp_unslash($_POST['arweave_tx_id']));
    $content_hash = sanitize_text_field(wp_unslash($_POST['content_hash']));

    if (!$attachment_id || !$arweave_tx_id || !$content_hash) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }

    update_post_meta($attachment_id, '_clkt_current_tx_id', $arweave_tx_id);
    update_post_meta($attachment_id, '_clkt_content_hash', $content_hash);

    wp_send_json_success([
        'message' => 'Arweave metadata saved successfully',
        'attachment_id' => $attachment_id,
        'arweave_tx_id' => $arweave_tx_id
    ]);
}
add_action('wp_ajax_clkt_save_attachment_arweave', 'clkt_save_attachment_arweave');

function clkt_get_version_tx_id($version)
{
    if (!is_array($version)) {
        return '';
    }

    if (!empty($version['transactionId'])) {
        return sanitize_text_field($version['transactionId']);
    }

    if (!empty($version['tx_id'])) {
        return sanitize_text_field($version['tx_id']);
    }

    if (!empty($version['id'])) {
        return sanitize_text_field($version['id']);
    }

    if (!empty($version['arweave_url'])) {
        return sanitize_text_field(basename($version['arweave_url']));
    }

    return '';
}

function clkt_get_restore_proof_version($post_id, $tx_id = '')
{
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        return null;
    }

    $versions = array_reverse($versions);

    foreach ($versions as $version) {
        if (empty($version['restore_manifest']) || !is_array($version['restore_manifest'])) {
            continue;
        }

        $version_tx_id = clkt_get_version_tx_id($version);
        if ($tx_id === '' || hash_equals($version_tx_id, $tx_id)) {
            return $version;
        }
    }

    return null;
}

function clkt_build_restore_preview_from_manifest($manifest)
{
    $post = isset($manifest['post']) && is_array($manifest['post']) ? $manifest['post'] : array();
    $content = isset($manifest['content']) && is_array($manifest['content']) ? $manifest['content'] : array();
    $attachments = isset($manifest['attachments']) && is_array($manifest['attachments']) ? $manifest['attachments'] : array();

    return array(
        'title' => isset($post['title']) ? (string) $post['title'] : 'Untitled',
        'content' => !empty($post['contentRaw']) ? (string) $post['contentRaw'] : (isset($post['contentRendered']) ? (string) $post['contentRendered'] : ''),
        'excerpt' => !empty($post['excerptRaw']) ? (string) $post['excerptRaw'] : (isset($post['excerptRendered']) ? wp_strip_all_tags((string) $post['excerptRendered']) : ''),
        'original_post_id' => isset($post['id']) ? absint($post['id']) : 0,
        'post_type' => isset($post['postType']) ? sanitize_key($post['postType']) : 'post',
        'source_tx_id' => isset($content['txId']) ? sanitize_text_field($content['txId']) : '',
        'source_url' => isset($content['url']) ? esc_url_raw($content['url']) : '',
        'content_hash' => isset($content['contentHash']) ? sanitize_text_field($content['contentHash']) : '',
        'package_hash' => isset($content['packageHash']) ? sanitize_text_field($content['packageHash']) : '',
        'encrypted' => !empty($content['encrypted']),
        'attachments' => array_values($attachments),
        'attachment_count' => count($attachments),
    );
}

function clkt_verify_restore_manifest($version, $manifest)
{
    $tx_id = clkt_get_version_tx_id($version);
    $content = isset($manifest['content']) && is_array($manifest['content']) ? $manifest['content'] : array();
    $expected_package_hash = isset($content['packageHash']) ? sanitize_text_field($content['packageHash']) : '';
    $expected_content_hash = isset($content['contentHash']) ? sanitize_text_field($content['contentHash']) : '';

    if ($tx_id === '' || $tx_id === '[object Object]') {
        return array(
            'status' => 'mismatch',
            'message' => 'The saved version does not contain a valid transaction ID.',
            'checked_at' => current_time('mysql', true),
        );
    }

    if (strpos($tx_id, 'TEST_') === 0) {
        $manifest_tx_id = isset($content['txId']) ? sanitize_text_field($content['txId']) : '';
        $manifest_hash = isset($version['content_hash']) ? sanitize_text_field($version['content_hash']) : '';
        $hash_matches = $expected_content_hash === '' || $manifest_hash === '' || hash_equals($expected_content_hash, $manifest_hash);

        return array(
            'status' => ($manifest_tx_id === $tx_id && $hash_matches) ? 'verified' : 'mismatch',
            'message' => ($manifest_tx_id === $tx_id && $hash_matches)
                ? 'Test upload verified from the saved restore manifest.'
                : 'Test upload manifest does not match the saved version metadata.',
            'source' => 'stored_manifest',
            'checked_at' => current_time('mysql', true),
        );
    }

    $response = wp_remote_get('https://arweave.net/' . rawurlencode($tx_id), array(
        'timeout' => 15,
        'redirection' => 3,
    ));

    if (is_wp_error($response)) {
        return array(
            'status' => 'unavailable',
            'message' => $response->get_error_message(),
            'source' => 'arweave',
            'checked_at' => current_time('mysql', true),
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        return array(
            'status' => 'unavailable',
            'message' => 'Arweave gateway returned HTTP ' . $status_code . '.',
            'source' => 'arweave',
            'checked_at' => current_time('mysql', true),
        );
    }

    $body = wp_remote_retrieve_body($response);
    $actual_package_hash = hash('sha256', $body);
    $package_matches = $expected_package_hash === '' || hash_equals($expected_package_hash, $actual_package_hash);

    $content_matches = true;
    $decoded = json_decode($body, true);
    if (is_array($decoded) && $expected_content_hash !== '' && !empty($decoded['clkt_content_hash'])) {
        $content_matches = hash_equals($expected_content_hash, sanitize_text_field($decoded['clkt_content_hash']));
    }

    $verified = $package_matches && $content_matches;

    return array(
        'status' => $verified ? 'verified' : 'mismatch',
        'message' => $verified
            ? 'Permaweb package matches the saved restore manifest.'
            : 'Permaweb package does not match the saved restore manifest.',
        'source' => 'arweave',
        'expected_package_hash' => $expected_package_hash,
        'actual_package_hash' => $actual_package_hash,
        'checked_at' => current_time('mysql', true),
    );
}

function clkt_build_restore_proof_response($post_id, $tx_id = '')
{
    $version = clkt_get_restore_proof_version($post_id, $tx_id);
    if (!$version) {
        return new WP_Error('clkt_restore_manifest_missing', 'No restore manifest found for this upload.');
    }

    $manifest = $version['restore_manifest'];
    $verification = clkt_verify_restore_manifest($version, $manifest);
    $preview = clkt_build_restore_preview_from_manifest($manifest);

    if (empty($preview['title']) || empty($preview['content'])) {
        return new WP_Error('clkt_restore_manifest_incomplete', 'Restore manifest is missing restorable post content.');
    }

    return array(
        'version' => array(
            'tx_id' => clkt_get_version_tx_id($version),
            'timestamp' => isset($version['timestamp']) ? absint($version['timestamp']) : 0,
            'content_hash' => isset($version['content_hash']) ? sanitize_text_field($version['content_hash']) : '',
        ),
        'verification' => $verification,
        'preview' => $preview,
        'manifest' => $manifest,
    );
}

function clkt_restore_proof_preview()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $tx_id = isset($_POST['tx_id']) ? sanitize_text_field(wp_unslash($_POST['tx_id'])) : '';

    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $response = clkt_build_restore_proof_response($post_id, $tx_id);
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
        return;
    }

    wp_send_json_success($response);
}
add_action('wp_ajax_clkt_restore_proof_preview', 'clkt_restore_proof_preview');

function clkt_restore_proof_create_draft()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $tx_id = isset($_POST['tx_id']) ? sanitize_text_field(wp_unslash($_POST['tx_id'])) : '';

    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $response = clkt_build_restore_proof_response($post_id, $tx_id);
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
        return;
    }

    $preview = $response['preview'];

    $decrypted_title = isset($_POST['decrypted_title']) ? sanitize_text_field(wp_unslash($_POST['decrypted_title'])) : '';
    $decrypted_content = isset($_POST['decrypted_content']) ? wp_unslash($_POST['decrypted_content']) : '';
    $decrypted_excerpt = isset($_POST['decrypted_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['decrypted_excerpt'])) : '';

    $restore_title = $decrypted_title !== '' ? $decrypted_title : ($preview['title'] ?: 'Untitled');
    $restore_content = $decrypted_content !== '' ? wp_kses_post($decrypted_content) : $preview['content'];
    $restore_excerpt = $decrypted_excerpt !== '' ? $decrypted_excerpt : $preview['excerpt'];

    $restore_post_type = !empty($preview['post_type']) && clkt_is_supported_post_type($preview['post_type'])
        ? $preview['post_type']
        : 'post';
    $draft_id = wp_insert_post(array(
        'post_title' => sprintf('Restored: %s', sanitize_text_field($restore_title)),
        'post_content' => $restore_content,
        'post_excerpt' => sanitize_textarea_field($restore_excerpt),
        'post_status' => 'draft',
        'post_type' => $restore_post_type,
        'post_author' => get_current_user_id(),
    ), true);

    if (is_wp_error($draft_id)) {
        wp_send_json_error(array('message' => $draft_id->get_error_message()));
        return;
    }

    update_post_meta($draft_id, '_clkt_restored_from_post_id', $post_id);
    update_post_meta($draft_id, '_clkt_restored_from_tx_id', $response['version']['tx_id']);
    update_post_meta($draft_id, '_clkt_restore_manifest_hash', $preview['package_hash']);

    wp_send_json_success(array(
        'message' => 'Restore draft created.',
        'draft_id' => $draft_id,
        'edit_url' => get_edit_post_link($draft_id, 'raw'),
        'source_tx_id' => $response['version']['tx_id'],
    ));
}
add_action('wp_ajax_clkt_restore_proof_create_draft', 'clkt_restore_proof_create_draft');

function clkt_build_permanent_edition($post_id, $tx_id = '')
{
    $version = clkt_get_restore_proof_version($post_id, $tx_id);
    if (!$version) {
        return null;
    }

    $manifest = isset($version['restore_manifest']) && is_array($version['restore_manifest']) ? $version['restore_manifest'] : array();
    $content = isset($manifest['content']) && is_array($manifest['content']) ? $manifest['content'] : array();
    $post = isset($manifest['post']) && is_array($manifest['post']) ? $manifest['post'] : array();
    $attachments = isset($manifest['attachments']) && is_array($manifest['attachments']) ? $manifest['attachments'] : array();

    $resolved_tx_id = clkt_get_version_tx_id($version);
    $is_test = strpos($resolved_tx_id, 'TEST_') === 0;

    $edition = array(
        'schema' => 'clkt-permanent-edition-v1',
        'schemaVersion' => '1.0.0',
        'generatedAt' => current_time('c'),
        'title' => isset($post['title']) ? sanitize_text_field($post['title']) : get_the_title($post_id),
        'author' => isset($post['author']) ? sanitize_text_field($post['author']) : get_the_author_meta('display_name', get_post_field('post_author', $post_id)),
        'sourceUrl' => isset($post['link']) ? esc_url_raw($post['link']) : get_permalink($post_id),
        'arweave' => array(
            'txId' => sanitize_text_field($resolved_tx_id),
            'url' => $is_test ? null : 'https://arweave.net/' . rawurlencode($resolved_tx_id),
            'test' => $is_test,
        ),
        'proof' => array(
            'contentHash' => isset($content['contentHash']) ? sanitize_text_field($content['contentHash']) : (isset($version['content_hash']) ? sanitize_text_field($version['content_hash']) : ''),
            'packageHash' => isset($content['packageHash']) ? sanitize_text_field($content['packageHash']) : '',
            'encrypted' => !empty($content['encrypted']),
            'mediaCount' => count($attachments),
        ),
        'timestamp' => isset($version['timestamp']) ? absint($version['timestamp']) : 0,
        'pluginVersion' => isset($manifest['pluginVersion']) ? sanitize_text_field($manifest['pluginVersion']) : '',
        'verifyUrl' => $is_test ? null : 'https://arweave.net/' . rawurlencode($resolved_tx_id),
    );

    $license = get_post_meta($post_id, '_clkt_license', true);
    if (!empty($license) && is_array($license)) {
        $edition['license'] = array_intersect_key($license, array_flip(array('spdx', 'url', 'note')));
    }

    return $edition;
}

function clkt_get_permanent_edition()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $tx_id = isset($_POST['tx_id']) ? sanitize_text_field(wp_unslash($_POST['tx_id'])) : '';

    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $edition = clkt_build_permanent_edition($post_id, $tx_id);
    if (!$edition) {
        wp_send_json_error(array('message' => 'No Permanent Edition available for this upload.'));
        return;
    }

    wp_send_json_success(array('edition' => $edition));
}
add_action('wp_ajax_clkt_get_permanent_edition', 'clkt_get_permanent_edition');

/**
 * Save upload version metadata after successful upload
 */
function clkt_save_upload_version()
{
    if (!check_ajax_referer('clkt_ajax_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error('Missing post ID');
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('Post not found');
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $tx_id_raw = isset($_POST['tx_id']) ? map_deep(wp_unslash($_POST['tx_id']), 'sanitize_text_field') : '';

    // Handle case where tx_id might be an object or array
    if (is_array($tx_id_raw) || is_object($tx_id_raw)) {
        // Try to extract the actual transaction ID
        if (is_array($tx_id_raw) && isset($tx_id_raw['id'])) {
            $tx_id = $tx_id_raw['id'];
        } elseif (is_object($tx_id_raw) && isset($tx_id_raw->id)) {
            $tx_id = sanitize_text_field(wp_unslash($tx_id_raw->id));
        } else {
            wp_send_json_error('Invalid transaction ID format');
            return;
        }
    } else {
        $tx_id = sanitize_text_field($tx_id_raw);
    }

    $arweave_url = isset($_POST['arweave_url']) ? esc_url_raw(wp_unslash($_POST['arweave_url'])) : '';

    if (!$post_id || !$tx_id || $tx_id === '[object Object]') {
        wp_send_json_error('Missing or invalid required parameters');
        return;
    }

    // Check if this is a test mode transaction (but still save it)
    $is_test_mode = (strpos($tx_id, 'TEST_') === 0);

    // Calculate content hash of CURRENT post state
    $current_content_hash = clkt_calculate_post_content_hash($post);
    $uploaded_content_hash = isset($_POST['content_hash'])
        ? sanitize_text_field(wp_unslash($_POST['content_hash']))
        : '';
    if ($uploaded_content_hash === '') {
        $uploaded_content_hash = $current_content_hash;
    }

    // Get existing versions BEFORE adding new one
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }

    // Check if current content hash matches ANY EXISTING version (before adding new one)
    // This tells us if the post was edited after the upload we're saving
    $current_hash_has_version = false;
    foreach ($versions as $version) {
        if (isset($version['content_hash']) && $version['content_hash'] === $current_content_hash) {
            $current_hash_has_version = true;
            break;
        }
    }

    // Get WordPress revision count
    $wp_revisions = wp_get_post_revisions($post_id);
    $wp_revision_count = count($wp_revisions) + 1;

    $restore_manifest = array();
    if (isset($_POST['restore_manifest'])) {
        $manifest_json = wp_unslash($_POST['restore_manifest']);
        $decoded_manifest = json_decode($manifest_json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_manifest)) {
            $restore_manifest = $decoded_manifest;
        }
    }

    if (!empty($restore_manifest)) {
        if (!isset($restore_manifest['content']) || !is_array($restore_manifest['content'])) {
            $restore_manifest['content'] = array();
        }

        $restore_manifest['content']['txId'] = $tx_id;
        $restore_manifest['content']['url'] = $arweave_url ?: 'https://arweave.net/' . $tx_id;
        $restore_manifest['content']['contentHash'] = $uploaded_content_hash;
        $restore_manifest['savedAt'] = current_time('mysql', true);
    }

    // Add new version with current content hash
    $new_version = array(
        'id' => $tx_id,
        'tx_id' => $tx_id,
        'transactionId' => $tx_id, // Add this for consistency with version checking code
        'arweave_url' => $arweave_url,
        'timestamp' => time(),
        'content_hash' => $uploaded_content_hash,
        'wp_revision_count' => $wp_revision_count,
        'post_title' => $post->post_title,
        'post_modified' => $post->post_modified,
        'service' => 'self_wallet' // Uploaded via new component system
    );

    if (!empty($restore_manifest)) {
        $new_version['restore_manifest'] = $restore_manifest;
    }

    $versions[] = $new_version;

    update_post_meta($post_id, '_clkt_arweave_versions', $versions);
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);
    update_post_meta($post_id, '_clkt_content_hash', $uploaded_content_hash);
    if (!empty($restore_manifest)) {
        update_post_meta($post_id, '_clkt_latest_restore_manifest', $restore_manifest);
    }

    // Clear pending flags once the saved version represents the current post content.
    if ($current_hash_has_version || $uploaded_content_hash === $current_content_hash) {
        delete_post_meta($post_id, '_clkt_pending_upload');
    }

    // Clear object cache to ensure subsequent reads get fresh data
    wp_cache_delete($post_id, 'post_meta');
    clean_post_cache($post_id);

    wp_send_json_success(array(
        'message' => 'Version saved successfully',
        'version_count' => count($versions),
        'content_hash' => $uploaded_content_hash
    ));
}
add_action('wp_ajax_clkt_save_upload_version', 'clkt_save_upload_version');
