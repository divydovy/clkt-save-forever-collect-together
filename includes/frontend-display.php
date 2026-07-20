<?php
/**
 * Frontend Display Functions
 *
 * Handles displaying Arweave links on public posts
 *
 * @package CLKT
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Arweave links to post content or excerpts
 */
function clkt_add_permaweb_links_to_content($content)
{
    // Only add on supported single content. Block themes can render post content
    // outside the global main query object, so use the loop state here.
    if (!is_singular(clkt_get_supported_post_types()) || !in_the_loop()) {
        return $content;
    }

    if (doing_filter('get_the_excerpt')) {
        return $content;
    }

    // Check if setting is enabled
    $show_on_public = get_option('clkt_show_permaweb_on_public', 'yes');
    if ($show_on_public !== 'yes') {
        return $content;
    }

    // Get the permaweb links HTML
    $permaweb_html = clkt_get_permaweb_links_html(get_the_ID());

    if (empty($permaweb_html)) {
        return $content;
    }

    if (strpos($content, 'clkt-permaweb-links') !== false) {
        return $content;
    }

    return $content . $permaweb_html;
}
add_filter('the_content', 'clkt_add_permaweb_links_to_content', 20);
add_filter('the_excerpt', 'clkt_add_permaweb_links_to_content', 20);

/**
 * Shortcode: [clkt_permaweb_links]
 *
 * Displays Arweave links for the current post
 *
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function clkt_permaweb_links_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'post_id' => get_the_ID(),
        'title' => get_option('clkt_permaweb_public_heading', 'CLKT - Permanent Record'),
        'show_title' => 'yes',
        'show_history' => 'no',
        'no_css' => 'no',
    ), $atts);

    return clkt_get_permaweb_links_html(
        $atts['post_id'],
        $atts['show_title'] === 'yes' ? $atts['title'] : '',
        $atts['show_history'] === 'yes',
        $atts['no_css'] === 'yes'
    );
}
add_shortcode('clkt_permaweb_links', 'clkt_permaweb_links_shortcode');

/**
 * Extract the transaction ID from a saved CLKT version.
 *
 * @param array $version Saved version metadata.
 * @return string Transaction ID.
 */
function clkt_get_version_transaction_id($version)
{
    if (!empty($version['transactionId'])) {
        return (string) $version['transactionId'];
    }

    if (!empty($version['tx_id'])) {
        return (string) $version['tx_id'];
    }

    if (!empty($version['id'])) {
        return (string) $version['id'];
    }

    return '';
}

/**
 * Generate the hosted CLKT collect URL for a saved record.
 *
 * @param int    $post_id Post ID.
 * @param string $tx_id Arweave transaction ID.
 * @param array  $version Saved version metadata.
 * @return string Collect URL.
 */
function clkt_get_collect_url($post_id, $tx_id, $version = array())
{
    $base_url = apply_filters('clkt_collect_url_base', 'https://app.getclkt.com/collect', $post_id, $tx_id, $version);
    $source_url = get_permalink($post_id);
    $fragment_args = array(
        'tx' => $tx_id,
        'schema' => 'clkt-record-v1',
        'source' => $source_url ? $source_url : '',
        'title' => wp_strip_all_tags(get_the_title($post_id)),
    );

    return strtok($base_url, '#') . '#' . http_build_query($fragment_args, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Generate HTML for Arweave links
 *
 * @param int $post_id Post ID
 * @param string $title Optional heading text
 * @param bool $show_history Show all versions or just latest (default: false)
 * @param bool $no_css Skip CSS classes for unstyled output (default: false)
 * @return string HTML output
 */
function clkt_get_permaweb_links_html($post_id = null, $title = null, $show_history = false, $no_css = false)
{
    if (!$post_id) {
        $post_id = get_the_ID();
    }

    if (!$post_id) {
        return '';
    }

    // Get versions from post meta
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    if (empty($versions) || !is_array($versions)) {
        return '';
    }

    // Filter for valid versions (non-TEST in production, TEST in test mode)
    $test_mode_value = get_option('clkt_test_mode', 'no');
    $test_mode = in_array($test_mode_value, array('yes', '1', 1, true), true);
    $valid_versions = array();

    foreach ($versions as $version) {
        $tx_id = clkt_get_version_transaction_id($version);

        if (empty($tx_id)) {
            continue;
        }

        // Filter TEST vs production
        $is_test = strpos($tx_id, 'TEST_') === 0;
        if ($test_mode && !$is_test) {
            continue; // Skip non-TEST in test mode
        }
        if (!$test_mode && $is_test) {
            continue; // Skip TEST in production
        }

        $valid_versions[] = $version;
    }

    if (empty($valid_versions)) {
        return '';
    }

    // Get heading text
    if ($title === null) {
        $title = get_option('clkt_permaweb_public_heading', 'CLKT - Permanent Record');
    }

    // Sort by timestamp (newest first)
    usort($valid_versions, function ($a, $b) {
        $time_a = !empty($a['timestamp']) ? $a['timestamp'] : 0;
        $time_b = !empty($b['timestamp']) ? $b['timestamp'] : 0;
        return $time_b - $time_a;
    });

    // If not showing history, only keep the latest version
    if (!$show_history && count($valid_versions) > 1) {
        $valid_versions = array($valid_versions[0]);
    }

    // Build HTML
    ob_start();

    if ($no_css) {
        // Unstyled version - minimal markup
?>
    <div>
        <?php if (!empty($title)) : ?>
            <h3><?php echo esc_html($title); ?></h3>
        <?php endif; ?>
        <ul>
            <?php foreach ($valid_versions as $index => $version) :
                $tx_id = clkt_get_version_transaction_id($version);

                if (empty($tx_id)) {
                    continue;
                }

                $url = 'https://arweave.net/' . $tx_id;
                $collect_url = clkt_get_collect_url($post_id, $tx_id, $version);
                $timestamp = !empty($version['timestamp']) ? $version['timestamp'] : 0;
                $date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
                /* translators: %d: version number */
                $version_label = count($valid_versions) > 1 ? sprintf(esc_html__('Version %d', 'clkt-save-forever-collect-together'), $index + 1) : esc_html__('View on Arweave', 'clkt-save-forever-collect-together');

                if ($index === 0 && count($valid_versions) > 1) {
                    $version_label .= ' (' . esc_html__('Latest', 'clkt-save-forever-collect-together') . ')';
                }
            ?>
                <li>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($version_label); ?></a>
                    <a href="<?php echo esc_url($collect_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Collect to CLKT', 'clkt-save-forever-collect-together'); ?></a>
                    <?php if ($date) : ?>
                        - <?php echo esc_html($date); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php
    } else {
        // Styled version with CLKT branding
?>
    <div class="clkt-permaweb-links">
        <?php if (!empty($title)) : ?>
            <h3 class="clkt-permaweb-heading"><?php echo esc_html($title); ?></h3>
        <?php endif; ?>

        <?php
        $license = get_post_meta($post_id, '_clkt_license', true);
        $derived_from = get_post_meta($post_id, '_clkt_derived_from', true);
        $has_attribution = (!empty($license) && is_array($license)) || (!empty($derived_from) && is_array($derived_from));

        if ($has_attribution) : ?>
        <div class="clkt-attribution">
            <?php if (!empty($license) && is_array($license)) :
                $license_label = !empty($license['spdx']) ? $license['spdx'] : (!empty($license['note']) ? $license['note'] : '');
                $license_url = !empty($license['url']) ? $license['url'] : '';
            ?>
            <span class="clkt-attribution-license">
                <?php if ($license_url) : ?>
                    <a href="<?php echo esc_url($license_url); ?>" target="_blank" rel="noopener noreferrer license"><?php echo esc_html($license_label); ?></a>
                <?php else : ?>
                    <?php echo esc_html($license_label); ?>
                <?php endif; ?>
                <?php if (!empty($license['note']) && !empty($license['spdx'])) : ?>
                    <span class="clkt-attribution-note"><?php echo esc_html($license['note']); ?></span>
                <?php endif; ?>
            </span>
            <?php endif; ?>

            <?php if (!empty($derived_from) && is_array($derived_from)) : ?>
            <span class="clkt-attribution-derived">
                <?php foreach ($derived_from as $ref) :
                    $rel = !empty($ref['relationship']) ? $ref['relationship'] : 'source';
                    $ref_title = !empty($ref['title']) ? $ref['title'] : (!empty($ref['url']) ? $ref['url'] : '');
                    $ref_url = !empty($ref['url']) ? $ref['url'] : (!empty($ref['txId']) ? 'https://arweave.net/' . $ref['txId'] : '');
                    $ref_author = !empty($ref['author']) ? $ref['author'] : '';
                ?>
                <span class="clkt-derived-ref">
                    <?php /* translators: %s: relationship type (e.g. "response", "remix") */ ?>
                    <?php echo esc_html(ucfirst($rel)); ?>
                    <?php if ($ref_url && $ref_title) : ?>
                        <?php esc_html_e('to', 'clkt-save-forever-collect-together'); ?>
                        <a href="<?php echo esc_url($ref_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($ref_title); ?></a>
                    <?php elseif ($ref_title) : ?>
                        <?php esc_html_e('to', 'clkt-save-forever-collect-together'); ?> <?php echo esc_html($ref_title); ?>
                    <?php endif; ?>
                    <?php if ($ref_author) : ?>
                        <span class="clkt-derived-author"><?php esc_html_e('by', 'clkt-save-forever-collect-together'); ?> <?php echo esc_html($ref_author); ?></span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="clkt-permaweb-versions">
            <?php foreach ($valid_versions as $index => $version) :
                $tx_id = clkt_get_version_transaction_id($version);

                if (empty($tx_id)) {
                    continue;
                }

                $url = 'https://arweave.net/' . $tx_id;
                $collect_url = clkt_get_collect_url($post_id, $tx_id, $version);
                $timestamp = !empty($version['timestamp']) ? $version['timestamp'] : 0;
                $date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
                /* translators: %d: version number */
                $version_label = count($valid_versions) > 1 ? sprintf(esc_html__('Version %d', 'clkt-save-forever-collect-together'), $index + 1) : esc_html__('View on Arweave', 'clkt-save-forever-collect-together');

                if ($index === 0 && count($valid_versions) > 1) {
                    $version_label .= ' <span class="clkt-version-badge">' . esc_html__('Latest', 'clkt-save-forever-collect-together') . '</span>';
                }
            ?>
                <div class="clkt-permaweb-version">
                    <div class="clkt-permaweb-actions">
                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="clkt-permaweb-link">
                            ⛅ <?php echo wp_kses_post($version_label); ?>
                        </a>
                        <a href="<?php echo esc_url($collect_url); ?>" target="_blank" rel="noopener noreferrer" class="clkt-collect-link">
                            <?php esc_html_e('Collect to CLKT', 'clkt-save-forever-collect-together'); ?>
                        </a>
                    </div>
                    <?php if ($date) : ?>
                        <span class="clkt-version-date"><?php echo esc_html($date); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (get_option('clkt_show_powered_by', 'no') === 'yes') : ?>
        <div class="clkt-permaweb-footer">
            <a href="https://getclkt.com" target="_blank" rel="noopener noreferrer" class="clkt-powered-by">
                <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" class="clkt-logo" role="img" aria-label="CLKT Logo">
                    <path d="M 18 12 L 12 12 L 12 52 L 18 52" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="square"></path>
                    <path d="M 46 12 L 52 12 L 52 52 L 46 52" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="square"></path>
                    <line x1="24" y1="20" x2="24" y2="44" stroke="currentColor" stroke-width="2"></line>
                    <line x1="24" y1="28" x2="40" y2="20" stroke="currentColor" stroke-width="2"></line>
                    <line x1="24" y1="32" x2="40" y2="44" stroke="currentColor" stroke-width="2"></line>
                </svg>
                <?php esc_html_e('Powered by CLKT', 'clkt-save-forever-collect-together'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
<?php
    }

    return ob_get_clean();
}

/**
 * Enqueue frontend styles
 */
function clkt_enqueue_frontend_styles()
{
    if (!is_singular(clkt_get_supported_post_types())) {
        return;
    }

    $post_id = get_the_ID();
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);

    // Only enqueue if post has versions
    if (empty($versions)) {
        return;
    }

    wp_enqueue_style(
        'clkt-frontend',
        plugins_url('assets/css/frontend.css', dirname(__FILE__)),
        array(),
        CLKT_VERSION
    );
}
add_action('wp_enqueue_scripts', 'clkt_enqueue_frontend_styles');

/**
 * Register the Permaweb Links Gutenberg block
 */
function clkt_register_permaweb_links_block()
{
    register_block_type(
        CLKT_PLUGIN_DIR . 'blocks/permaweb-links',
        array(
            'render_callback' => 'clkt_render_permaweb_links_block',
        )
    );
}
add_action('init', 'clkt_register_permaweb_links_block');

/**
 * Render callback for the Permaweb Links block
 *
 * @param array $attributes Block attributes
 * @return string HTML output
 */
function clkt_render_permaweb_links_block($attributes)
{
    $show_title = isset($attributes['showTitle']) ? $attributes['showTitle'] : true;
    $custom_title = isset($attributes['customTitle']) && !empty($attributes['customTitle'])
        ? $attributes['customTitle']
        : null;
    $show_history = isset($attributes['showHistory']) ? $attributes['showHistory'] : false;

    $title = $show_title ? $custom_title : '';

    return clkt_get_permaweb_links_html(get_the_ID(), $title, $show_history);
}
