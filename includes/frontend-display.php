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
 * Add Arweave links to post content
 */
function clkt_add_permaweb_links_to_content($content)
{
    // Only add on single posts in the main query
    if (!is_singular('post') || !is_main_query()) {
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

    // Append to content
    return $content . $permaweb_html;
}
add_filter('the_content', 'clkt_add_permaweb_links_to_content', 20);

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
    $test_mode = get_option('clkt_test_mode', 'no') === 'yes';
    $valid_versions = array();

    foreach ($versions as $version) {
        // Get transaction ID
        $tx_id = '';
        if (!empty($version['transactionId'])) {
            $tx_id = $version['transactionId'];
        } elseif (!empty($version['tx_id'])) {
            $tx_id = $version['tx_id'];
        } elseif (!empty($version['id'])) {
            $tx_id = $version['id'];
        }

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
                $tx_id = '';
                if (!empty($version['transactionId'])) {
                    $tx_id = $version['transactionId'];
                } elseif (!empty($version['tx_id'])) {
                    $tx_id = $version['tx_id'];
                } elseif (!empty($version['id'])) {
                    $tx_id = $version['id'];
                }

                if (empty($tx_id)) {
                    continue;
                }

                $url = 'https://arweave.net/' . $tx_id;
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

        <div class="clkt-permaweb-versions">
            <?php foreach ($valid_versions as $index => $version) :
                $tx_id = '';
                if (!empty($version['transactionId'])) {
                    $tx_id = $version['transactionId'];
                } elseif (!empty($version['tx_id'])) {
                    $tx_id = $version['tx_id'];
                } elseif (!empty($version['id'])) {
                    $tx_id = $version['id'];
                }

                if (empty($tx_id)) {
                    continue;
                }

                $url = 'https://arweave.net/' . $tx_id;
                $timestamp = !empty($version['timestamp']) ? $version['timestamp'] : 0;
                $date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
                /* translators: %d: version number */
                $version_label = count($valid_versions) > 1 ? sprintf(esc_html__('Version %d', 'clkt-save-forever-collect-together'), $index + 1) : esc_html__('View on Arweave', 'clkt-save-forever-collect-together');

                if ($index === 0 && count($valid_versions) > 1) {
                    $version_label .= ' <span class="clkt-version-badge">' . esc_html__('Latest', 'clkt-save-forever-collect-together') . '</span>';
                }
            ?>
                <div class="clkt-permaweb-version">
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="clkt-permaweb-link">
                        ⛅ <?php echo wp_kses_post($version_label); ?>
                    </a>
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
    if (!is_singular('post')) {
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
