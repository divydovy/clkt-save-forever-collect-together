<?php
namespace CLKT\PermawebIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Helper function to check if a transaction ID is from test mode
function clkt_is_test_transaction($tx_id)
{
    return !empty($tx_id) && strpos($tx_id, 'TEST_') === 0;
}

// Helper function to check if test mode is currently enabled
function clkt_is_test_mode_enabled()
{
    return get_option('clkt_test_mode', false);
}

// Add Permaweb integration fields to post edit screen
function clkt_permaweb_meta_box()
{
    add_meta_box(
        'clkt_permaweb_meta_box',
        'CLKT - Save Forever',
        __NAMESPACE__ . '\\clkt_permaweb_meta_box_callback',
        clkt_get_supported_post_types(),
        'side',
        'high',
        null
    );
}

// Get all previous Arweave versions for a post
function clkt_get_arweave_versions($post_id)
{
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    if (!is_array($versions)) {
        $versions = [];
    }

    return $versions;
}

// Save a new Arweave version for a post
function clkt_add_arweave_version($post_id, $arweave_url, $content_hash)
{
    $versions = clkt_get_arweave_versions($post_id);

    // Get current WP revision count
    $wp_version = count(wp_get_post_revisions($post_id)) + 1;

    // Add the new version
    $versions[] = [
        'arweave_url' => $arweave_url,
        'timestamp' => current_time('timestamp', true),
        'content_hash' => $content_hash,
        'wp_version' => $wp_version,
        'confirmation_status' => 'pending'
    ];

    // Save the updated versions
    update_post_meta($post_id, '_clkt_arweave_versions', $versions);
    update_post_meta($post_id, '_clkt_arweave_url', $arweave_url);

    return $versions;
}

// Check if content hash matches an existing version
function clkt_check_content_hash_exists($post_id, $content_hash)
{
    $versions = clkt_get_arweave_versions($post_id);

    foreach ($versions as $version) {
        if (isset($version['content_hash']) && $version['content_hash'] === $content_hash) {
            return $version['arweave_url'];
        }
    }

    return false;
}

// Get the post content and metadata as a structured JSON object
function clkt_get_post_data($post_id)
{
    $post = get_post($post_id);

    // Get featured image if exists
    $featured_image = null;
    $featured_image_id = get_post_thumbnail_id($post_id);
    if ($featured_image_id) {
        $featured_image = wp_get_attachment_url($featured_image_id);
    }

    // Get post categories
    $categories = [];
    $post_categories = get_the_category($post_id);
    if (!empty($post_categories)) {
        foreach ($post_categories as $category) {
            $categories[] = $category->name;
        }
    }

    // Build the structured data
    $post_data = [
        'structured_data' => [
            'metadata' => [
                'domain' => wp_parse_url(get_site_url(), PHP_URL_HOST),
                'postId' => $post->ID,
                'postType' => $post->post_type,
                'permalink' => get_permalink($post->ID),
                'publishedAt' => get_post_time('c', true, $post->ID),
                'modifiedAt' => get_post_modified_time('c', true, $post->ID),
                'wp_version' => count(wp_get_post_revisions($post->ID)) + 1
            ],
            'content' => [
                'title' => $post->post_title,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'timestamp' => strtotime($post->post_date_gmt),
                'excerpt' => $post->post_excerpt,
                'content' => $post->post_content,
                'featuredImage' => $featured_image,
                'tags' => wp_get_post_tags($post->ID, ['fields' => 'names']),
                'categories' => $categories
            ]
        ]
    ];

    return $post_data;
}

// Meta box callback with Figma-based design
function clkt_permaweb_meta_box_callback($post)
{
    // Get post data
    $post_data = clkt_get_post_data($post->ID);
    $post_content_json = json_encode($post_data);
    $content_hash = hash('sha256', $post_content_json);

    // Get all versions
    $versions = clkt_get_arweave_versions($post->ID);
    $latest_version = !empty($versions) ? end($versions) : null;

    // Check if current content hash matches latest uploaded version
    $current_version_uploaded = false;
    if ($latest_version && isset($latest_version['content_hash'])) {
        $current_version_uploaded = ($latest_version['content_hash'] === $content_hash);
    }

    // Get settings
    $global_auto_save_raw = get_option('clkt_global_auto_save', false);

    // Convert to boolean properly (option might be string '1' or '0')
    $global_auto_save = ($global_auto_save_raw === '1' || $global_auto_save_raw === 1 || $global_auto_save_raw === true);

    $per_post_setting = get_post_meta($post->ID, '_clkt_per_post_save', true);

    if (empty($per_post_setting)) {
        $per_post_setting = 'default';
    }

    // Get encryption settings
    $encrypt_content = get_post_meta($post->ID, '_clkt_encrypt_content', true);
    $encryption_method = get_post_meta($post->ID, '_clkt_encryption_method', true);
    $per_post_password = get_post_meta($post->ID, '_clkt_per_post_password', true);

    // Get attribution settings
    $license = get_post_meta($post->ID, '_clkt_license', true);
    if (!is_array($license)) {
        $license = array();
    }
    $derived_from = get_post_meta($post->ID, '_clkt_derived_from', true);
    if (!is_array($derived_from)) {
        $derived_from = array();
    }

    // Convert encrypt_content to boolean for proper checking
    $encrypt_content = ($encrypt_content === '1' || $encrypt_content === 1 || $encrypt_content === true);

    if (empty($encryption_method)) {
        $encryption_method = 'wallet';
    }

    // Determine if post will be saved
    $will_be_saved = false;
    if ($per_post_setting === 'always') {
        $will_be_saved = true;
    } elseif ($per_post_setting === 'never') {
        $will_be_saved = false;
    } elseif ($per_post_setting === 'default') {
        $will_be_saved = $global_auto_save;
    }

    // Get upload mode to determine status class
    $upload_mode = get_post_meta($post->ID, '_clkt_upload_mode', true);
    if (empty($upload_mode))
        $upload_mode = 'new_only';

    // Determine status class for color coding
    $status_class = 'will-not-upload'; // Default: red
    if ($will_be_saved) {
        if ($upload_mode === 'force_reupload') {
            $status_class = 'will-reupload'; // Yellow
        } else {
            $status_class = 'will-upload'; // Green
        }
    }

    // Get current WP revision for version timeline
    $current_wp_version = count(wp_get_post_revisions($post->ID)) + 1;

    // Check if test mode is currently enabled
    $test_mode_enabled = clkt_is_test_mode_enabled();

    // Build version timeline data with proper WordPress-Arweave matching
    $version_timeline = [];
    $wp_revisions = wp_get_post_revisions($post->ID);
    $revision_count = count($wp_revisions) + 1; // +1 for current

    // Create a map of Arweave uploads indexed by their WordPress revision count
    // Filter based on test mode: show only test uploads in test mode, only real uploads in normal mode
    // NOTE: Store ALL uploads for each revision (multiple uploads can have same revision count)
    $arweave_by_wp_version = [];
    foreach ($versions as $version) {
        // Use wp_revision_count if available (new uploads), otherwise we can't match
        if (isset($version['wp_revision_count'])) {
            $version_tx_id = isset($version['id']) ? $version['id'] : (isset($version['tx_id']) ? $version['tx_id'] : '');
            $is_test_tx = clkt_is_test_transaction($version_tx_id);

            // Only include if mode matches: test mode shows test uploads, normal mode shows real uploads
            if ($test_mode_enabled === $is_test_tx) {
                // Store all uploads for this revision in an array
                if (!isset($arweave_by_wp_version[$version['wp_revision_count']])) {
                    $arweave_by_wp_version[$version['wp_revision_count']] = [];
                }
                $arweave_by_wp_version[$version['wp_revision_count']][] = $version;
            }
        }
    }

    // For each revision, keep only the LATEST upload (highest timestamp)
    foreach ($arweave_by_wp_version as $rev_count => $uploads) {
        if (count($uploads) > 1) {
            // Sort by timestamp descending (newest first)
            usort($uploads, function($a, $b) {
                $time_a = $a['timestamp'] ?? 0;
                $time_b = $b['timestamp'] ?? 0;
                return $time_b - $time_a;
            });
            // Keep only the latest
            $arweave_by_wp_version[$rev_count] = $uploads[0];
        } else {
            // Single upload, unwrap from array
            $arweave_by_wp_version[$rev_count] = $uploads[0];
        }
    }

    // Add current version (always the highest version number)
    $current_version_data = [
        'wp_version' => $revision_count,
        'date' => get_post_modified_time('Y-m-d H:i', false, $post->ID),
        'is_current' => true,
        'arweave_status' => 'not-uploaded',
        'transaction_id' => null,
        'confirmation_status' => null
    ];

    // Check if current version has an Arweave upload
    if (isset($arweave_by_wp_version[$revision_count])) {
        $arweave_version = $arweave_by_wp_version[$revision_count];
        $current_version_data['arweave_status'] = 'uploaded';
        $current_version_data['transaction_id'] = isset($arweave_version['arweave_url']) ?
            basename($arweave_version['arweave_url']) :
            (isset($arweave_version['id']) ? $arweave_version['id'] : null);
        $current_version_data['confirmation_status'] = isset($arweave_version['confirmation_status']) ?
            $arweave_version['confirmation_status'] : 'confirmed';
        $current_version_data['upload_timestamp'] = $arweave_version['timestamp'] ?? null;
    }

    $version_timeline[] = $current_version_data;

    // Add all WordPress revisions with their corresponding Arweave uploads (if any)
    $version_num = $revision_count - 1;
    foreach ($wp_revisions as $revision) {
        $revision_data = [
            'wp_version' => $version_num,
            'date' => get_post_modified_time('Y-m-d H:i', false, $revision->ID),
            'is_current' => false,
            'arweave_status' => 'not-uploaded',
            'transaction_id' => null,
            'confirmation_status' => null
        ];

        // Check if this revision has an Arweave upload
        if (isset($arweave_by_wp_version[$version_num])) {
            $arweave_version = $arweave_by_wp_version[$version_num];
            $revision_data['arweave_status'] = 'uploaded';
            $revision_data['transaction_id'] = isset($arweave_version['arweave_url']) ?
                basename($arweave_version['arweave_url']) :
                (isset($arweave_version['id']) ? $arweave_version['id'] : null);
            $revision_data['confirmation_status'] = isset($arweave_version['confirmation_status']) ?
                $arweave_version['confirmation_status'] : 'confirmed';
            $revision_data['upload_timestamp'] = $arweave_version['timestamp'] ?? null;
        }

        $version_timeline[] = $revision_data;
        $version_num--;
    }

    ?>
    <div class="clkt-permaweb-container" data-post-id="<?php echo esc_attr($post->ID); ?>">
        <!-- Header -->
        <div class="clkt-header">
            <h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; color: #1d2327;">Wallet Connection</h4>
            <!-- Wallet Status -->
            <div class="clkt-wallet-status" id="clkt-wallet-status">
                <div class="clkt-wallet-info">
                    <svg class="clkt-wallet-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M4 4a2 2 0 00-2 2v1h16v4.586l-3.707-3.707a1 1 0 00-1.414 0L8 12.757 5.121 9.88a1 1 0 00-1.414 0L2 11.586V6a2 2 0 012-2h12zm0 10.758l2-2 2.879 2.879a1 1 0 001.414 0L16 9.93V14a2 2 0 01-2 2H4zm12-12H4a4 4 0 00-4 4v8a4 4 0 004 4h12a4 4 0 004-4V6a4 4 0 00-4-4z" />
                    </svg>
                    <span class="clkt-wallet-address">Checking...</span>
                    <span class="clkt-wallet-badge clkt-wallet-status-badge">Checking...</span>
                </div>
                <div class="clkt-wallet-actions">
                    <button class="clkt-wallet-connect" style="display:none;">Connect</button>
                    <button class="clkt-wallet-disconnect" style="display:none;">Disconnect</button>
                </div>
            </div>
        </div>

        <!-- Accessibility: Live region for status announcements -->
        <div id="clkt-status-announcer" role="status" aria-live="polite" aria-atomic="true" class="screen-reader-text"></div>

        <!-- Main Content -->
        <div class="clkt-main">
            <!-- Post Upload Settings Section -->
            <div class="clkt-section">
                <?php
                $is_published = ($post->post_status === 'publish');
                ?>
                <!-- Always visible status panel -->
                <div class="clkt-auto-save-message <?php echo esc_attr( $status_class ); ?>">
                    <!-- Status header -->
                    <div class="clkt-auto-save-title">
                        <?php if ($will_be_saved): ?>
                            <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>
                            <span>Auto-save Enabled</span>
                        <?php else: ?>
                            <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd" />
                            </svg>
                            <span>Auto-save Disabled</span>
                        <?php endif; ?>
                    </div>

                    <!-- Status description -->
                    <div class="clkt-auto-save-desc">
                        <?php if ($will_be_saved): ?>
                            <div class="clkt-status-item">
                                <?php if ($is_published): ?>
                                    Post will upload on save
                                <?php else: ?>
                                    Post will upload on publish
                                <?php endif; ?>
                            </div>
                            <?php if ($encrypt_content): ?>
                                <?php
                                $encryption_label = 'wallet key';
                                if ($encryption_method === 'global')
                                    $encryption_label = 'global password';
                                elseif ($encryption_method === 'per-post')
                                    $encryption_label = 'per-post password';
                                ?>
                                <div class="clkt-status-item" style="color: #646970; font-size: 12px;">
                                    🔒 Encrypted with <?php echo esc_html( $encryption_label ); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="clkt-status-item">
                                Post will not upload to Arweave
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Expandable Settings Section -->
                    <div class="clkt-settings-toggle" onclick="toggleSettings(this)"
                        style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #dcdcde; cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                        <span
                            style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">Settings</span>
                        <svg class="clkt-settings-chevron" width="16" height="16" fill="currentColor" viewBox="0 0 20 20"
                            style="transition: transform 0.2s;">
                            <path fill-rule="evenodd"
                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                clip-rule="evenodd" />
                        </svg>
                    </div>

                    <!-- Collapsible Settings Content -->
                    <div class="clkt-settings-content" style="display: none; margin-top: 8px;">

                        <!-- Auto-save heading -->
                        <div
                            style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">
                            Auto-save</div>

                        <!-- Global Settings -->
                        <div class="clkt-setting-row">
                            <div class="clkt-setting-label">
                                <a href="<?php echo esc_url( admin_url('options-general.php?page=clkt-settings') ); ?>"
                                    style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 4px;">
                                    <span>Global auto-save</span>
                                    <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20"
                                        style="opacity: 0.5;">
                                        <path
                                            d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                                        <path
                                            d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                                    </svg>
                                </a>
                            </div>
                            <div class="clkt-setting-controls">
                                <span class="clkt-badge <?php echo $global_auto_save ? 'on' : 'off'; ?>">
                                    <?php echo $global_auto_save ? 'ON' : 'OFF'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Per-Post Setting -->
                        <div class="clkt-setting-row">
                            <div class="clkt-setting-label">
                                <span>This post</span>
                            </div>
                            <select class="clkt-select" id="clkt-per-post-setting"
                                data-post-id="<?php echo esc_attr($post->ID); ?>"
                                data-initial-value="<?php echo esc_attr($per_post_setting); ?>">
                                <option value="default" <?php selected($per_post_setting, 'default'); ?>>Default</option>
                                <option value="always" <?php selected($per_post_setting, 'always'); ?>>Always</option>
                                <option value="never" <?php selected($per_post_setting, 'never'); ?>>Never</option>
                            </select>
                        </div>

                        <!-- Upload Mode Setting -->
                        <?php if ($per_post_setting !== 'never'):
                            $upload_mode = get_post_meta($post->ID, '_clkt_upload_mode', true);
                            if (empty($upload_mode))
                                $upload_mode = 'new_only';
                            ?>
                            <div class="clkt-setting-row">
                                <div class="clkt-setting-label">
                                    <span>Upload mode</span>
                                </div>
                                <select class="clkt-select" id="clkt-upload-mode"
                                    data-post-id="<?php echo esc_attr($post->ID); ?>">
                                    <option value="new_only" <?php selected($upload_mode, 'new_only'); ?>>New & updated only
                                    </option>
                                    <option value="force_reupload" <?php selected($upload_mode, 'force_reupload'); ?>>Force
                                        re-upload all</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Encryption heading -->
                        <div
                            style="font-size: 11px; font-weight: 600; color: #6b7280; margin-top: 8px; margin-bottom: 6px; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">
                            Encryption</div>

                        <!-- Encrypt Content -->
                        <div class="clkt-setting-row">
                            <div class="clkt-setting-label">
                                <label for="clkt_encrypt_content">Encrypt content</label>
                            </div>
                            <label class="clkt-toggle">
                                <input type="checkbox" id="clkt_encrypt_content"
                                       role="switch"
                                       aria-checked="<?php echo $encrypt_content ? 'true' : 'false'; ?>"
                                       aria-label="Enable content encryption"
                                       <?php checked($encrypt_content); ?>>
                                <span class="clkt-toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>

                        <!-- Encryption Method (shown when encryption is on) -->
                        <div id="clkt-encryption-options" class="clkt-subsection"
                            style="display: <?php echo $encrypt_content ? 'block' : 'none'; ?>;">
                            <div class="clkt-setting-row">
                                <div class="clkt-setting-label">
                                    <span>Method</span>
                                </div>
                                <select class="clkt-select" id="clkt-encryption-method">
                                    <option value="wallet" <?php selected($encryption_method, 'wallet'); ?>>Wallet Key
                                    </option>
                                    <option value="global" <?php selected($encryption_method, 'global'); ?>>Global Password
                                    </option>
                                    <option value="per-post" <?php selected($encryption_method, 'per-post'); ?>>Per-post
                                        Password</option>
                                </select>
                            </div>

                            <!-- Per-post password input (shown when per-post is selected) -->
                            <div id="clkt-per-post-password-container"
                                style="display: <?php echo $encryption_method === 'per-post' ? 'block' : 'none'; ?>; margin-top: 8px;">
                                <input type="password" id="clkt_encryption_password" class="clkt-password-input"
                                    placeholder="Enter per-post password"
                                    value="<?php echo esc_attr($per_post_password); ?>">
                            </div>

                        </div>

                        <!-- Attribution heading -->
                        <div
                            style="font-size: 11px; font-weight: 600; color: #6b7280; margin-top: 12px; margin-bottom: 6px; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">
                            Attribution</div>

                        <!-- License -->
                        <div class="clkt-setting-row">
                            <div class="clkt-setting-label">
                                <label for="clkt-license-spdx">License</label>
                            </div>
                            <select class="clkt-select" id="clkt-license-spdx"
                                data-post-id="<?php echo esc_attr($post->ID); ?>">
                                <option value="" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', ''); ?>>None</option>
                                <option value="CC-BY-4.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'CC-BY-4.0'); ?>>CC BY 4.0</option>
                                <option value="CC-BY-SA-4.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'CC-BY-SA-4.0'); ?>>CC BY-SA 4.0</option>
                                <option value="CC-BY-NC-4.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'CC-BY-NC-4.0'); ?>>CC BY-NC 4.0</option>
                                <option value="CC-BY-NC-SA-4.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'CC-BY-NC-SA-4.0'); ?>>CC BY-NC-SA 4.0</option>
                                <option value="CC0-1.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'CC0-1.0'); ?>>CC0 1.0</option>
                                <option value="MIT" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'MIT'); ?>>MIT</option>
                                <option value="Apache-2.0" <?php selected(isset($license['spdx']) ? $license['spdx'] : '', 'Apache-2.0'); ?>>Apache 2.0</option>
                                <option value="custom" <?php
                                    $known = array('', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'CC-BY-NC-4.0', 'CC-BY-NC-SA-4.0', 'CC0-1.0', 'MIT', 'Apache-2.0');
                                    $current_spdx = isset($license['spdx']) ? $license['spdx'] : '';
                                    if ($current_spdx && !in_array($current_spdx, $known)) echo 'selected';
                                ?>>Custom...</option>
                            </select>
                        </div>

                        <!-- Custom SPDX input (shown when custom is selected) -->
                        <?php
                        $is_custom = ($current_spdx && !in_array($current_spdx, $known));
                        ?>
                        <div id="clkt-license-custom-container" style="display: <?php echo $is_custom ? 'block' : 'none'; ?>; margin-bottom: 8px;">
                            <input type="text" id="clkt-license-custom-spdx" class="clkt-text-input"
                                placeholder="SPDX identifier (e.g. GPL-3.0-only)"
                                value="<?php echo esc_attr($is_custom ? $current_spdx : ''); ?>">
                        </div>

                        <!-- License URL (auto-filled, editable) -->
                        <div id="clkt-license-url-container" style="display: <?php echo $current_spdx ? 'block' : 'none'; ?>; margin-bottom: 8px;">
                            <input type="url" id="clkt-license-url" class="clkt-text-input"
                                placeholder="License URL"
                                value="<?php echo esc_attr(isset($license['url']) ? $license['url'] : ''); ?>">
                        </div>

                        <!-- License note -->
                        <div id="clkt-license-note-container" style="display: <?php echo $current_spdx ? 'block' : 'none'; ?>; margin-bottom: 8px;">
                            <input type="text" id="clkt-license-note" class="clkt-text-input"
                                placeholder="Note (e.g. Attribution required)"
                                value="<?php echo esc_attr(isset($license['note']) ? $license['note'] : ''); ?>">
                        </div>

                        <!-- Derived From -->
                        <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-top: 8px; margin-bottom: 6px; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">
                            Derived From</div>

                        <div id="clkt-derived-from-list" data-post-id="<?php echo esc_attr($post->ID); ?>">
                            <?php if (!empty($derived_from)): ?>
                                <?php foreach ($derived_from as $i => $ref): ?>
                                    <div class="clkt-derived-entry" data-index="<?php echo esc_attr($i); ?>">
                                        <div class="clkt-derived-entry-header">
                                            <select class="clkt-select clkt-derived-relationship">
                                                <option value="citation" <?php selected(isset($ref['relationship']) ? $ref['relationship'] : '', 'citation'); ?>>Citation</option>
                                                <option value="response" <?php selected(isset($ref['relationship']) ? $ref['relationship'] : '', 'response'); ?>>Response</option>
                                                <option value="translation" <?php selected(isset($ref['relationship']) ? $ref['relationship'] : '', 'translation'); ?>>Translation</option>
                                                <option value="remix" <?php selected(isset($ref['relationship']) ? $ref['relationship'] : '', 'remix'); ?>>Remix</option>
                                                <option value="fork" <?php selected(isset($ref['relationship']) ? $ref['relationship'] : '', 'fork'); ?>>Fork</option>
                                            </select>
                                            <button type="button" class="clkt-derived-remove" title="Remove">&times;</button>
                                        </div>
                                        <input type="text" class="clkt-text-input clkt-derived-title" placeholder="Title" value="<?php echo esc_attr(isset($ref['title']) ? $ref['title'] : ''); ?>">
                                        <input type="text" class="clkt-text-input clkt-derived-author" placeholder="Author" value="<?php echo esc_attr(isset($ref['author']) ? $ref['author'] : ''); ?>">
                                        <input type="text" class="clkt-text-input clkt-derived-url" placeholder="URL" value="<?php echo esc_attr(isset($ref['url']) ? $ref['url'] : ''); ?>">
                                        <input type="text" class="clkt-text-input clkt-derived-txid" placeholder="Arweave TX ID (optional)" value="<?php echo esc_attr(isset($ref['txId']) ? $ref['txId'] : ''); ?>">
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button clkt-derived-add" style="width: 100%; margin-top: 4px; font-size: 12px;">+ Add reference</button>

                    </div>
                    <!-- End collapsible settings content -->
                </div>

            </div>

            <!-- New Upload Component Container -->
            <?php if ($per_post_setting !== 'never'): ?>
                <div id="clkt-single-upload-container" style="margin-top: 8px; margin-left: 0; padding-left: 0;"
                    data-per-post-setting="<?php echo esc_attr($per_post_setting); ?>"
                    data-will-be-saved="<?php echo $will_be_saved ? '1' : '0'; ?>"
                    data-global-auto-save="<?php echo $global_auto_save ? '1' : '0'; ?>"></div>
            <?php endif; ?>

            <!-- Unified Version Timeline -->
            <?php if (!empty($version_timeline)): ?>
                <div class="clkt-separator"></div>
                <div class="clkt-section">
                    <!-- Current Version Status (always visible) -->
                    <div class="clkt-current-status-header">
                        <?php
                        $current_item = $version_timeline[0]; // Current version is always first
                        ?>
                        <div class="clkt-timeline-item" style="padding-bottom: 0;">
                            <div class="clkt-timeline-dot <?php
                            echo $current_item['arweave_status'] === 'uploaded' ? 'current uploaded' : 'current not-uploaded';
                            ?>">
                                <?php echo esc_html( $current_item['wp_version'] ); ?>
                            </div>
                            <div class="clkt-timeline-details" style="flex: 1;">
                                <div class="clkt-timeline-version">
                                    Version <?php echo esc_html( $current_item['wp_version'] ); ?>
                                    <span style="color: #8b5cf6;"> (Current)</span>
                                </div>
                                <div class="clkt-timeline-date"><?php echo esc_html( $current_item['date'] ); ?></div>
                                <?php if ($current_item['arweave_status'] === 'uploaded'): ?>
                                    <div class="clkt-timeline-status">
                                        <?php
                                        $is_test = clkt_is_test_transaction($current_item['transaction_id']);
                                        if ($is_test):
                                            ?>
                                            <span class="clkt-timeline-badge test">🧪 Test Upload</span>
                                        <?php else: ?>
                                            <span class="clkt-timeline-badge confirmed">✓ Uploaded</span>
                                            <a href="<?php echo esc_url('https://arweave.net/' . $current_item['transaction_id']); ?>"
                                                target="_blank" class="clkt-timeline-link">
                                                <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">
                                                    <path
                                                        d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                                                    <path
                                                        d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php echo wp_kses_post(\clkt_render_restore_proof_button($post->ID, $current_item['transaction_id'])); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="clkt-timeline-status">
                                        <span class="clkt-timeline-badge" style="background: #fef3c7; color: #92400e;">
                                            Not uploaded
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (count($version_timeline) > 1): ?>
                        <!-- Expandable older versions -->
                        <div class="clkt-timeline-header" onclick="toggleTimeline(this)"
                            style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0v-4a1 1 0 00-1-1z"
                                        clip-rule="evenodd" />
                                </svg>
                                <span>Previous Versions (<?php echo count($version_timeline) - 1; ?>)</span>
                            </div>
                            <svg class="clkt-timeline-chevron" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                    clip-rule="evenodd" />
                            </svg>
                        </div>

                        <div class="clkt-timeline-content">
                            <div class="clkt-timeline-line"></div>
                            <?php
                            // Skip the first item (current version) as it's shown above
                            $older_versions = array_slice($version_timeline, 1);
                            foreach ($older_versions as $index => $item):
                                ?>
                                <div class="clkt-timeline-item">
                                    <div class="clkt-timeline-dot <?php
                                    echo $item['is_current'] ? 'current' :
                                        ($item['arweave_status'] === 'uploaded' ? 'uploaded' : 'not-uploaded');
                                    ?>">
                                        <?php echo esc_html( $item['wp_version'] ); ?>
                                    </div>
                                    <div class="clkt-timeline-details">
                                        <div class="clkt-timeline-version">
                                            Version <?php echo esc_html( $item['wp_version'] ); ?>
                                            <?php if ($item['is_current']): ?>
                                                <span style="color: #8b5cf6;"> (Current)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="clkt-timeline-date"><?php echo esc_html( $item['date'] ); ?></div>
                                        <?php if ($item['arweave_status'] === 'uploaded'): ?>
                                            <div class="clkt-timeline-status">
                                                <?php
                                                $is_test = clkt_is_test_transaction($item['transaction_id']);
                                                if ($is_test):
                                                    ?>
                                                    <span class="clkt-timeline-badge test">🧪 Test Upload</span>
                                                <?php else: ?>
                                                    <span class="clkt-timeline-badge <?php echo esc_attr( $item['confirmation_status'] ); ?>">
                                                        <?php echo esc_html( $item['confirmation_status'] ); ?>
                                                    </span>
                                                    <a href="<?php echo esc_url('https://arweave.net/' . $item['transaction_id']); ?>"
                                                        target="_blank" class="clkt-timeline-link">
                                                        <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">
                                                            <path
                                                                d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                                                            <path
                                                                d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                                                        </svg>
                                                    </a>
                                                <?php endif; ?>
                                                <?php echo wp_kses_post(\clkt_render_restore_proof_button($post->ID, $item['transaction_id'])); ?>
                                            </div>
                                            <?php if ($item['transaction_id']): ?>
                                                <div class="clkt-timeline-tx">
                                                    <?php echo esc_html( substr($item['transaction_id'], 0, 8) ) . '...' . esc_html( substr($item['transaction_id'], -6) ); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="clkt-timeline-status">
                                                <span class="clkt-timeline-badge" style="background: #f3f4f6; color: #6b7280;">
                                                    Not uploaded
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Hidden fields for JavaScript -->
        <input type="hidden" id="clkt-global-auto-save" value="<?php echo $global_auto_save ? '1' : '0'; ?>">
        <input type="hidden" id="clkt-post-id" value="<?php echo esc_attr($post->ID); ?>">
        <div id="clkt-restore-proof-panel" class="clkt-restore-proof-panel" hidden></div>

    </div>
    <?php
}

// Enqueue metabox styles and scripts on post edit screens
function clkt_enqueue_metabox_assets($hook)
{
    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }

    if (!clkt_is_supported_post_type(clkt_get_current_admin_post_type())) {
        return;
    }

    wp_enqueue_style(
        'clkt-metabox',
        CLKT_PLUGIN_URL . 'assets/css/metabox.css',
        array(),
        CLKT_VERSION
    );

    wp_enqueue_script(
        'clkt-metabox',
        CLKT_PLUGIN_URL . 'assets/js/metabox.js',
        array(),
        CLKT_VERSION,
        true
    );
}

// Hook the meta box registration
add_action('add_meta_boxes', __NAMESPACE__ . '\\clkt_permaweb_meta_box');

// Hook the metabox asset enqueue
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\clkt_enqueue_metabox_assets');
