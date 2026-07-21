<?php
/**
 * CLKT REST API — headless / agent-driveable Save Forever.
 *
 * Design principle: the plugin NEVER holds a key and NEVER signs content.
 * Signing happens remotely, in the caller's own wallet — exactly like the
 * browser flow, where the visitor's own wallet signs and JS then posts the
 * resulting tx id to be recorded. This endpoint is the headless equivalent:
 * the caller (agent / CI / script) signs + dispatches with THEIR OWN wallet,
 * then registers the tx id here. So liability for what gets saved stays with
 * the signer, never with CLKT.
 *
 * Typical flow:
 *   1. GET  /clkt/v1/prepare/<id>  -> canonical record + tags + content hash
 *   2. caller signs those + dispatches to Arweave with their own wallet
 *   3. POST /clkt/v1/save/<id>     -> { tx_id } ; plugin verifies + records
 *
 * Routes (namespace clkt/v1, auth via application password + edit_post cap):
 *   GET  /health           plugin version + verification mode
 *   GET  /prepare/<id>      canonical record + tags to sign (deterministic)
 *   POST /save/<id>         register a caller-signed tx id ({ tx_id })
 *   GET  /status/<id>       current tx id / status / versions
 *   GET  /records           posts that have been clkt'd
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
        'permission_callback' => function () { return current_user_can('edit_posts'); },
    ));

    register_rest_route($ns, '/prepare/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_prepare',
        'permission_callback' => function ($request) { return current_user_can('edit_post', (int) $request['id']); },
    ));

    register_rest_route($ns, '/save/(?P<id>\d+)', array(
        'methods'             => 'POST',
        'callback'            => 'clkt_rest_save',
        'permission_callback' => function ($request) { return current_user_can('edit_post', (int) $request['id']); },
        'args'                => array(
            'tx_id' => array('required' => true, 'type' => 'string'),
        ),
    ));

    register_rest_route($ns, '/status/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_status',
        'permission_callback' => function ($request) { return current_user_can('edit_post', (int) $request['id']); },
    ));

    register_rest_route($ns, '/records', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_records',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
    ));

    register_rest_route($ns, '/auto-save-queue', array(
        'methods'             => 'GET',
        'callback'            => 'clkt_rest_auto_save_queue',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
    ));
}

/**
 * GET /health
 */
function clkt_rest_health() {
    return rest_ensure_response(array(
        'plugin'  => 'clkt',
        'version' => defined('CLKT_VERSION') ? CLKT_VERSION : null,
        'site'    => home_url(),
        // Plugin never signs: signing is done remotely by the caller's wallet.
        'signing' => 'caller-side',
        'verify'  => (bool) apply_filters('clkt_rest_verify_onchain', true),
    ));
}

/**
 * GET /prepare/<id> — canonical record + tags the caller should sign.
 * Deterministic so the caller can build a byte-identical record.
 */
function clkt_rest_prepare(WP_REST_Request $request) {
    $post = get_post((int) $request['id']);
    if (!$post || $post->post_status !== 'publish') {
        return new WP_Error('clkt_not_publishable', 'Post not found or not published.', array('status' => 404));
    }
    $built = clkt_rest_build_record($post);
    return rest_ensure_response(array(
        'post_id'      => $post->ID,
        'record'       => $built['record'],
        'tags'         => $built['tags'],
        'content_hash' => $built['content_hash'],
    ));
}

/**
 * POST /save/<id> — register a tx the caller already signed + dispatched.
 * The plugin does NOT sign; it verifies the tx belongs to this post/site and
 * records it (same post-meta as the browser flow, so the badge works).
 */
function clkt_rest_save(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $tx_id   = sanitize_text_field((string) $request->get_param('tx_id'));
    $post    = get_post($post_id);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_Error('clkt_not_publishable', 'Post not found or not published.', array('status' => 404));
    }
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $tx_id)) {
        return new WP_Error('clkt_bad_txid', 'tx_id is not a valid Arweave transaction id.', array('status' => 400));
    }

    // Best-effort on-chain verification: confirm the tx is a CLKT record for
    // THIS post + site. Skipped gracefully if not yet indexed (propagation lag)
    // so we mark it pending rather than reject a legitimate just-dispatched tx.
    $verify = apply_filters('clkt_rest_verify_onchain', true);
    $status = 'uploaded';
    if ($verify) {
        $check = clkt_rest_verify_tx($tx_id, $post_id);
        if (is_wp_error($check)) {
            return $check; // definitively wrong (mismatched post/site/app)
        }
        if ($check === 'pending') {
            $status = 'pending_confirmation';
        }
    }

    $content_hash = sanitize_text_field((string) $request->get_param('content_hash'));
    clkt_rest_store_tx($post_id, $tx_id, $content_hash, $status);

    return rest_ensure_response(array(
        'success'     => true,
        'post_id'     => $post_id,
        'tx_id'       => $tx_id,
        'status'      => $status,
        'arweave_url' => 'https://arweave.net/' . $tx_id,
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
 * GET /records
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
 * Build the clkt-record-v1 envelope + tags for a post. Mirrors the schema the
 * browser bundle produces, so a caller signing this makes an equivalent record.
 */
function clkt_rest_build_record(WP_Post $post) {
    $html         = apply_filters('the_content', $post->post_content);
    $domain       = wp_parse_url(home_url(), PHP_URL_HOST);
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
        'content'    => array('contentType' => 'text/html', 'encoding' => 'utf-8', 'sha256' => $content_hash, 'html' => $html),
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
    );

    return array('record' => $record, 'tags' => $tags, 'content_hash' => $content_hash);
}

/**
 * Verify a submitted tx really is a CLKT record for this post + site, via the
 * Arweave GraphQL index. Returns true (verified), 'pending' (not yet indexed),
 * or WP_Error (definitively mismatched — reject).
 */
function clkt_rest_verify_tx($tx_id, $post_id) {
    $domain = wp_parse_url(home_url(), PHP_URL_HOST);
    $query  = '{ transaction(id: "' . $tx_id . '") { tags { name value } } }';
    $resp   = wp_remote_post('https://arweave-search.goldsky.com/graphql', array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('query' => $query)),
    ));
    if (is_wp_error($resp)) {
        return 'pending'; // can't reach index; don't block, mark pending
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $tx   = isset($data['data']['transaction']) ? $data['data']['transaction'] : null;
    if (empty($tx) || empty($tx['tags'])) {
        return 'pending'; // not indexed yet (propagation lag)
    }
    $tags = array();
    foreach ($tx['tags'] as $t) {
        $tags[$t['name']] = $t['value'];
    }
    if (($tags['App-Name'] ?? '') !== 'CLKT') {
        return new WP_Error('clkt_verify_failed', 'Transaction is not a CLKT record.', array('status' => 422));
    }
    if ((string) ($tags['WordPress-Post-ID'] ?? '') !== (string) $post_id) {
        return new WP_Error('clkt_verify_failed', 'Transaction post id does not match.', array('status' => 422));
    }
    if (($tags['Source-Domain'] ?? '') !== $domain) {
        return new WP_Error('clkt_verify_failed', 'Transaction is for a different site.', array('status' => 422));
    }
    return true;
}

/**
 * Persist a tx id onto the post — identical meta shape to the browser save path
 * (clkt_save_transaction), so the frontend badge works.
 */
function clkt_rest_store_tx($post_id, $tx_id, $content_hash = '', $status = 'uploaded') {
    $versions = get_post_meta($post_id, '_clkt_arweave_versions', true);
    if (!is_array($versions)) {
        $versions = array();
    }
    $post = get_post($post_id);
    // Idempotent: only append a version the first time we see this tx id, so
    // a re-save (e.g. to re-verify a pending tx) updates status without dupes.
    $already = false;
    foreach ($versions as $v) {
        if (isset($v['tx_id']) && $v['tx_id'] === $tx_id) { $already = true; break; }
    }
    if (!$already) {
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
    }
    update_post_meta($post_id, '_clkt_current_tx_id', $tx_id);
    update_post_meta($post_id, '_clkt_arweave_status', $status);
    update_post_meta($post_id, '_clkt_upload_timestamp', time());
    update_post_meta($post_id, '_clkt_arweave_url', 'https://arweave.net/' . $tx_id);
}

/**
 * GET /auto-save-queue — published posts that SHOULD be auto-saved per the
 * site's OWN settings (global auto-save + per-post always/never/default) but
 * don't have a current Arweave tx yet. Lets an external signer auto-clkt posts
 * on publish while fully respecting the site's CLKT auto-save configuration.
 */
function clkt_rest_auto_save_queue(WP_REST_Request $request) {
    $types = apply_filters('clkt_supported_post_types', array('post'));
    $q = new WP_Query(array(
        'post_type'      => $types,
        'post_status'    => 'publish',
        'posts_per_page' => (int) ($request['per_page'] ?: 100),
        'fields'         => 'ids',
    ));
    $out = array();
    foreach ($q->posts as $pid) {
        if (get_post_meta($pid, '_clkt_current_tx_id', true)) {
            continue; // already saved
        }
        if (!clkt_should_autosave($pid)) {
            continue; // site settings say don't auto-save this one
        }
        $out[] = array('post_id' => $pid, 'title' => get_the_title($pid));
    }
    return rest_ensure_response($out);
}

/**
 * Mirror of the auto-upload decision in clkt_handle_auto_upload(): the per-post
 * setting wins (always/never); otherwise fall back to the global toggle.
 * Keeps "respect settings" authoritative and inside the plugin.
 */
function clkt_should_autosave($post_id) {
    $per_post = get_post_meta($post_id, '_clkt_per_post_save', true);
    if ($per_post === 'always') {
        return true;
    }
    if ($per_post === 'never') {
        return false;
    }
    return (bool) get_option('clkt_global_auto_save', false);
}
