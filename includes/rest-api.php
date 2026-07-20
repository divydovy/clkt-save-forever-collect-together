<?php
/**
 * CLKT REST API — headless / agent-driveable Save Forever.
 *
 * Exposes the plugin's save flow over REST so it can be driven without the
 * admin browser (e.g. by automation, CI, or an agent) using an application
 * password. Signing/dispatch is delegated to a pluggable dispatcher via the
 * `clkt_dispatch_record` filter, because WordPress.com Atomic cannot sign
 * ANS-104 data items itself — a small hosted signing service holds the key
 * and does the Turbo dispatch. See clkt_default_dispatch_record() below.
 *
 * Routes (namespace clkt/v1):
 *   GET  /health           — plugin version + whether a dispatcher is configured
 *   POST /save/<id>        — build record, dispatch, persist tx (needs edit_post)
 *   GET  /status/<id>      — current tx id / status / versions for a post
 *   GET  /records          — list posts that have been clkt'd
 *
 * @package CLKT
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'clkt_register_rest_routes');

function clkt_register_rest_routes() {
    $ns = 'clkt/v1';

    register_rest_route($ns, '/health', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_health',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ));

    register_rest_route($ns, '/save/(?P<id>\d+)', array(
        'methods'             => 'POST',
        'callback'            => 'clkt_rest_save',
        'permission_callback' => function ($request) {
            return current_user_can('edit_post', (int) $request['id']);
        },
        'args'                => array(
            'id' => array('validate_callback' => function ($v) { return is_numeric($v); }),
        ),
    ));

    register_rest_route($ns, '/status/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_status',
        'permission_callback' => function ($request) {
            return current_user_can('edit_post', (int) $request['id']);
        },
    ));

    register_rest_route($ns, '/records', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_records',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ));
}

/**
 * GET /health
 */
function clkt_rest_health() {
    return rest_ensure_response(array(
        'plugin'                => 'clkt',
        'version'               => defined('CLKT_VERSION') ? CLKT_VERSION : null,
        'dispatcher_configured' => clkt_has_dispatcher(),
        'site'                  => home_url(),
    ));
}

/**
 * POST /save/<id> — the headless Save Forever.
 */
function clkt_rest_save(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $post    = get_post($post_id);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_Error('clkt_not_publishable', 'Post not found or not published.', array('status' => 404));
    }
    if (!clkt_has_dispatcher()) {
        return new WP_Error(
            'clkt_no_dispatcher',
            'No dispatcher configured. Set the CLKT signing-service URL/secret (options clkt_dispatch_service_url + clkt_dispatch_service_secret) or hook the clkt_dispatch_record filter.',
            array('status' => 501)
        );
    }

    $built  = clkt_rest_build_record($post);
    $record = $built['record'];
    $tags   = $built['tags'];

    // Delegate signing + Turbo dispatch to the configured dispatcher.
    $result = apply_filters('clkt_dispatch_record', null, $record, $tags, $post_id);

    if (is_wp_error($result)) {
        return $result;
    }
    if (empty($result) || empty($result['tx_id'])) {
        return new WP_Error('clkt_dispatch_failed', 'Dispatcher returned no transaction id.', array('status' => 502));
    }

    clkt_rest_store_tx($post_id, $result['tx_id'], $built['content_hash']);

    return rest_ensure_response(array(
        'success'     => true,
        'post_id'     => $post_id,
        'tx_id'       => $result['tx_id'],
        'arweave_url' => 'https://arweave.net/' . $result['tx_id'],
    ));
}

/**
 * GET /status/<id>
 */
function clkt_rest_status(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    return rest_ensure_response(array(
        'post_id'     => $post_id,
        'status'      => get_post_meta($post_id, '_clkt_arweave_status', true) ?: 'none',
        'tx_id'       => get_post_meta($post_id, '_clkt_current_tx_id', true) ?: null,
        'arweave_url' => get_post_meta($post_id, '_clkt_arweave_url', true) ?: null,
        'versions'    => get_post_meta($post_id, '_clkt_arweave_versions', true) ?: array(),
    ));
}

/**
 * GET /records — posts that have a current tx id.
 */
function clkt_rest_records(WP_REST_Request $request) {
    $q = new WP_Query(array(
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => (int) ($request['per_page'] ?: 50),
        'meta_key'       => '_clkt_current_tx_id',
        'fields'         => 'ids',
    ));
    $out = array();
    foreach ($q->posts as $pid) {
        $out[] = array(
            'post_id'     => $pid,
            'title'       => get_the_title($pid),
            'tx_id'       => get_post_meta($pid, '_clkt_current_tx_id', true),
            'arweave_url' => get_post_meta($pid, '_clkt_arweave_url', true),
        );
    }
    return rest_ensure_response($out);
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * True if something can sign+dispatch (a hosted service URL is set, or a
 * custom filter is hooked).
 */
function clkt_has_dispatcher() {
    if (has_filter('clkt_dispatch_record')) {
        return true;
    }
    return (bool) get_option('clkt_dispatch_service_url');
}

/**
 * Build the clkt-record-v1 envelope + tags for a post. Mirrors the schema the
 * browser bundle produces, so REST-dispatched records are indistinguishable
 * from UI ones.
 */
function clkt_rest_build_record(WP_Post $post) {
    $html   = apply_filters('the_content', $post->post_content);
    $domain = wp_parse_url(home_url(), PHP_URL_HOST);
    $now    = gmdate('c');
    $content_hash = hash('sha256', $html);

    $record = array(
        'schema'  => 'clkt-record-v1',
        'version' => 1,
        'app'     => array('name' => 'CLKT', 'version' => defined('CLKT_VERSION') ? CLKT_VERSION : null, 'via' => 'rest-api'),
        'source'  => array(
            'type'     => 'wordpress-post',
            'url'      => get_permalink($post),
            'domain'   => $domain,
            'postId'   => $post->ID,
            'postType' => $post->post_type,
            'slug'     => $post->post_name,
            'title'    => get_the_title($post),
        ),
        'content' => array('contentType' => 'text/html', 'encoding' => 'utf-8', 'sha256' => $content_hash, 'html' => $html),
        'collection' => array('id' => 'wordpress-site:' . $domain),
        'restore'    => array('manifest' => 'embedded'),
    );

    $tags = array(
        array('name' => 'Content-Type', 'value' => 'application/json'),
        array('name' => 'App-Name', 'value' => 'CLKT'),
        array('name' => 'App-Version', 'value' => defined('CLKT_VERSION') ? CLKT_VERSION : ''),
        array('name' => 'CLKT-Record-Schema', 'value' => 'clkt-record-v1'),
        array('name' => 'CLKT-Record-Version', 'value' => '1'),
        array('name' => 'Title', 'value' => get_the_title($post)),
        array('name' => 'Source-Type', 'value' => 'wordpress-post'),
        array('name' => 'Source-URL', 'value' => get_permalink($post)),
        array('name' => 'Source-Domain', 'value' => $domain),
        array('name' => 'WordPress-Post-ID', 'value' => (string) $post->ID),
        array('name' => 'WordPress-Post-Type', 'value' => $post->post_type),
        array('name' => 'Collection-ID', 'value' => 'wordpress-site:' . $domain),
        array('name' => 'Content-Hash', 'value' => $content_hash),
        array('name' => 'Updated-At', 'value' => $now),
    );

    return array('record' => $record, 'tags' => $tags, 'content_hash' => $content_hash);
}

/**
 * Persist a dispatched tx id onto the post — identical meta shape to the
 * browser save path (clkt_save_transaction), so the frontend badge works.
 */
function clkt_rest_store_tx($post_id, $tx_id, $content_hash = '') {
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }
    $post = get_post($post_id);
    $versions[] = array(
        'tx_id'         => $tx_id,
        'arweave_url'   => 'https://arweave.net/' . $tx_id,
        'timestamp'     => time(),
        'content_hash'  => $content_hash,
        'wp_revision_id'=> $post_id,
        'post_title'    => $post ? $post->post_title : '',
        'post_modified' => $post ? $post->post_modified : current_time('mysql'),
        'via'           => 'rest-api',
    );
    update_post_meta($post_id, '_clkt_arweave_versions', $versions);
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);
    update_post_meta($post_id, '_clkt_arweave_status', 'uploaded');
    update_post_meta($post_id, '_clkt_upload_timestamp', time());
    update_post_meta($post_id, '_clkt_arweave_url', 'https://arweave.net/' . $tx_id);
}

/**
 * Default dispatcher: POST the record + tags to a hosted signing service that
 * holds the EVM key and dispatches via Turbo (free <100KB). Configure with:
 *   update_option('clkt_dispatch_service_url', 'https://app.getclkt.com/api/dispatch');
 *   update_option('clkt_dispatch_service_secret', '<shared secret>');
 * Returns array('tx_id' => ...) or WP_Error. Only active when a URL is set.
 */
add_filter('clkt_dispatch_record', 'clkt_default_dispatch_record', 10, 4);
function clkt_default_dispatch_record($result, $record, $tags, $post_id) {
    if (!empty($result)) {
        return $result; // another handler already dispatched
    }
    $url = get_option('clkt_dispatch_service_url');
    if (!$url) {
        return $result; // not configured; clkt_has_dispatcher() stays false
    }
    $resp = wp_remote_post($url, array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type'      => 'application/json',
            'X-CLKT-Secret'     => (string) get_option('clkt_dispatch_service_secret', ''),
        ),
        'body'    => wp_json_encode(array('record' => $record, 'tags' => $tags, 'postId' => $post_id)),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || empty($data['tx_id'])) {
        return new WP_Error('clkt_service_error', 'Signing service error (HTTP ' . $code . ').', array('status' => 502, 'body' => $data));
    }
    return array('tx_id' => $data['tx_id']);
}
