<?php
/**
 * CLKT Consolidated Settings Page
 * 
 * Combines all settings into a single tabbed interface
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue styles and scripts for the CLKT settings page
 */
function clkt_settings_page_enqueue($hook) {
    if ($hook !== 'settings_page_clkt-settings') {
        return;
    }

    // Main settings page CSS
    $settings_css = '
        .clkt-settings-container {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 0 0 6px 6px;
            margin-top: -1px;
            padding: 20px;
        }
        .clkt-tab-content { display: none; }
        .clkt-tab-content.active { display: block; }
        .clkt-settings-section { margin-bottom: 30px; }
        .clkt-settings-section h3 { margin-top: 0; font-size: 1.1em; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px; }
        .clkt-info-box { background: #f0f8ff; border: 1px solid #007cba; border-radius: 4px; padding: 15px; margin: 20px 0; }
        .clkt-info-box h4 { margin-top: 0; color: #007cba; }
    ';

    // Wallet settings tab CSS
    $settings_css .= '
        .wallet-status-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .wallet-status-indicator.connected { background: #00a32a; }
        .wallet-status-indicator.disconnected { background: #d63638; }
        .wallet-address-display { font-family: monospace; background: #f0f0f0; padding: 4px 8px; border-radius: 4px; }
        .wallet-connection-box { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px; margin-bottom: 20px; }
    ';

    wp_register_style('clkt-settings-page', false, array(), CLKT_VERSION);
    wp_enqueue_style('clkt-settings-page');
    wp_add_inline_style('clkt-settings-page', $settings_css);

    // General settings tab JS (wallet status check)
    wp_enqueue_script(
        'clkt-settings-general',
        plugin_dir_url(__FILE__) . 'settings-general.js',
        array('jquery'),
        CLKT_VERSION,
        true
    );

    // Wallet settings tab JS (wallet connect/disconnect/refresh)
    wp_enqueue_script(
        'clkt-settings-wallet',
        plugin_dir_url(__FILE__) . 'settings-wallet.js',
        array('jquery'),
        CLKT_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'clkt_settings_page_enqueue');

/**
 * Render the consolidated settings page with tabs
 */
function clkt_render_consolidated_settings_page() {
    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <!-- Tabs Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=clkt-settings&tab=general" 
               class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                General Settings
            </a>
            <a href="?page=clkt-settings&tab=wallet" 
               class="nav-tab <?php echo $current_tab === 'wallet' ? 'nav-tab-active' : ''; ?>">
                Wallet Settings
            </a>
            <a href="?page=clkt-settings&tab=bulk-upload" 
               class="nav-tab <?php echo $current_tab === 'bulk-upload' ? 'nav-tab-active' : ''; ?>">
                Bulk Upload
            </a>
        </h2>



        <div class="clkt-settings-container">
            <?php
            // Render the appropriate tab content
            switch ($current_tab) {
                case 'general':
                    clkt_render_general_settings_tab();
                    break;
                case 'wallet':
                    clkt_render_wallet_settings_tab();
                    break;
                case 'bulk-upload':
                    clkt_render_bulk_upload_tab();
                    break;
                default:
                    clkt_render_general_settings_tab();
            }
            ?>
        </div>
        
        <!-- Support Section -->
        <div style="margin-top: 40px; padding: 20px; background: #f1f1f1; border-left: 4px solid #0073aa;">
            <h2 style="margin-top: 0;">Need Help?</h2>
            <p>We're here to help you save forever!</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><a href="https://getclkt.com/docs" target="_blank">Documentation</a> - Getting started guides and tutorials</li>
                <li><a href="https://github.com/divydovy/clkt-save-forever-collect-together/issues" target="_blank">Report an Issue</a> - Found a bug? Let us know!</li>
                <li><a href="mailto:support@getclkt.com?subject=CLKT%20Support%20Request&body=WordPress%20Version:%20<?php echo esc_attr(get_bloginfo('version')); ?>%0ACLKT%20Version:%20<?php echo esc_attr(CLKT_VERSION); ?>%0A%0ADescribe%20your%20issue:%20">Email Support</a> - Get direct help</li>
                <li><a href="https://wordpress.org/support/plugin/clkt/" target="_blank">WordPress Forums</a> - Community support</li>
            </ul>
            <p style="margin-top: 15px;"><strong>Love CLKT?</strong> <a href="https://wordpress.org/support/plugin/clkt/reviews/#new-post" target="_blank">Leave a review</a> to help others discover permanent content storage!</p>
        </div>
    </div>
    <?php
}

/**
 * Render General Settings Tab
 */
function clkt_render_general_settings_tab() {
    ?>
    <!-- Wallet Warning -->
    <div id="clkt-general-wallet-warning" style="display: none; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-bottom: 20px; color: #856404;">
        <strong>⚠️ No Wallet Connected</strong>
        <p style="margin: 8px 0 0 0;">You need to connect an Arweave wallet to upload content. Please go to the <a href="?page=clkt-settings&tab=wallet" style="color: #0073aa;">Wallet Settings</a> tab to connect your wallet.</p>
    </div>

    <form method="post" action="options.php">
        <?php
        settings_fields('clkt_settings');
        do_settings_sections('clkt_settings');
        ?>

        <div class="clkt-settings-section">
            <h3>Display Settings</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="clkt_show_permaweb_on_public">Show on Public Posts</label>
                    </th>
                    <td>
                        <select name="clkt_show_permaweb_on_public" id="clkt_show_permaweb_on_public">
                            <option value="yes" <?php selected(get_option('clkt_show_permaweb_on_public', 'yes'), 'yes'); ?>>Yes</option>
                            <option value="no" <?php selected(get_option('clkt_show_permaweb_on_public', 'yes'), 'no'); ?>>No</option>
                        </select>
                        <p class="description">Display Permaweb versions on public-facing posts</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="clkt_show_powered_by">Show "Powered by CLKT"</label>
                    </th>
                    <td>
                        <select name="clkt_show_powered_by" id="clkt_show_powered_by">
                            <option value="no" <?php selected(get_option('clkt_show_powered_by', 'no'), 'no'); ?>>No</option>
                            <option value="yes" <?php selected(get_option('clkt_show_powered_by', 'no'), 'yes'); ?>>Yes</option>
                        </select>
                        <p class="description">Display a "Powered by CLKT" credit link below Permaweb versions on public posts</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="clkt_permaweb_public_heading">Public Section Heading</label>
                    </th>
                    <td>
                        <input type="text" name="clkt_permaweb_public_heading" id="clkt_permaweb_public_heading" class="regular-text" 
                               value="<?php echo esc_attr(get_option('clkt_permaweb_public_heading', 'CLKT - Permanent Record')); ?>">
                        <p class="description">Heading text shown above Permaweb versions on public posts</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="clkt-settings-section">
            <h3>Upload Settings</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="clkt_max_allowed_upload_mb">Maximum Upload Size (MB)</label>
                    </th>
                    <td>
                        <input type="number" name="clkt_max_allowed_upload_mb" id="clkt_max_allowed_upload_mb"
                               value="<?php echo esc_attr(get_option('clkt_max_allowed_upload_mb', 100)); ?>"
                               min="1" max="500" class="small-text"> MB
                        <p class="description">Maximum file size allowed for uploads. Files larger than this will be rejected. Default: 100MB.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="clkt_free_dispatch_limit_kb">Free Dispatch Limit (KB)</label>
                    </th>
                    <td>
                        <input type="number" name="clkt_free_dispatch_limit_kb" id="clkt_free_dispatch_limit_kb"
                               value="<?php echo esc_attr(get_option('clkt_free_dispatch_limit_kb', 100)); ?>"
                               min="1" max="500" class="small-text"> KB
                        <p class="description">
                            Files under this size upload for free via Arweave bundling (no AR cost). Default: 100KB.<br>
                            <strong>Note:</strong> This is determined by Arweave's bundling service. Only change if the service limit changes and the plugin hasn't been updated.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="clkt_global_auto_save">Global Auto-Save to Permaweb</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="clkt_global_auto_save" id="clkt_global_auto_save"
                                   value="1" <?php checked(get_option('clkt_global_auto_save', false)); ?> />
                            Automatically save all posts to Permaweb when published or updated
                        </label>
                        <p class="description">When enabled, all posts will be automatically saved to the Permaweb unless individually disabled. Individual posts can override this setting.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="clkt_global_encryption_password">Global Encryption Password</label>
                    </th>
                    <td>
                        <input type="password" name="clkt_global_encryption_password" id="clkt_global_encryption_password" class="regular-text"
                               value="<?php echo esc_attr(get_option('clkt_global_encryption_password', '')); ?>">
                        <p class="description">Default password for encrypting posts. Individual posts can override this setting. Leave empty for no encryption.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="clkt_test_mode">🧪 Test Mode</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="clkt_test_mode" id="clkt_test_mode"
                                   value="1" <?php checked(get_option('clkt_test_mode', false)); ?> />
                            Enable test mode (simulates uploads without spending AR)
                        </label>
                        <p class="description">
                            <strong>⚠️ Testing Only:</strong> When enabled, uploads will be simulated with fake transaction IDs.
                            No real AR will be spent and nothing will be uploaded to Arweave.
                            All validation logic (size limits, encryption, etc.) still runs normally.
                            Perfect for development and automated testing.
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="clkt-settings-section">
            <h3>Wallet Configuration</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="clkt_wallet_type">Wallet Type</label>
                    </th>
                    <td>
                        <!-- Default to self-managed wallet for initial_release branch -->
                        <input type="hidden" name="clkt_wallet_type" id="clkt_wallet_type" value="self">
                        <p><strong>Self-Managed Wallet Mode</strong></p>
                        <p class="description">
                            Use your own Arweave wallet to upload content to the permaweb.
                        </p>
                    </td>
                </tr>
                
                <tr id="wallet-provider-row">
                    <th scope="row">
                        <label for="clkt_wallet_provider">Wallet Provider</label>
                    </th>
                    <td>
                        <select name="clkt_wallet_provider" id="clkt_wallet_provider">
                            <option value="wander" <?php selected(get_option('clkt_wallet_provider', 'wander'), 'wander'); ?>>Wander</option>
                        </select>
                        <p class="description">
                            Browser extension for managing your Arweave wallet.
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="clkt_show_cost_estimate">Show Cost Estimates</label>
                    </th>
                    <td>
                        <input type="checkbox" name="clkt_show_cost_estimate" id="clkt_show_cost_estimate" value="1"
                               <?php checked(get_option('clkt_show_cost_estimate', '1'), '1'); ?>>
                        <label for="clkt_show_cost_estimate">Display estimated AR costs before upload</label>
                    </td>
                </tr>
            </table>
        </div>
        

        
        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Render Wallet Settings Tab
 */
function clkt_render_wallet_settings_tab() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('clkt_wallet_settings');
        ?>
        
        <div class="clkt-info-box">
            <h4>🔐 Arweave Wallet Connection</h4>
            <p>Connect your own Arweave wallet to upload content permanently to the permaweb.</p>
        </div>
        
        <div class="clkt-settings-section">
            <h3>Wallet Connection Status</h3>
            <div class="wallet-connection-box">
                <div id="wallet-status-container">
                    <div style="margin-bottom: 15px;">
                        <span class="wallet-status-indicator disconnected" id="status-indicator"></span>
                        <strong>Status:</strong> <span id="wallet-status-text">Not Connected</span>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong>Wallet Address:</strong>
                        <span id="wallet-address" class="wallet-address-display">Not connected</span>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong>Balance:</strong> <span id="wallet-balance">-</span>
                    </div>
                    <div id="content-collection-url" style="margin-bottom: 20px; display: none; padding: 12px; background: #f0f8ff; border-left: 4px solid #0073aa; border-radius: 4px;">
                        <strong>📚 Your Content Collection:</strong>
                        <p style="margin: 8px 0 4px 0; font-size: 0.9em; color: #666;">View all your uploaded content. These links work even if your WordPress site goes down.</p>
                        <?php if (get_option('clkt_test_mode', false)): ?>
                        <div style="background: #fcf9e8; border: 1px solid #dba617; padding: 10px; margin: 12px 0; border-radius: 4px;">
                            <p style="margin: 0; font-size: 0.9em; color: #856404;">
                                🧪 <strong>Test Mode Active:</strong> View links are disabled because test uploads don't create real Arweave transactions.
                            </p>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 8px; <?php echo get_option('clkt_test_mode', false) ? 'opacity: 0.4; pointer-events: none;' : ''; ?>">
                            <strong>📱 Simple Content Viewer (Easy to use!):</strong><br>
                            <a id="permanent-viewer-link" href="#" target="_blank" style="color: #0073aa; word-break: break-all; font-size: 0.9em;"></a>
                            <br><span style="font-size: 0.85em; color: #28a745;">✓ Best for non-technical users: Beautiful cards showing all your posts and media.</span>
                            <br><span style="font-size: 0.85em; color: #28a745;">✓ Permanently hosted on Arweave - works forever!</span>
                            <details style="margin-top: 8px; font-size: 0.85em;">
                                <summary style="cursor: pointer; color: #0073aa;"><strong>📤 Want to upload your own custom viewer?</strong></summary>
                                <div style="margin-top: 8px; padding: 10px; background: #f0f8ff; border-left: 3px solid #0073aa;">
                                    <p style="margin: 0 0 8px 0;">You can customize the viewer and upload it to Arweave:</p>
                                    <ol style="margin: 8px 0; padding-left: 20px;">
                                        <li>Go to <a href="<?php echo esc_url(admin_url('options-general.php?page=clkt-upload-viewer')); ?>" style="color: #0073aa;"><strong>Settings → CLKT Upload Viewer</strong></a></li>
                                        <li>Click "1️⃣ Connect Wallet" and approve in Wander</li>
                                        <li>Click "2️⃣ Upload to Arweave"</li>
                                        <li>Save the permanent URL you receive</li>
                                    </ol>
                                    <p style="margin: 8px 0 0 0; color: #666;"><em>Note: The viewer is automatically customized with your wallet address.</em></p>
                                </div>
                            </details>
                        </div>
                        <div style="margin-top: 8px; <?php echo get_option('clkt_test_mode', false) ? 'opacity: 0.4; pointer-events: none;' : ''; ?>">
                            <strong>ArScan Explorer (Shows ALL uploads including bundled posts):</strong><br>
                            <a id="arscan-link" href="#" target="_blank" style="color: #0073aa; word-break: break-all; font-size: 0.9em;"></a>
                            <br><span style="font-size: 0.85em; color: #28a745;">✓ Recommended: Shows both L1 transactions AND bundled data items.</span>
                            <br><span style="font-size: 0.85em; color: #666;">💡 To view transaction content: Click any transaction, copy its ID, then visit: <code>https://arweave.net/[TX-ID]</code></span>
                        </div>
                        <div style="margin-top: 8px; <?php echo get_option('clkt_test_mode', false) ? 'opacity: 0.4; pointer-events: none;' : ''; ?>">
                            <strong>ViewBlock Explorer (L1 transactions only):</strong><br>
                            <a id="viewblock-link" href="#" target="_blank" style="color: #0073aa; word-break: break-all; font-size: 0.9em;"></a>
                            <br><span style="font-size: 0.85em; color: #999;">Shows large files only. Bundled uploads (posts < 100KB) won't appear.</span>
                        </div>
                        <div style="margin-top: 8px;">
                            <details style="font-size: 0.9em;">
                                <summary style="cursor: pointer; color: #666;"><strong>GraphQL Query (Advanced)</strong></summary>
                                <div style="margin-top: 8px;">
                                    <a id="goldsky-gql-link" href="#" target="_blank" style="color: #0073aa; word-break: break-all; font-size: 0.9em;"></a>
                                    <br><span style="font-size: 0.85em; color: #999;">Query to find all your ContentCloud uploads including bundled posts:</span>
                                    <div style="margin-top: 8px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 0.8em; overflow-x: auto;">
                                        <pre id="gql-query-display" style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"></pre>
                                    </div>
                                    <button type="button" class="button button-secondary" id="copy-gql-btn" style="margin-top: 8px;">
                                        📋 Copy Query to Clipboard
                                    </button>
                                </div>
                            </details>
                        </div>
                    </div>
                    
                    <div class="wallet-actions">
                        <button type="button" class="button button-primary" id="connect-wallet-btn">
                            Connect Wallet
                        </button>
                        <button type="button" class="button" id="disconnect-wallet-btn" style="display:none;">
                            Disconnect
                        </button>
                        <button type="button" class="button button-secondary" id="refresh-balance-btn" style="display:none;">
                            Refresh Balance
                        </button>
                    </div>
                    
                    <div id="wallet-message" style="margin-top: 15px; display: none;"></div>
                </div>
            </div>
        </div>
        
        <div class="clkt-settings-section">
            <h3>Getting Started with Arweave</h3>

            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-bottom: 20px;">
                <strong>⚠️ Security Notice:</strong> Always verify URLs before visiting. Double-check that links match the official websites and use secure connections (HTTPS). Be cautious of phishing attempts.
            </div>

            <ol>
                <li><strong>Install Wander Wallet:</strong> Get the browser extension from <a href="https://wander.app" target="_blank">wander.app</a></li>
                <li><strong>Create or Import Wallet:</strong> Set up a new Arweave wallet or import an existing one</li>
                <li><strong>Fund Your Wallet:</strong> Purchase AR tokens from exchanges like:
                    <ul style="margin-top: 5px;">
                        <li><a href="https://www.binance.com" target="_blank">Binance</a></li>
                        <li><a href="https://www.coinbase.com" target="_blank">Coinbase</a></li>
                        <li><a href="https://www.kraken.com" target="_blank">Kraken</a></li>
                    </ul>
                </li>
                <li><strong>Connect Above:</strong> Click "Connect Wallet" to link your wallet to this site</li>
            </ol>
            
            <div class="clkt-info-box" style="background: #fff4e5; border-color: #ffb900; margin-top: 20px;">
                <h4>💡 Cost Information</h4>
                <p><strong>Storage Cost:</strong> Approximately $0.001 per MB for permanent storage</p>
                <p><strong>Example Costs:</strong></p>
                <ul style="margin-top: 5px;">
                    <li>Blog post (text only): < $0.001</li>
                    <li>Post with 5 images (5MB): ~$0.005</li>
                    <li>Photo gallery (50MB): ~$0.05</li>
                </ul>
                <p style="margin-top: 10px;"><em>All content is stored permanently on the Arweave blockchain.</em></p>
            </div>
        </div>
    </form>
    <?php
}

function clkt_render_bulk_upload_tab() {
    ?>


    <!-- Filter controls (used by both old and new UI) -->
    <div class="clkt-bulk-filters" style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h3 style="margin-top: 0;">📦 Bulk Upload to Permaweb</h3>
        <p style="margin-bottom: 20px; color: #666;">Upload multiple posts to Arweave at once. Choose whether to upload only new/changed content or force re-upload of all posts.</p>

        <div class="clkt-filter-row" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 15px;">
            <div class="clkt-filter-item" style="flex: 1;">
                <label for="upload_type" style="display: block; margin-bottom: 5px; font-weight: 600;">Upload Mode:</label>
                <select id="upload_type" name="upload_type" style="width: 100%; padding: 8px;">
                    <option value="new_posts_only">New & Updated Content Only</option>
                    <option value="all_posts">All Posts (Force Re-upload)</option>
                </select>
                <p class="description" style="margin-top: 5px; font-size: 0.9em; color: #666;">
                    <strong>New & Updated:</strong> Only uploads posts that have never been uploaded or have changed since last upload (most efficient).<br>
                    <strong>Force Re-upload:</strong> Re-uploads all posts regardless of previous upload status.
                </p>
            </div>

            <div style="flex-shrink: 0; display: flex; gap: 10px;">
                <button type="button" class="button button-primary" id="clkt-start-bulk-upload" disabled>
                    Start Upload
                </button>
                <button type="button" class="button" id="clkt-cancel-bulk-upload" style="display: none;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Mode Explanation Box -->
    <div id="mode-explanation" class="clkt-info-box" style="background: #f0f8ff; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <h4 style="margin-top: 0; color: #0073aa;">📋 What will be uploaded:</h4>
        <p>Only posts and attachments that have changed or have never been uploaded will be included. Unchanged attachments will be reused from previous uploads.</p>
    </div>

    <!-- Wallet Warning -->
    <div id="clkt-bulk-wallet-warning" style="display: none; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-bottom: 20px; color: #856404;">
        <strong>⚠️ No Wallet Connected</strong>
        <p style="margin: 8px 0 0 0;">You need to connect an Arweave wallet to upload content. Please go to the <a href="?page=clkt-settings&tab=wallet" style="color: #0073aa;">Wallet Settings</a> tab to connect your wallet.</p>
    </div>

    <!-- Test Mode Banner (Persistent) -->
    <?php if (get_option('clkt_test_mode', false)): ?>
    <div id="clkt-bulk-test-mode-banner" style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 12px 12px 12px 8px; margin-bottom: 20px; border-radius: 4px;">
        <div style="display: grid; grid-template-columns: 16px 1fr; gap: 8px;">
            <span style="font-size: 16px; line-height: 1;">🧪</span>
            <div>
                <strong style="color: #1d2327; font-size: 13px; display: block; margin-bottom: 4px;">Test Mode Active</strong>
                <p style="margin: 0; font-size: 12px; color: #646970; line-height: 1.5;">
                    Uploads will be simulated - no AR will be spent. Arweave view links are disabled in test mode.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upload manager initialized here by BulkUploadContext -->
    <div id="clkt-bulk-upload-container" class="clkt-bulk-upload-section"></div>
    <?php
}
