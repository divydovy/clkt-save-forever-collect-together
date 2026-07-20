<?php

/**
 * Plugin Name: CLKT - Save Forever, Collect Together
 * Plugin URI: https://getclkt.com
 * Description: Your content deserves to outlive your website. CLKT permanently preserves your WordPress posts and media on the decentralized web. One click, saved forever. No recurring fees, no expiration dates.
 * Version: 1.0.19
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

define('CLKT_VERSION', '1.0.19');
define('CLKT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLKT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Handle auto-upload when post is published
function clkt_handle_auto_upload($post_id, $post, $update)
{
    // Skip if this is not a post save or if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Skip if user doesn't have permission to edit posts
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Skip if this is not a 'post' post type
    if ($post->post_type !== 'post') {
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

    // Set upload status to pending (will be handled by JavaScript via UploadManager)
    update_post_meta($post_id, '_clkt_arweave_status', 'pending_auto_upload');

    // Add admin notice for auto-upload
    add_action('admin_notices', function () use ($post_id) {
        ?>
        <div class="notice notice-info clkt-auto-upload-notice" data-post-id="<?php echo esc_attr($post_id); ?>">
            <p><strong>CLKT:</strong> Post published successfully. Upload will start automatically...</p>
        </div>
        <?php
    });

    // Enqueue auto-upload trigger script
    add_action('admin_enqueue_scripts', function () use ($post_id) {
        $auto_upload_js = '
            jQuery(document).ready(function ($) {
                var postId = $(".clkt-auto-upload-notice").data("post-id");
                var notice = $(".clkt-auto-upload-notice");
                if (!notice.length) return;

                var checkInterval = setInterval(function () {
                    if (window.ContentCloud && window.ContentCloud.singlePostContext) {
                        clearInterval(checkInterval);
                        console.log("Auto-upload triggered for post", postId);
                        notice.find("p").html("<strong>CLKT:</strong> Uploading to Arweave... <span class=\"spinner is-active\" style=\"float:none;\"></span>");
                        setTimeout(function() {
                            window.ContentCloud.singlePostContext.startUpload({ autoUpload: true });
                        }, 500);
                    }
                }, 200);

                setTimeout(function () {
                    clearInterval(checkInterval);
                    if (!window.ContentCloud || !window.ContentCloud.singlePostContext) {
                        notice.removeClass("notice-info").addClass("notice-warning");
                        notice.find("p").html("<strong>CLKT:</strong> Auto-upload failed to initialize. Please manually upload from the CLKT panel below.");
                    }
                }, 10000);
            });
        ';
        wp_register_script('clkt-auto-upload-trigger', false, array('jquery'), CLKT_VERSION, true);
        wp_enqueue_script('clkt-auto-upload-trigger');
        wp_add_inline_script('clkt-auto-upload-trigger', $auto_upload_js);
    });
}

// Wrapper for REST API hook - adapts signature from ($post, $request, $creating) to ($post_id, $post, $update)
function clkt_handle_auto_upload_rest($post, $request, $creating) {
    clkt_handle_auto_upload($post->ID, $post, !$creating);
}

// Use rest_after_insert_post for Gutenberg compatibility - ensures post meta is available
add_action('rest_after_insert_post', 'clkt_handle_auto_upload_rest', 10, 3);

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
add_filter('manage_posts_columns', 'clkt_add_posts_columns');

// Populate the Permaweb status column
function clkt_populate_posts_columns($column, $post_id)
{
    if ($column === 'clkt_permaweb_status') {
        $status = get_post_meta($post_id, '_clkt_arweave_status', true);

        // Check if post is currently uploading or pending
        if ($status === 'uploading') {
            echo '<span class="clkt-status-uploading">⏳ Uploading...</span>';
            return;
        } elseif ($status === 'pending_auto_upload') {
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
add_action('manage_posts_custom_column', 'clkt_populate_posts_columns', 10, 2);

// Add CSS for status indicators
function clkt_admin_head_posts_list($hook)
{
    if ($hook !== 'edit.php') {
        return;
    }
    $screen = get_current_screen();
    if ($screen && $screen->id === 'edit-post') {
        $css = '
            .column-clkt_permaweb_status { width: 120px; }
            .clkt-status-uploaded { color: #46b450; font-weight: bold; }
            .clkt-status-uploading { color: #ffb900; font-weight: bold; }
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

    // Cleanup legacy feedback database table (if exists)
    clkt_cleanup_legacy_feedback_table();
}

/**
 * Remove legacy feedback table from database
 * Called on plugin activation to clean up old installations
 */
function clkt_cleanup_legacy_feedback_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'clkt_feedback';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

    if ($table_exists) {
        // Drop the legacy table
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }
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

function clkt_enqueue_scripts($hook)
{
    // Only load on post edit screens, bulk upload page, and settings page
    $allowed_pages = array('post.php', 'post-new.php', 'settings_page_clkt-settings', 'toplevel_page_clkt-bulk-upload');

    // Check if we're on an allowed page and user can edit posts
    if (!current_user_can('edit_posts') || !in_array($hook, $allowed_pages)) {
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

    // Only add full configuration on post edit pages
    $is_post_edit_page = in_array($hook, $allowed_pages);

    // Localize script with AJAX URL, nonce, and user info
    // JavaScript global object for AJAX operations
    wp_localize_script('clkt-bundle', 'clkt_ajax_obj', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('clkt_ajax_nonce'),
            'plugin_url' => plugin_dir_url(__FILE__),

            // Wallet configuration
            'wallet_type' => 'self', // Default to self-managed wallet
            'wallet_provider' => get_option('clkt_wallet_provider', 'wander'),

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
            // Diagnostic information
            console.log("CLKT - Security Context Check:");
            console.log("- Is Secure Context:", window.isSecureContext);
            console.log("- Protocol:", window.location.protocol);
            console.log("- Crypto Object:", typeof window.crypto !== "undefined");
            console.log("- Subtle Crypto:", window.crypto && typeof window.crypto.subtle !== "undefined");

            // Check WebCrypto availability
            function checkWebCrypto() {
                try {
                    // Check for secure context first
                    if (!window.isSecureContext) {
                        console.error("WebCrypto requires a secure context (HTTPS)");
                        return false;
                    }

                    if (window.crypto && window.crypto.subtle) {
                        console.log("Native WebCrypto is available");
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
// Legacy feedback feature removed - now using Google Forms (client-side only)
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

    // Legacy feedback feature removed - now using Google Forms (client-side only)
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
        'title'          => array('rendered' => get_the_title($post_id)),
        'content'        => array('rendered' => apply_filters('the_content', $post->post_content)),
        'excerpt'        => array('rendered' => has_excerpt($post_id) ? apply_filters('the_excerpt', $post->post_excerpt) : ''),
        'author'         => (int) $post->post_author,
        'featured_media' => (int) get_post_thumbnail_id($post_id),
        'categories'     => wp_get_post_categories($post_id),
        'tags'           => wp_get_post_tags($post_id, array('fields' => 'ids')),
    );
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
        'post_type' => 'post',
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
                    'type' => 'post',
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
        'post_type' => 'post',
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


/**
 * Handle single post upload in bulk process
 */
function clkt_bulk_upload_single()
{
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'clkt_bulk_upload')) {
        wp_send_json_error('Invalid nonce');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $post_id = intval($_POST['post_id']);
    $encrypt = sanitize_text_field(wp_unslash($_POST['encrypt'])) === 'true';

    try {
        // Get post data using direct approach
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception('Post not found');
        }

        // Self-managed wallet - prepare data for client-side upload

        // Get post content
        $post_content = apply_filters('the_content', $post->post_content);

        // Get attachments if needed
        $attachments = array();
        if (get_option('clkt_include_attachments', '0') === '1') {
            $attachment_ids = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_parent' => $post_id,
                'fields' => 'ids'
            ));

            foreach ($attachment_ids as $attachment_id) {
                $attachments[] = array(
                    'id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'type' => get_post_mime_type($attachment_id),
                    'title' => get_the_title($attachment_id)
                );
            }
        }

        // Get full attachment data using the same function as single post upload
        $clkt_attachments = clkt_get_post_attachments($post_id);

        // Get category and tag details for proper metadata
        $categories = get_the_category($post_id);
        $tags = get_the_tags($post_id);

        // Build embedded data for categories and tags
        $embedded_data = array();
        if ($categories) {
            $embedded_data['wp:term'] = array(
                array_map(function ($cat) {
                    return array(
                        'id' => $cat->term_id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'taxonomy' => 'category'
                    );
                }, $categories)
            );
        }

        if ($tags) {
            if (!isset($embedded_data['wp:term'])) {
                $embedded_data['wp:term'] = array();
            }
            $tag_data = array_map(function ($tag) {
                return array(
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'taxonomy' => 'post_tag'
                );
            }, $tags);

            // Merge tags with categories
            if (!empty($embedded_data['wp:term'][0])) {
                $embedded_data['wp:term'][] = $tag_data;
            } else {
                $embedded_data['wp:term'] = array($tag_data);
            }
        }

        // Build comprehensive post data matching the format of single post uploads
        $post_data = array(
            'id' => $post_id,
            'title' => array('rendered' => get_the_title($post_id)),
            'content' => array('rendered' => $post_content),
            'excerpt' => array('rendered' => get_the_excerpt($post_id)),
            'author' => $post->post_author,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'link' => get_permalink($post_id),
            'categories' => wp_get_post_categories($post_id),
            'tags' => wp_get_post_tags($post_id, array('fields' => 'ids')),
            'featured_media' => get_post_thumbnail_id($post_id),
            'clkt_attachments' => $clkt_attachments,  // Use the full attachment data
            '_embedded' => $embedded_data
        );

        // Return post data for client-side upload
        wp_send_json_success(array(
            'wallet_type' => 'self',
            'post_data' => $post_data,  // Full post data for createPostAsset
            'encrypt' => $encrypt,
            'message' => 'Post data prepared for self-managed wallet upload',
            // Keep backward compatibility fields
            'post_id' => $post_id,
            'post_title' => get_the_title($post_id),
            'post_content' => $post_content,
            'post_url' => get_permalink($post_id),
            'post_date' => $post->post_date,
            'post_author' => get_the_author_meta('display_name', $post->post_author),
            'attachments' => $attachments  // Keep simple format for backward compatibility
        ));

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_clkt_bulk_upload_single', 'clkt_bulk_upload_single');


/**
 * Handle auto-upload when post is published/updated
 */
function clkt_auto_upload_on_publish($post_id, $post, $update)
{
    // Only for published posts
    if ($post->post_status !== 'publish') {
        return;
    }

    // Only for regular posts (not attachments, pages, etc.)
    if ($post->post_type !== 'post') {
        return;
    }

    // Check if auto-upload is enabled for this post
    $auto_upload = get_post_meta($post_id, '_clkt_publish_and_upload', true);
    if (!$auto_upload) {
        return;
    }

    // Check if encryption is enabled for this post
    $encrypt_content = get_post_meta($post_id, '_clkt_encrypt_content', true);

    // Schedule the upload for next page load (since we can't do Arweave upload server-side easily)
    update_post_meta($post_id, '_clkt_pending_upload', array(
        'timestamp' => time(),
        'encrypt' => $encrypt_content ? true : false
    ));

    // Add admin notice for next page load
    set_transient('clkt_upload_notice_' . get_current_user_id(), array(
        'type' => 'info',
        'message' => 'Post "' . $post->post_title . '" will be uploaded to Permaweb automatically.'
    ), 60);
}

// Wrapper for REST API hook - adapts signature from ($post, $request, $creating) to ($post_id, $post, $update)
function clkt_auto_upload_on_publish_rest($post, $request, $creating) {
    clkt_auto_upload_on_publish($post->ID, $post, !$creating);
}

// Use rest_after_insert_post for Gutenberg compatibility - ensures post meta is available
add_action('rest_after_insert_post', 'clkt_auto_upload_on_publish_rest', 10, 3);

/**
 * Save meta box data including auto-upload preference
 */
function clkt_save_meta_box_data($post_id)
{
    // Check nonce
    if (!isset($_POST['clkt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['clkt_nonce'])), 'clkt_meta_box')) {
        return;
    }

    // Check user capabilities
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save auto-upload preference
    if (isset($_POST['clkt_publish_and_upload'])) {
        update_post_meta($post_id, '_clkt_publish_and_upload', 1);
    } else {
        delete_post_meta($post_id, '_clkt_publish_and_upload');
    }

    // Note: Encryption preferences are now handled by AJAX in clkt_save_encryption_settings()
    // Only save encryption preference if it's explicitly in the POST data to avoid conflicts
    if (isset($_POST['clkt_encrypt_content'])) {
        update_post_meta($post_id, '_clkt_encrypt_content', 1);
    } elseif (array_key_exists('clkt_encrypt_content', $_POST)) {
        // Only delete if the key exists but is not set (unchecked checkbox)
        delete_post_meta($post_id, '_clkt_encrypt_content');
    }
    // If clkt_encrypt_content is not in POST at all, don't touch the meta field
}
add_action('save_post', 'clkt_save_meta_box_data');

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
                // Use the new helper function that tries multiple hash calculation methods
                if (clkt_post_hash_matches($post, $latest_valid_version['content_hash'])) {
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
        'type' => 'post',
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

// AJAX handler for updating post preferences from Gutenberg
add_action('wp_ajax_clkt_update_post_preference', 'clkt_update_post_preference');
function clkt_update_post_preference()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    if (isset($_POST['auto_upload'])) {
        $value = sanitize_text_field(wp_unslash($_POST['auto_upload']));

        // Update the per-post save setting
        if ($value === '1' || $value == 1) {
            update_post_meta($post_id, '_clkt_per_post_save', 'always');
        } elseif ($value === '0' || $value == 0) {
            update_post_meta($post_id, '_clkt_per_post_save', 'never');
        } else {
            // null or 'default' - set to default to use global setting
            update_post_meta($post_id, '_clkt_per_post_save', 'default');
        }

        // Also update the old meta for backward compatibility
        if ($value === '1' || $value == 1) {
            update_post_meta($post_id, '_clkt_publish_and_upload', '1');
        } elseif ($value === '0' || $value == 0) {
            update_post_meta($post_id, '_clkt_publish_and_upload', '0');
        } else {
            delete_post_meta($post_id, '_clkt_publish_and_upload');
        }
    }

    if (isset($_POST['encrypt'])) {
        if (sanitize_text_field(wp_unslash($_POST['encrypt']))) {
            update_post_meta($post_id, '_clkt_encrypt_content', 1);
        } else {
            delete_post_meta($post_id, '_clkt_encrypt_content');
        }
    }

    wp_send_json_success();
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

// Add enhanced save functionality integration
add_action('post_submitbox_misc_actions', 'clkt_add_publish_save_options', 10);  // Classic editor
add_action('admin_enqueue_scripts', 'clkt_enhanced_save_script', 10);             // Classic editor JS

// For Gutenberg/Block Editor
// Disabled the inline panel since we have the proper meta box
// add_action('enqueue_block_editor_assets', 'clkt_gutenberg_editor_assets');
add_action('enqueue_block_editor_assets', 'clkt_enqueue_gutenberg_integration');

// Also add to pages and other post types for testing
add_action('page_submitbox_misc_actions', 'clkt_add_publish_save_options', 1);

// Ensure WordPress meta box functionality
add_action('admin_enqueue_scripts', 'clkt_enqueue_admin_scripts');

// Register meta fields for Gutenberg
add_action('init', 'clkt_register_post_meta');

function clkt_register_post_meta()
{
    register_post_meta('post', 'clkt_auto_upload', array(
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'default' => '0',
        'auth_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    register_post_meta('post', '_clkt_publish_and_upload', array(
        'show_in_rest' => true,
        'single' => true,
        'type' => 'boolean',
        'default' => false,
        'auth_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));
}

// Removed debug admin notices - Gutenberg integration is working

/**
 * Enqueue admin scripts for proper meta box functionality
 */
function clkt_enqueue_admin_scripts($hook)
{
    global $post_type;

    // Only load on post edit screens
    if (!in_array($hook, ['post.php', 'post-new.php'])) {
        return;
    }

    // Only for post post_type
    if ($post_type !== 'post') {
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

    // Publish box save options styles (used by clkt_add_publish_save_options)
    $publish_box_css = '
        .misc-pub-clkt .clkt-wallet-indicator { display: flex; align-items: center; font-size: 13px; }
        .misc-pub-clkt .clkt-indicator-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; background-color: #ddd; }
        .misc-pub-clkt .clkt-indicator-dot.connected { background-color: #46b450; }
        .misc-pub-clkt .clkt-indicator-dot.disconnected { background-color: #dc3232; }
        .misc-pub-clkt .clkt-save-options { border-top: 1px solid #ddd; padding-top: 8px; }
        .misc-pub-clkt .clkt-advanced-options { font-size: 12px; color: #666; }
        .misc-pub-clkt label { cursor: pointer; }
    ';
    wp_register_style('clkt-publish-box', false, array(), CLKT_VERSION);
    wp_enqueue_style('clkt-publish-box');
    wp_add_inline_style('clkt-publish-box', $publish_box_css);
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

    // Only load on post edit screens
    if (!$post || $post->post_type !== 'post') {
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

        'global_auto_save' => get_option('clkt_global_auto_save', false) ? '1' : '0',
        'global_encryption_password' => get_option('clkt_global_encryption_password', '')
    ));
}


/**
 * Add enhanced save options to the publish box
 */
function clkt_add_publish_save_options()
{
    global $post;

    if (!$post || $post->post_type !== 'post') {
        return;
    }

    // Get current settings
    $auto_upload = get_post_meta($post->ID, '_clkt_publish_and_upload', true);
    $global_auto = get_option('clkt_global_auto_save', false);
    $encrypt = get_post_meta($post->ID, '_clkt_encrypt_content', true);

    // Add nonce field
    wp_nonce_field('clkt_meta_box', 'clkt_nonce');

    ?>
    <div class="misc-pub-section misc-pub-clkt">
        <span id="clkt-save-status">
            <span class="clkt-wallet-indicator">
                <span class="clkt-indicator-dot" id="clkt-wallet-dot"></span>
                <span id="clkt-wallet-text">Checking wallet...</span>
            </span>
        </span>

        <div class="clkt-save-options" style="margin-top: 8px;">
            <label>
                <input type="checkbox" name="clkt_publish_and_upload" id="clkt_publish_and_upload" value="1" <?php checked($auto_upload || $global_auto); ?> />
                Save to Permaweb automatically
            </label>

            <div class="clkt-advanced-options" style="margin-left: 20px; margin-top: 5px;">
                <label>
                    <input type="checkbox" name="clkt_encrypt_content" id="clkt_encrypt_content" value="1" <?php checked($encrypt); ?> />
                    Encrypt content
                </label>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Add enhanced save script for wallet integration
 */
function clkt_enhanced_save_script($hook)
{
    // Only for post edit screens
    if (!in_array($hook, array('post.php', 'post-new.php'))) {
        return;
    }

    global $post;

    // Only for Classic Editor (not Gutenberg) on post type 'post'
    if (!$post || $post->post_type !== 'post') {
        return;
    }

    wp_enqueue_script(
        'clkt-enhanced-save',
        plugins_url('assets/js/enhanced-save.js', __FILE__),
        array('jquery'),
        CLKT_VERSION,
        true
    );
}

/**
 * Show admin notices for pending uploads
 */
function clkt_show_upload_notices()
{
    $user_id = get_current_user_id();
    $notice = get_transient('clkt_upload_notice_' . $user_id);

    if ($notice) {
        echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible">';
        echo '<p><strong>CLKT:</strong> ' . esc_html($notice['message']) . '</p>';
        echo '</div>';

        delete_transient('clkt_upload_notice_' . $user_id);
    }
}
add_action('admin_notices', 'clkt_show_upload_notices');

// Removed duplicate function - see line 1939 for clkt_check_pending_upload

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

    // Clear both meta fields used for pending uploads
    delete_post_meta($post_id, '_clkt_pending_upload');
    delete_post_meta($post_id, '_clkt_arweave_status');

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
 * Calculate legacy content hash (for backward compatibility with older versions)
 * Older versions included 'modified' timestamp in the hash
 *
 * @param WP_Post $post The WordPress post object
 * @return string SHA-256 hash of the legacy post content
 */
function clkt_calculate_legacy_post_content_hash($post)
{
    $hash_data = array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
        'modified' => $post->post_modified
    );

    $json_string = json_encode($hash_data, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $json_string);

    return $hash;
}

/**
 * Check if post content matches a stored version hash
 * Tries both current and legacy hash calculation methods
 *
 * @param WP_Post $post The WordPress post object
 * @param string $stored_hash The hash stored in version metadata
 * @return bool True if hashes match (using any method)
 */
function clkt_post_hash_matches($post, $stored_hash)
{
    // Try current hash method first
    $current_hash = clkt_calculate_post_content_hash($post);
    if ($current_hash === $stored_hash) {
        return true;
    }

    // Try legacy hash method (includes 'modified' field)
    $legacy_hash = clkt_calculate_legacy_post_content_hash($post);
    if ($legacy_hash === $stored_hash) {
        return true;
    }

    // Note: Removed third hash method that called clkt_get_post_data()
    // because it's an AJAX handler that exits the script - caused preview to break

    return false;
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

    // Legacy fields removed
    update_post_meta($post_id, '_clkt_arweave_status', 'uploaded');
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

    // Legacy fields removed
    update_post_meta($post_id, '_clkt_arweave_status', 'uploaded');
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
    // Check new meta key first
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    // If no versions found, check old meta key
    if (empty($versions)) {
        $versions = get_post_meta($post_id, '_cc_arweave_versions', true);
    }

    // Also check if there's a single transaction saved (legacy format)
    if (empty($versions)) {
        $tx_id = get_post_meta($post_id, '_clkt_current_tx_id', true); // Use new meta key
        if (!$tx_id) {
            $tx_id = get_post_meta($post_id, '_cc_arweave_tx_id', true);
        }

        if ($tx_id) {
            // Create a version entry from legacy single transaction
            $versions = array(
                array(
                    'id' => $tx_id,
                    'tx_id' => $tx_id,
                    'arweave_url' => 'https://arweave.net/' . $tx_id,
                    'timestamp' => get_post_meta($post_id, '_clkt_upload_timestamp', true) ?: time()
                )
            );
        }
    }

    if (!is_array($versions)) {
        $versions = array();
    }

    // Fix missing 'id' field in older versions by extracting from arweave_url
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
        '_clkt_arweave_status',
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
    if (empty($versions)) {
        $versions = get_post_meta($post_id, '_cc_arweave_versions', true);
    }

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
    $orphaned_uploads = [];

    if (!empty($versions)) {
        foreach ($versions as $version) {
            if (isset($version['wp_revision_count'])) {
                // This upload knows which WordPress revision it belongs to
                $arweave_by_wp_version[$version['wp_revision_count']] = $version;
            } else {
                // Legacy upload without revision tracking
                $orphaned_uploads[] = $version;
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

        // Add orphaned uploads (legacy uploads without wp_revision_count)
        foreach ($orphaned_uploads as $version) {
            $v_tx_id = '';
            if (isset($version['tx_id'])) {
                $v_tx_id = $version['tx_id'];
            } else if (isset($version['arweave_url'])) {
                $v_tx_id = basename($version['arweave_url']);
            } else if (isset($version['id'])) {
                $v_tx_id = $version['id'];
            }

            $timeline_html .= '<div class="clkt-timeline-item">';
            $timeline_html .= '<div class="clkt-timeline-dot uploaded">❓</div>';
            $timeline_html .= '<div class="clkt-timeline-details">';
            $timeline_html .= '<div class="clkt-timeline-version">Legacy Upload';
            if (isset($version['post_title'])) {
                $timeline_html .= ' <span style="font-size: 11px; color: #888;">(' . esc_html($version['post_title']) . ')</span>';
            }
            $timeline_html .= '</div>';

            // Use timestamp if available
            $date = isset($version['timestamp']) ?
                gmdate('Y-m-d H:i', $version['timestamp']) :
                'Date unknown';
            $timeline_html .= '<div class="clkt-timeline-date">' . esc_html($date) . '</div>';

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
                } else {
                    $timeline_html .= '<div class="clkt-timeline-tx" style="color: #dc3545; font-size: 10px;">' . esc_html(substr($v_tx_id, 0, 20)) . '...</div>';
                }
            }
            $timeline_html .= '</div></div>';
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
add_action('admin_enqueue_scripts', 'clkt_enqueue_scripts');

function clkt_add_scripts_to_footer()
{
    $js = '
        document.addEventListener("DOMContentLoaded", function () {
            console.log("Initializing CLKT");

            try {
                // Initialize Arweave with default configuration
                var arweave = Arweave.init({});
                window.ContentCloud = {
                    arweave: arweave
                };

                console.log("Arweave initialized successfully");

                // Test connection
                arweave.network.getInfo().then(function(info) {
                    console.log("Connected to Arweave network:", info);
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

// Debug endpoint to check meta keys
function clkt_check_meta_keys()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $result = array(
        'posts_checked' => 0,
        'with_new_meta' => 0,
        'with_old_meta' => 0,
        'with_no_meta' => 0,
        'examples' => array()
    );

    $posts = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC'
    ));

    foreach ($posts as $post) {
        $result['posts_checked']++;

        // Check new meta key
        $new_versions = get_post_meta($post->ID, '_clkt_arweave_versions', true);
        // Check old meta key
        $old_versions = get_post_meta($post->ID, '_cc_arweave_versions', true);

        if (!empty($new_versions) && is_array($new_versions)) {
            $result['with_new_meta']++;
            if (count($result['examples']) < 3) {
                $result['examples'][] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'meta_type' => 'new (_clkt_)',
                    'versions' => count($new_versions)
                );
            }
        } elseif (!empty($old_versions) && is_array($old_versions)) {
            $result['with_old_meta']++;
            if (count($result['examples']) < 3) {
                $result['examples'][] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'meta_type' => 'old (_cc_)',
                    'versions' => count($old_versions)
                );
            }
        } else {
            $result['with_no_meta']++;
        }
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_clkt_check_meta_keys', 'clkt_check_meta_keys');

// Run meta key migration via AJAX
function clkt_run_migration()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    global $wpdb;

    // Define the meta key mappings
    $meta_key_mappings = array(
        '_cc_arweave_versions' => '_clkt_arweave_versions',
        '_cc_arweave_tx_id' => '_clkt_current_tx_id',
        '_cc_arweave_status' => '_clkt_arweave_status',
        '_cc_arweave_url' => '_clkt_arweave_url',
        '_cc_arweave_block_height' => '_clkt_arweave_block_height',
        '_cc_arweave_block_timestamp' => '_clkt_arweave_block_timestamp',
        '_cc_encrypted' => '_clkt_encrypted',
        '_cc_encryption_key' => '_clkt_encryption_key'
    );

    $total_updated = 0;
    $details = array();

    foreach ($meta_key_mappings as $old_key => $new_key) {
        // Update post meta
        $updated = $wpdb->update(
            $wpdb->postmeta,
            array('meta_key' => $new_key),
            array('meta_key' => $old_key),
            array('%s'),
            array('%s')
        );

        if ($updated > 0) {
            $details[] = "Updated $updated entries: $old_key -> $new_key";
            $total_updated += $updated;
        }
    }

    wp_send_json_success(array(
        'message' => "Migration complete! Updated $total_updated meta entries.",
        'total_updated' => $total_updated,
        'details' => $details
    ));
}
add_action('wp_ajax_clkt_run_migration', 'clkt_run_migration');

// Check for pending uploads (auto-upload feature)
function clkt_check_pending_upload()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    // Check both meta keys for pending uploads
    $arweave_status = get_post_meta($post_id, '_clkt_arweave_status', true);
    $pending_upload = get_post_meta($post_id, '_clkt_pending_upload', true);

    $is_pending = ($arweave_status === 'pending_auto_upload') || !empty($pending_upload);

    wp_send_json_success(array(
        'pending' => $is_pending,
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
    $status = get_post_meta($post_id, '_clkt_arweave_status', true);

    // Also check auto-upload settings
    $global_auto_save = get_option('clkt_global_auto_save', false);
    $per_post_setting = get_post_meta($post_id, '_clkt_per_post_save', true);
    $pending_upload = get_post_meta($post_id, '_clkt_pending_upload', true);

    wp_send_json_success(array(
        'post_id' => $post_id,
        'versions' => $versions,
        'current_tx_id' => $current_tx_id,
        'status' => $status,
        'versions_count' => is_array($versions) ? count($versions) : 0,
        'is_uploaded' => !empty($current_tx_id),
        // Auto-upload diagnostics
        'global_auto_save' => $global_auto_save,
        'per_post_setting' => $per_post_setting,
        'pending_upload' => $pending_upload
    ));
}
add_action('wp_ajax_clkt_debug_post_meta', 'clkt_debug_post_meta');

// Manual migration trigger for testing
function clkt_force_migration()
{
    check_ajax_referer('clkt_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $count = clkt_migrate_current_tx_ids();
    wp_send_json_success(array('migrated_count' => $count));
}
add_action('wp_ajax_clkt_force_migration', 'clkt_force_migration');

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

    // Add new version with current content hash
    $versions[] = array(
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

    update_post_meta($post_id, '_clkt_arweave_versions', $versions);
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);
    update_post_meta($post_id, '_clkt_content_hash', $uploaded_content_hash);

    // Clear pending flags once the saved version represents the current post content.
    if ($current_hash_has_version || $uploaded_content_hash === $current_content_hash) {
        delete_post_meta($post_id, '_clkt_pending_upload');
        delete_post_meta($post_id, '_clkt_arweave_status');
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

// Migration function to update existing posts with current TX ID
function clkt_migrate_current_tx_ids()
{
    global $wpdb;

    // Find ALL posts that have versions (ignore current TX ID status for this migration)
    $posts_with_versions = $wpdb->get_results(
        "SELECT post_id, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key = '_clkt_arweave_versions'"
    );

    $migrated_count = 0;
    foreach ($posts_with_versions as $row) {
        $versions = maybe_unserialize($row->meta_value);
        if (is_array($versions) && !empty($versions)) {
            // Get the latest version (last in array)
            $latest_version = end($versions);
            if (isset($latest_version['tx_id']) && !empty($latest_version['tx_id'])) {
                // Update or set the current TX ID to the latest version
                update_post_meta($row->post_id, '_clkt_current_tx_id', $latest_version['tx_id']);
                $migrated_count++;
            }
        }
    }

    return $migrated_count;
}

// Run migration on admin init (force re-run to fix any issues)
add_action('admin_init', function () {
    if (!get_option('clkt_current_tx_migrated_v2')) {
        $count = clkt_migrate_current_tx_ids();
        update_option('clkt_current_tx_migrated_v2', true);
    }
});
