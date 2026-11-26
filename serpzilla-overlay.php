<?php
/**
 * Plugin Name: Serpzilla Overlay for WordPress
 * Description: Serpzilla rental + contextual links overlay (adapted from Cloudflare Worker serpzilla-worker).
 * Version: 1.3.0
 * Author: Erik Rizki Aldianto
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SERPZILLA_PLUGIN_VERSION', '1.3.0' );
define( 'SERPZILLA_DISPENSER', 'https://dispenser.globalbacklinks.com/' );
define( 'SERPZILLA_CACHE_KEY', 'serpzilla_snapshot_v1' );
define( 'SERPZILLA_MAX_STALE', 10800 ); // 3 jam
define( 'SERPZILLA_MAX_FEED', 8 * 1024 * 1024 );

// Text domain for internationalization
define( 'SERPZILLA_TEXT_DOMAIN', 'serpzilla-overlay' );

// Polyfill untuk PHP < 8
if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return $needle === '' || strncmp( (string) $haystack, (string) $needle, strlen( $needle ) ) === 0;
    }
}
if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( $haystack, $needle ) {
        $haystack = (string) $haystack; $needle = (string) $needle;
        if ( $needle === '' ) return true;
        return substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}

// Load text domain on init (WPCS: avoids _load_textdomain_just_in_time notice on WP 6.7+).
add_action( 'init', function() {
    load_plugin_textdomain( SERPZILLA_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
} );

// ---------------- Admin settings page ----------------
add_action( 'admin_menu', function() {
    add_options_page(
        'Serpzilla Overlay', 'Serpzilla Overlay', 'manage_options',
        'serpzilla-overlay', 'serpzilla_overlay_settings_page'
    );
});

function serpzilla_overlay_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<div class="wrap"><h1>Serpzilla Overlay</h1><p>Anda tidak memiliki izin untuk mengakses halaman ini.</p></div>';
        return;
    }

    $saved = false;
    $msg   = '';
    if ( isset( $_POST['serpzilla_save'] ) ) {
        check_admin_referer( 'serpzilla_save_settings', 'serpzilla_settings_nonce' );
        $fields = [ 'serpzilla_user_key', 'serpzilla_admin_token', 'serpzilla_enabled', 'serpzilla_site_host' ];
        foreach ( $fields as $k ) {
            if ( isset( $_POST[ $k ] ) ) {
                update_option( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
            }
        }
        // Validate max_stale numerik, gunakan default jika invalid.
        if ( isset( $_POST['serpzilla_max_stale'] ) && is_numeric( $_POST['serpzilla_max_stale'] ) ) {
            update_option( 'serpzilla_max_stale', absint( $_POST['serpzilla_max_stale'] ) );
        }
        $saved = true;
        $msg   = __( 'Settings saved.', SERPZILLA_TEXT_DOMAIN );
    }

    $user_key   = get_option( 'serpzilla_user_key', '' );
    $admin_tok  = get_option( 'serpzilla_admin_token', '' );
    $enabled    = get_option( 'serpzilla_enabled', 'true' );
    $site_host  = get_option( 'serpzilla_site_host', home_url() );
    $max_stale  = get_option( 'serpzilla_max_stale', SERPZILLA_MAX_STALE );
    ?>
    <div class="wrap">
        <h1>Serpzilla Overlay Settings</h1>
        <?php if ( $saved ) : ?><div class="updated notice is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
        <?php
        // Admin notice untuk hasil sync (diproses di admin_init, ditampilkan di sini).
        if ( isset( $_GET['serpzilla_notice'] ) ) {
            $flag   = sanitize_text_field( wp_unslash( $_GET['serpzilla_notice'] ) );
            $result = get_option( 'serpzilla_sync_result', [] );
            if ( in_array( $flag, [ 'sync_ok', 'sync_error' ], true ) ) {
                $class = 'sync_ok' === $flag ? 'notice-success' : 'notice-error';
                echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
                echo esc_html__( 'Feed sync ', SERPZILLA_TEXT_DOMAIN ) . ( 'sync_ok' === $flag ? esc_html__( 'completed.', SERPZILLA_TEXT_DOMAIN ) : esc_html__( 'failed.', SERPZILLA_TEXT_DOMAIN ) );
                if ( ! empty( $result['result'] ) ) {
                    echo '<pre>' . esc_html( print_r( $result['result'], true ) ) . '</pre>';
                }
                echo '</p></div>';
                delete_option( 'serpzilla_sync_result' );
            }

            if ( in_array( $flag, [ 'status_ok', 'status_empty' ], true ) ) {
                $status  = get_option( 'serpzilla_status_result', [] );
                $class   = 'status_ok' === $flag ? 'notice-success' : 'notice-warning';
                $message = 'status_ok' === $flag
                    ? esc_html__( 'Feed is cached and active.', SERPZILLA_TEXT_DOMAIN )
                    : esc_html__( 'No cached feed found. A background sync has been scheduled.', SERPZILLA_TEXT_DOMAIN );
                echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . $message;
                if ( ! empty( $status['updatedAt'] ) ) {
                    echo ' ' . esc_html__( 'Last update:', SERPZILLA_TEXT_DOMAIN ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $status['updatedAt'] / 1000 ) ) );
                }
                echo '</p></div>';
                delete_option( 'serpzilla_status_result' );
            }
        }
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=serpzilla-overlay' ) ); ?>">
            <?php wp_nonce_field( 'serpzilla_save_settings', 'serpzilla_settings_nonce' ); ?>
            <table class="form-table">
                <tr><th scope="row">User Key</th><td><input type="text" name="serpzilla_user_key" value="<?php echo esc_attr( $user_key ); ?>" class="regular-text"/></td></tr>
                <tr><th scope="row">Admin Token</th><td><input type="text" name="serpzilla_admin_token" value="<?php echo esc_attr( $admin_tok ); ?>" class="regular-text"/></td></tr>
                <tr><th scope="row">Enabled</th><td><input type="text" name="serpzilla_enabled" value="<?php echo esc_attr( $enabled ); ?>"/> ("true" to enable)</td></tr>
                <tr><th scope="row">Site Host</th><td><input type="text" name="serpzilla_site_host" value="<?php echo esc_attr( $site_host ); ?>" class="regular-text"/></td></tr>
                <tr><th scope="row">Max Stale (seconds)</th><td><input type="number" name="serpzilla_max_stale" value="<?php echo esc_attr( $max_stale ); ?>"/></td></tr>
            </table>
            <?php submit_button( __( 'Save Changes', SERPZILLA_TEXT_DOMAIN ), 'primary', 'serpzilla_save' ); ?>
        </form>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=serpzilla-overlay&action=sync' ), 'serpzilla_sync' ) ); ?>">Sync Feed Now</a> |
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=serpzilla-overlay&action=status' ), 'serpzilla_status' ) ); ?>">Status</a>
        </p>
        <?php
        $last = get_option( 'serpzilla_last_sync', [] );
        if ( $last ) :
            $color = ! empty( $last['ok'] ) ? 'green' : '#b32d2e';
        ?>
        <p><strong>Sync terakhir:</strong>
            <span style="color:<?php echo esc_attr( $color ); ?>">
                <?php echo ! empty( $last['ok'] ) ? 'SUKSES' : 'GAGAL'; ?>
                (<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) $last['time'] ) ); ?>)
                &mdash; <?php echo esc_html( $last['code'] . ': ' . $last['message'] ); ?>
                <?php if ( isset( $last['linkPages'] ) ) echo '| link pages: ' . (int) $last['linkPages'] . ', context pages: ' . (int) $last['contextPages']; ?>
            </span>
        </p>
        <?php endif; ?>
        <p style="color:#666;font-size:12px">
            PHP <?php echo esc_html( PHP_VERSION ); ?> | WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
            | Feed: <?php echo esc_html( SERPZILLA_DISPENSER ); ?>
        </p>
    </div>
    <?php
}

// Process sync/status in admin_init, then redirect back with a flag.
// HTML output is handled by admin notices in the settings page (WPCS-safe).
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_GET['page'] ) || 'serpzilla-overlay' !== $_GET['page'] ) {
        return;
    }

    $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

    if ( 'sync' === $action ) {
        check_admin_referer( 'serpzilla_sync' );
        $res  = serpzilla_sync_feed();
        $flag = ! empty( $res['error'] ) ? 'sync_error' : 'sync_ok';
        update_option( 'serpzilla_sync_result', [ 'flag' => $flag, 'result' => $res ], false );
        wp_safe_redirect( admin_url( 'options-general.php?page=serpzilla-overlay&serpzilla_notice=' . $flag ) );
        exit;
    }

    if ( 'status' === $action ) {
        check_admin_referer( 'serpzilla_status' );
        $val  = serpzilla_get_snapshot();
        $flag = ! empty( $val ) ? 'status_ok' : 'status_empty';
        update_option( 'serpzilla_status_result', [ 'updatedAt' => $val['updatedAt'] ?? null ], false );
        wp_safe_redirect( admin_url( 'options-general.php?page=serpzilla-overlay&serpzilla_notice=' . $flag ) );
        exit;
    }
} );

// ---------------- Core: fetch feed ----------------
function serpzilla_fetch_feed( $kind ) {
    try {
        $user_key = get_option( 'serpzilla_user_key' );
        if ( ! $user_key ) return new WP_Error( 'SERPZILLA_KEY_MISSING', 'User Key not set' );

        $endpoint = add_query_arg( [
            'user'     => $user_key,
            'host'     => get_option( 'serpzilla_site_host', home_url() ),
            'charset'  => 'UTF-8',
            'format'   => 'serialize',
        ], SERPZILLA_DISPENSER . ( $kind === 'links' ? 'code.php' : 'code_context.php' ) );

        $ua = $kind === 'links'
            ? 'BACKLINKS_Client PHP 1.5.6 (WP v3.4.5)'
            : 'BACKLINKS_Context PHP 1.5.6 (WP v3.4.5)';

        $response = wp_remote_get( $endpoint, [
            'timeout'    => 15,
            'redirection'=> 0,
            'user-agent' => $ua,
            'headers'    => [
                'Accept'          => 'application/octet-stream,text/plain;q=0.9',
                'Accept-Charset'  => 'UTF-8',
            ],
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) return new WP_Error( 'SERPZILLA_HTTP_' . $code, 'HTTP ' . $code );

        $body = wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > SERPZILLA_MAX_FEED ) return new WP_Error( 'BODY_TOO_LARGE', 'Feed too large' );

        $prefix = substr( $body, 0, 32 );
        if ( str_starts_with( $prefix, 'FATAL ERROR:' ) ) return new WP_Error( 'SERPZILLA_APPLICATION_ERROR', $prefix );

        $value = serpzilla_unserialize( $body );
        if ( is_wp_error( $value ) ) return $value;
        if ( ! is_array( $value ) ) return new WP_Error( 'SERPZILLA_PAYLOAD_INVALID', 'Payload not array' );

        if ( isset( $value['__for_user__'] ) && $value['__for_user__'] !== $user_key )
            return new WP_Error( 'SERPZILLA_OWNER_MISMATCH', 'Owner mismatch' );

        $host = isset( $value['__for_host__'] ) ? $value['__for_host__'] : '';
        $site_host = get_option( 'serpzilla_site_host', home_url() );
        $site = strtolower( str_replace( [ 'http://', 'https://', 'www.', '/' ], '', $site_host ) );
        $feed_host = strtolower( str_replace( [ 'http://', 'https://', 'www.', '/' ], '', $host ) );
        if ( $feed_host && $feed_host !== $site )
            return new WP_Error( 'SERPZILLA_HOST_MISMATCH', 'Host mismatch' );

        return serpzilla_normalize_metadata( $value );
    } catch ( Throwable $e ) {
        return new WP_Error( 'EXCEPTION', $e->getMessage() );
    }
}

function serpzilla_normalize_metadata( array &$value ) {
    foreach ( $value as $k => $v ) {
        if ( str_starts_with( $k, '__sape_' ) ) {
            $alias = str_replace( '__sape_', '__backlinks_', $k );
            if ( ! isset( $value[ $alias ] ) ) $value[ $alias ] = $v;
        }
    }
    return $value;
}

function serpzilla_record_sync( $ok, $code, $message, $extra = [] ) {
    update_option( 'serpzilla_last_sync', array_merge( [
        'time'    => time(),
        'ok'      => (bool) $ok,
        'code'    => (string) $code,
        'message' => (string) $message,
    ], $extra ), false );
}

function serpzilla_sync_feed() {
    $links   = serpzilla_fetch_feed( 'links' );
    $context = serpzilla_fetch_feed( 'context' );

    if ( is_wp_error( $links ) ) {
        error_log( 'Serpzilla Overlay Sync Error: ' . $links->get_error_code() . ' - ' . $links->get_error_message() );
        $res = [ 'error' => $links->get_error_code(), 'message' => $links->get_error_message() ];
        serpzilla_record_sync( false, $res['error'], $res['message'] );
        return $res;
    }
    if ( is_wp_error( $context ) ) {
        error_log( 'Serpzilla Overlay Sync Error: ' . $context->get_error_code() . ' - ' . $context->get_error_message() );
        $res = [ 'error' => $context->get_error_code(), 'message' => $context->get_error_message() ];
        serpzilla_record_sync( false, $res['error'], $res['message'] );
        return $res;
    }

    $snapshot = [
        'updatedAt' => microtime( true ) * 1000,
        'links'     => $links,
        'context'   => $context,
    ];
    set_transient( SERPZILLA_CACHE_KEY, $snapshot, HOUR_IN_SECONDS * 3 );

    $link_pages = 0;
    foreach ( array_keys( $links ) as $k ) { if ( strpos( $k, '__' ) !== 0 ) $link_pages++; }
    $context_pages = 0;
    foreach ( array_keys( $context ) as $k ) { if ( strpos( $k, '__' ) !== 0 ) $context_pages++; }

    $res = [
        'updatedAt'    => $snapshot['updatedAt'],
        'linkPages'    => $link_pages,
        'contextPages' => $context_pages,
    ];
    serpzilla_record_sync( true, 'OK', 'Sync success', [ 'linkPages' => $link_pages, 'contextPages' => $context_pages ] );
    return $res;
}

function serpzilla_get_snapshot() {
    $val = get_transient( SERPZILLA_CACHE_KEY );
    if ( ! $val ) return null;
    $data = is_array( $val ) ? $val : maybe_unserialize( $val );
    if ( ! $data || ! isset( $data['updatedAt'] ) ) return null;
    $age = ( microtime( true ) * 1000 ) - $data['updatedAt'];
    $limit = (int) ( get_option( 'serpzilla_max_stale', SERPZILLA_MAX_STALE ) * 1000 );
    if ( $age < 0 || $age > $limit ) return null;
    return $data;
}

// ---------------- PHP unserialize (native, hardened) ----------------
// Feed dari Serpzilla memakai PHP serialize(). Pakai unserialize() bawaan PHP
// dengan allowed_classes=false (tidak ada object injection), bukan parser manual.
function serpzilla_unserialize( $input, $maxDepth = 20, $maxEntries = 200000 ) {
    $bytes = is_string( $input ) ? trim( $input ) : '';
    if ( $bytes === '' ) return new WP_Error( 'SERPZILLA_SERIALIZE_EMPTY', 'Empty payload' );

    if ( function_exists( 'ini_set' ) ) {
        @ini_set( 'unserialize_max_depth', (string) $maxDepth );
    }

    $error = null;
    set_error_handler( function( $no, $str ) use ( &$error ) {
        $error = $str;
        return true;
    } );

    try {
        $value = unserialize( $bytes, [ 'allowed_classes' => false, 'max_depth' => $maxDepth ] );
    } catch ( Throwable $e ) {
        restore_error_handler();
        return new WP_Error( 'SERPZILLA_SERIALIZE_INVALID', $e->getMessage() );
    }
    restore_error_handler();

    if ( $error !== null ) {
        return new WP_Error( 'SERPZILLA_SERIALIZE_INVALID', $error );
    }
    if ( $value === false && $bytes !== 'b:0;' ) {
        return new WP_Error( 'SERPZILLA_SERIALIZE_INVALID', 'unserialize() returned false' );
    }
    if ( is_object( $value ) ) {
        return new WP_Error( 'SERPZILLA_PAYLOAD_INVALID', 'Object payload rejected' );
    }
    return $value;
}

function serpzilla_cspace( $code ) { return in_array( $code, [ 9, 10, 13, 32 ], true ); }

// ---------------- Helpers: phrases, rental, footer ----------------
function serpzilla_strip_tags( $value ) {
    return str_replace( [ '&amp;', '&quot;', '&#039;', '&lt;', '&gt;' ], [ '&', '"', "'", '<', '>' ], preg_replace( '/<[^>]*>/', '', $value ) );
}

function serpzilla_phrase_pattern( $html ) {
    $phrase = serpzilla_strip_tags( (string) $html );
    $pattern = '';
    for ( $i = 0; $i < strlen( $phrase ); $i++ ) {
        $c = $phrase[$i];
        if ( preg_match( '/\s/', $c ) ) { $pattern .= '(?:\s|&nbsp;|\u00a0)+'; continue; }
        if ( $c === '&' ) { $pattern .= '(?:&amp;|&)'; continue; }
        if ( $c === '"' ) { $pattern .= '(?:&quot;|")'; continue; }
        if ( $c === "'" ) { $pattern .= "(?:&#0?39;|&#x27;|')"; continue; }
        if ( $c === '<' ) { $pattern .= '&lt;'; continue; }
        if ( $c === '>' ) { $pattern .= '&gt;'; continue; }
        $pattern .= preg_quote( $c, '/' );
    }
    return $pattern;
}

function serpzilla_contextualize( $fragment, $replacements ) {
    global $forbidden_tags;
    if ( ! isset( $forbidden_tags ) ) $forbidden_tags = [ 'a', 'textarea', 'select', 'script', 'style', 'label', 'noscript', 'noindex', 'button' ];
    $remaining = [];
    foreach ( (array) $replacements as $html ) {
        $source = (string) $html;
        $pattern = serpzilla_phrase_pattern( $source );
        if ( $pattern ) $remaining[] = [ 'html' => $source, 'pattern' => '/' . $pattern . '/' ];
    }
    if ( ! $remaining ) return [ 'html' => $fragment, 'count' => 0 ];
    $count = 0;
    $depth = 0;
    $parts = preg_split( '/(<[^>]+>)/', $fragment, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
    foreach ( $parts as &$part ) {
        if ( preg_match( '/^<\s*(\/?)\s*([a-z0-9]+)/i', $part, $m ) ) {
            $name = strtolower( $m[2] );
            if ( in_array( $name, $forbidden_tags, true ) ) {
                if ( $m[1] ) $depth = max( 0, $depth - 1 );
                elseif ( ! preg_match( '/\/\s*>$/', $part ) ) $depth++;
            }
            continue;
        }
        if ( $depth ) continue;
        foreach ( $remaining as $i => $rep ) {
            if ( preg_match( $rep['pattern'], $part ) ) {
                $part = preg_replace( $rep['pattern'], $rep['html'], $part, 1 );
                $count++;
                unset( $remaining[$i] );
                $remaining = array_values( $remaining );
                $i--;
            }
        }
    }
    unset( $part );
    return [ 'html' => implode( '', $parts ), 'count' => $count ];
}

function serpzilla_replace_article( $html, $replacements, $bot, $newUrl = '' ) {
    $count = 0;
    $found = false;
    $output = preg_replace_callback(
        '/(<article\b[^>]*\bclass=([\'"])[^\'"]*\bprose\b[^\'"]*\2[^>]*>)([\s\S]*?)(<\/article>)/i',
        function( $m ) use ( $replacements, $bot, $newUrl, &$count, &$found ) {
            $found = true;
            $changed = serpzilla_contextualize( $m[3], $replacements );
            $count = $changed['count'];
            $content = $bot ? '<backlinks_index>' . $changed['html'] . $newUrl . '</backlinks_index>' : $changed['html'];
            return $m[1] . $content . $m[4];
        },
        $html
    );
    return [ 'html' => $output, 'count' => $count, 'found' => $found ];
}

function serpzilla_replace_all( $value, $map ) {
    $result = (string) ( $value ?? '' );
    foreach ( $map as $key => $replacement ) {
        $result = str_replace( '{' . $key . '}', (string) $replacement, $result );
    }
    return preg_replace( '/\{[a-z\d_-]+\}/i', ' ', $result );
}

function serpzilla_page_value( $data, $uri, $bot ) {
    if ( isset( $data[ $uri ] ) ) return $data[ $uri ];
    if ( $bot && isset( $data['__backlinks_new_url__'] ) ) return $data['__backlinks_new_url__'];
    return [];
}

function serpzilla_rental_html( $data, $uri, $bot ) {
    $links = serpzilla_page_value( $data, $uri, $bot );
    if ( ! is_array( $links ) ) {
        $raw = (string) ( $links ?? '' );
        return $bot ? $raw . '<backlinks_noindex></backlinks_noindex>' : $raw;
    }

    $template = $data['__backlinks_block_tpl__'] ?? null;
    $orientation = $template[1] ?? $template['1'] ?? null;
    $html = '';
    if ( $template && isset( $orientation['block'] ) && isset( $orientation['item'] ) && isset( $orientation['item_container'] ) ) {
        $css   = $template['css'] ?? '';
        $before = $data['__backlinks_block_ins_beforeall__'] ?? '';
        $beforeblock = $data['__backlinks_block_ins_beforeblock__'] ?? '';
        $html .= (string) $css . (string) $before . (string) $beforeblock;
        $items = '';
        $obligatory = ! empty( $data['__backlinks_block_ins_itemobligatory__'] ) ? 1 : 0;
        $td_width = count( $links ) > 0 ? round( 100 / max( 1, count( $links ) + $obligatory ) ) : 100;
        foreach ( $links as $raw ) {
            $text = (string) $raw;
            $match = null;
            if ( preg_match( '#<a href="(https?://([^"/]+)[^"]*)"[^>]*>\s*([^<]+)</a>#i', $text, $match )
                 || preg_match( '#<a href="(https?://([^"/]+)[^"]*)"[^>]*><img.*?alt="(.*?)".*?></a>#i', $text, $match ) ) {
                $values = [
                    'header'   => ucfirst( trim( $match[3] ) ),
                    'text'     => trim( $text ),
                    'url'      => ( isset( $data['__backlinks_block_uri_idna__'][ $match[2] ] ) ? $data['__backlinks_block_uri_idna__'][ $match[2] ] : $match[2] ),
                    'link'     => $match[1],
                    'td_width' => $td_width,
                ];
            } else {
                $values = [ 'header' => '', 'text' => trim( $text ), 'url' => '', 'link' => '', 'td_width' => $td_width ];
            }
            $items .= serpzilla_replace_all( str_replace( '{item}', $orientation['item'], $orientation['item_container'] ), $values );
        }
        if ( $obligatory ) {
            $items .= serpzilla_replace_all(
                str_replace( '{item}', $data['__backlinks_block_ins_itemobligatory__'], $orientation['item_container'] ),
                [ 'td_width' => $td_width ]
            );
        }
        $html .= serpzilla_replace_all( $orientation['block'], [ 'items' => $items, 'td_width' => $td_width, 'block_style_custom' => '' ] );
        $html .= (string) ( $data['__backlinks_block_ins_afterblock__'] ?? '' );
    } else {
        $delimiter = $data['__backlinks_delimiter__'] ?? '';
        $html = implode( (string) $delimiter, $links );
    }

    if ( $bot ) {
        $nof_oblig = ! empty( $data['__backlinks_block_ins_itemobligatory__'] ) ? 1 : 0;
        $html = sprintf( '<backlinks_block nof_req="0" nof_displ="%d" nof_oblig="%d" nof_cond="0"><backlinks_noindex>%s</backlinks_noindex></backlinks_block>',
            count( $links ), $nof_oblig, $html );
    }
    return $html;
}

function serpzilla_append_footer( $html, $fragment ) {
    $wrapper = '<div data-serpzilla-rendered="true">' . $fragment . '</div>';

    // 1) Sisipkan setelah <footer ...> pertama (paling umum di tema WordPress)
    $found = false;
    $inserted = false;
    $replaced = preg_replace_callback(
        '/<footer\b[^>]*>/i',
        function( $m ) use ( $wrapper, &$found, &$inserted ) {
            $found = true;
            if ( ! $inserted ) {
                $inserted = true;
                return $m[0] . $wrapper;
            }
            return $m[0];
        },
        $html
    );

    if ( $inserted ) return [ 'html' => $replaced, 'found' => true ];

    // 2) Fallback: sebelum </body>
    $at = stripos( $html, '</body>' );
    if ( $at !== false ) {
        return [ 'html' => substr( $html, 0, $at ) . $wrapper . substr( $html, $at ), 'found' => true ];
    }

    // 3) Fallback terakhir: append di akhir dokumen
    return [ 'html' => $html . $wrapper, 'found' => true ];
}

function serpzilla_output( $html, $status = 200, $status_text = 'OK' ) {
    // Jangan hapus semua header (bisa membuang cookie & content-type WordPress).
    if ( ! headers_sent() ) {
        foreach ( [ 'Cache-Control', 'Pragma', 'Expires', 'ETag', 'Last-Modified', 'Content-Length', 'Content-MD5', 'Digest' ] as $h ) {
            header_remove( $h );
        }
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        if ( $status !== 200 ) {
            http_response_code( $status );
            header( 'Status: ' . $status . ' ' . $status_text );
        }
    }
    echo $html;
}

// ---------------- Main request handler (front-end) ----------------
// Blok disuntikkan lewat wp_footer (tidak tergantung output buffer, jadi aman
// walau LiteSpeed/plugin cache menutup buffer lebih dulu).
add_action( 'wp_footer', 'serpzilla_render_footer_block', 999 );

function serpzilla_host_matches() {
    $site = strtolower( trim( str_replace( [ 'http://', 'https://', 'www.', '/' ], '', (string) get_option( 'serpzilla_site_host', home_url() ) ) ) );
    $host = strtolower( trim( str_replace( [ 'http://', 'https://', 'www.', '/' ], '', ( isset( $_SERVER['HTTPS'] ) ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) ) ) );
    return $site !== '' && $site === $host;
}

function serpzilla_request_uri() {
    $uri = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
    if ( isset( $_SERVER['QUERY_STRING'] ) && $_SERVER['QUERY_STRING'] ) $uri .= '?' . $_SERVER['QUERY_STRING'];
    return $uri ?: '/';
}

function serpzilla_is_bot_request() {
    $cookies = serpzilla_parse_cookies( $_SERVER['HTTP_COOKIE'] ?? '' );
    $user_key = (string) get_option( 'serpzilla_user_key' );
    if ( $user_key === '' ) return false;
    return ( $cookies['backlinks_cookie'] ?? '' ) === $user_key || ( $cookies['sape_cookie'] ?? '' ) === $user_key;
}

function serpzilla_footer_payload( $data, $uri, $bot ) {
    $rental = serpzilla_rental_html( $data['links'], $uri, $bot );
    $mandatory = '';
    if ( empty( $GLOBALS['serpzilla_mandatory_done'] ) ) {
        $mandatory = $data['links']['__backlinks_page_obligatory_output__']
            ?? $data['context']['__backlinks_page_obligatory_output__']
            ?? '';
        if ( $mandatory !== '' ) $GLOBALS['serpzilla_mandatory_done'] = true;
    }
    return $rental . $mandatory;
}

function serpzilla_render_footer_block() {
    if ( ! empty( $GLOBALS['serpzilla_footer_done'] ) ) return;
    if ( ! serpzilla_is_front_end_request() ) return;
    if ( get_option( 'serpzilla_enabled', 'true' ) !== 'true' ) return;
    if ( ! serpzilla_host_matches() ) return;

    $data = serpzilla_get_snapshot();
    if ( ! $data ) {
        if ( function_exists( 'wp_schedule_single_event' ) ) wp_schedule_single_event( time() + 1, 'serpzilla_bg_sync' );
        return;
    }

    $payload = serpzilla_footer_payload( $data, serpzilla_request_uri(), serpzilla_is_bot_request() );
    if ( $payload === '' ) return;

    echo '<div data-serpzilla-rendered="true">' . $payload . '</div>';
    $GLOBALS['serpzilla_footer_done'] = true;
}

// Buffer hanya dipakai untuk contextual replacement (mengubah teks artikel).
add_action( 'template_redirect', 'serpzilla_maybe_nocache', 1 );

function serpzilla_maybe_nocache() {
    if ( ! serpzilla_is_front_end_request() ) return;
    if ( get_option( 'serpzilla_enabled', 'true' ) !== 'true' ) return;
    if ( ! serpzilla_host_matches() ) return;
    if ( ! serpzilla_get_snapshot() ) return;
    if ( headers_sent() ) return;
    header_remove( 'Cache-Control' );
    header_remove( 'Pragma' );
    header_remove( 'Expires' );
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
}

add_action( 'template_redirect', 'serpzilla_start_buffer', 0 );

function serpzilla_start_buffer() {
    if ( ! serpzilla_is_front_end_request() ) return;
    if ( get_option( 'serpzilla_enabled', 'true' ) !== 'true' ) return;
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) return;
    if ( ! serpzilla_host_matches() ) return;
    if ( ! empty( $GLOBALS['serpzilla_buffer_started'] ) ) return;
    $GLOBALS['serpzilla_buffer_started'] = true;
    ob_start();
    $GLOBALS['serpzilla_buffer_level'] = ob_get_level();
}

add_action( 'shutdown', 'serpzilla_handle_request', 999999 );

function serpzilla_handle_request() {
    if ( empty( $GLOBALS['serpzilla_buffer_started'] ) ) return;
    if ( ! serpzilla_is_front_end_request() ) return;
    if ( get_option( 'serpzilla_enabled', 'true' ) !== 'true' ) return;
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) return;
    if ( ! serpzilla_host_matches() ) return;

    // Hanya proses kalau buffer kita masih buffer teratas (tidak ditutup plugin lain).
    if ( ob_get_level() !== (int) ( $GLOBALS['serpzilla_buffer_level'] ?? -1 ) ) return;

    $html = ob_get_contents();
    if ( ! $html ) return;

    $is_html = stripos( $html, '<!DOCTYPE' ) === 0 || stripos( $html, '<html' ) !== false;
    if ( ! $is_html ) return;

    $uri = serpzilla_request_uri();
    $bypass = [ '/magenet-articles/', '/__magenet/', '/__backlinks/', '/__serpzilla/' ];
    foreach ( $bypass as $p ) { if ( str_starts_with( $uri, $p ) ) return; }

    $data = serpzilla_get_snapshot();
    $bot  = serpzilla_is_bot_request();

    if ( ! empty( $GLOBALS['serpzilla_footer_done'] ) ) {
        $payload = '';
    } elseif ( $data ) {
        $payload = serpzilla_footer_payload( $data, $uri, $bot );
    } else {
        return;
    }

    if ( ! $data ) return;

    $words  = $data['context'][ $uri ] ?? [];
    $newUrl = $bot ? ( $data['context']['__backlinks_new_url__'] ?? '' ) : '';
    $out    = serpzilla_replace_article( $html, $words, $bot, $newUrl )['html'];

    if ( $payload !== '' ) {
        $out = serpzilla_append_footer( $out, $payload )['html'];
    }

    if ( $out === $html ) return;

    ob_end_clean();
    serpzilla_output( $out );
}

add_action( 'serpzilla_bg_sync', 'serpzilla_sync_feed' );

function serpzilla_parse_cookies( $cookie_header ) {
    $result = [];
    foreach ( explode( ';', $cookie_header ) as $part ) {
        $at = strpos( $part, '=' );
        if ( $at > 0 ) $result[ trim( substr( $part, 0, $at ) ) ] = trim( substr( $part, $at + 1 ) );
    }
    return $result;
}

function serpzilla_is_front_end_request() {
    if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) return false;
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return false;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
    if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) return false;
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
    if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return false;
    if ( function_exists( 'is_feed' ) && is_feed() ) return false;
    if ( function_exists( 'is_robots' ) && is_robots() ) return false;
    return true;
}

// ---------------- Admin endpoints (protected) ----------------
function serpzilla_request_headers() {
    if ( function_exists( 'getallheaders' ) ) {
        $h = getallheaders();
        if ( is_array( $h ) ) return array_change_key_case( $h, CASE_LOWER );
    }
    $out = [];
    foreach ( $_SERVER as $k => $v ) {
        if ( strpos( $k, 'HTTP_' ) === 0 ) {
            $name = strtolower( str_replace( '_', '-', substr( $k, 5 ) ) );
            $out[ $name ] = $v;
        }
    }
    if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) $out['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    return $out;
}

function serpzilla_bearer_token() {
    $headers = serpzilla_request_headers();
    if ( ! empty( $headers['authorization'] ) ) return $headers['authorization'];
    if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) return $_SERVER['HTTP_AUTHORIZATION'];
    return '';
}

add_action( 'admin_post_serpzilla_sync', 'serpzilla_admin_sync' );
add_action( 'admin_post_nopriv_serpzilla_sync', 'serpzilla_admin_sync' );
function serpzilla_admin_sync() {
    try {
        $auth = serpzilla_bearer_token();
        $admin_token = (string) get_option( 'serpzilla_admin_token', '' );
        if ( $admin_token === '' || ! hash_equals( 'Bearer ' . $admin_token, (string) $auth ) ) {
            error_log( 'Serpzilla Overlay Admin Sync Unauthorized: invalid token' );
            status_header( 401 );
            wp_die( esc_html__( 'Unauthorized', SERPZILLA_TEXT_DOMAIN ), esc_html__( 'Unauthorized', SERPZILLA_TEXT_DOMAIN ), [ 'response' => 401 ] );
        }
        $res = serpzilla_sync_feed();
        echo '<div class="wrap"><h1>Serpzilla Overlay - Sync Result</h1>';
        if ( is_wp_error( $res ) ) {
            error_log( 'Serpzilla Overlay Admin Sync Error: ' . $res->get_error_code() );
            echo '<p style="color:red">Error: ' . esc_html( $res->get_error_message() ) . '</p>';
        } else {
            echo '<pre>' . esc_html( print_r( $res, true ) ) . '</pre>';
        }
        echo '<p><a href="?page=serpzilla-overlay">Back</a></p></div>';
        return;
    } catch ( Throwable $e ) {
        error_log( 'Serpzilla Overlay Admin Sync Exception: ' . $e->getMessage() );
        echo '<div class="wrap"><h1>Serpzilla Overlay</h1><p style="color:red">Exception: ' . esc_html( $e->getMessage() ) . '</p><p><a href="?page=serpzilla-overlay">Back</a></p></div>';
        return;
    }
}

add_action( 'admin_post_serpzilla_status', 'serpzilla_admin_status' );
add_action( 'admin_post_nopriv_serpzilla_status', 'serpzilla_admin_status' );
function serpzilla_admin_status() {
    try {
        $auth = serpzilla_bearer_token();
        $admin_token = (string) get_option( 'serpzilla_admin_token', '' );
        if ( $admin_token === '' || ! hash_equals( 'Bearer ' . $admin_token, (string) $auth ) ) {
            error_log( 'Serpzilla Overlay Admin Status Unauthorized: invalid token' );
            status_header( 401 );
            wp_die( esc_html__( 'Unauthorized', SERPZILLA_TEXT_DOMAIN ), esc_html__( 'Unauthorized', SERPZILLA_TEXT_DOMAIN ), [ 'response' => 401 ] );
        }
        $val = serpzilla_get_snapshot();
        echo '<div class="wrap"><h1>Serpzilla Overlay - Status</h1>';
        echo '<pre>' . esc_html( print_r( [ 'cached' => !!$val, 'updatedAt' => $val['updatedAt'] ?? null ], true ) ) . '</pre>';
        echo '<p><a href="?page=serpzilla-overlay">Back</a></p></div>';
        return;
    } catch ( Throwable $e ) {
        error_log( 'Serpzilla Overlay Admin Status Exception: ' . $e->getMessage() );
        echo '<div class="wrap"><h1>Serpzilla Overlay</h1><p style="color:red">Exception: ' . esc_html( $e->getMessage() ) . '</p><p><a href="?page=serpzilla-overlay">Back</a></p></div>';
        return;
    }
}

// ---------------- Widget: tampilkan blok link di sidebar ----------------
add_action( 'widgets_init', function() {
    if ( class_exists( 'WP_Widget' ) ) register_widget( 'Serpzilla_Overlay_Widget' );
} );

if ( class_exists( 'WP_Widget' ) ) :

class Serpzilla_Overlay_Widget extends WP_Widget {

    public $show_instance_in_rest = true;

    public function __construct() {
        parent::__construct(
            'serpzilla_overlay_widget',
            'Serpzilla Overlay (Links)',
            [ 'description' => 'Menampilkan blok link rental Serpzilla untuk halaman yang sedang dibuka. Ganti Widget Sidebar.' ]
        );
    }

    public function widget( $args, $instance ) {
        // Per-request state only. Never persist render state to the database,
        // otherwise the widget would render once and never appear again.
        static $rendered_instances = [];
        if ( ! empty( $rendered_instances[ $this->id ] ) ) {
            return;
        }

        if ( get_option( 'serpzilla_enabled', 'true' ) !== 'true' ) return;
        if ( ! serpzilla_host_matches() ) return;

        $data = serpzilla_get_snapshot();
        if ( ! $data ) {
            if ( function_exists( 'wp_schedule_single_event' ) ) wp_schedule_single_event( time() + 1, 'serpzilla_bg_sync' );
            return;
        }

        $uri = serpzilla_request_uri();
        $bot = serpzilla_is_bot_request();

        $rental = serpzilla_rental_html( $data['links'], $uri, $bot );

        // Mandatory script: render only once per request, in memory.
        $mandatory = '';
        if ( empty( $GLOBALS['serpzilla_mandatory_done'] ) ) {
            $mandatory = $data['links']['__backlinks_page_obligatory_output__']
                ?? $data['context']['__backlinks_page_obligatory_output__']
                ?? '';
            if ( $mandatory !== '' ) $GLOBALS['serpzilla_mandatory_done'] = true;
        }

        $content = $rental . $mandatory;
        if ( trim( $content ) === '' ) return;

        // Mark this instance as rendered for the current request.
        $rendered_instances[ $this->id ] = true;
        $GLOBALS['serpzilla_footer_done'] = true;

        // Halaman tanpa link: cukup script wajib, tanpa judul/wrapper kosong.
        if ( trim( $rental ) === '' ) {
            echo $mandatory;
            return;
        }

        echo $args['before_widget'];
        if ( ! empty( $instance['title'] ) ) {
            echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title'];
        }
        echo '<div data-serpzilla-rendered="true" class="serpzilla-widget-block">' . $content . '</div>';
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Judul (opsional):</label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   value="<?php echo esc_attr( $title ); ?>"/>
        </p>
        <p style="color:#666">Blok link mengikuti halaman yang sedang dibuka. Jika widget ini aktif, blok otomatis di footer tidak ditampilkan.</p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [ 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) ];
    }
}

endif;
