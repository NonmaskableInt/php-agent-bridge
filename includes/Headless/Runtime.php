<?php

namespace AgentBridge\Headless;

/** Applies the headless config to requests: CORS, front-end redirect, preview links. */
class Runtime {

    public static function boot(): void {
        add_action( 'rest_api_init', [ self::class, 'setup_cors' ], 15 );
        add_action( 'template_redirect', [ self::class, 'maybe_redirect' ], 1 ); // after Apps\Server (0)
        add_filter( 'preview_post_link', [ self::class, 'preview_link' ], 10, 2 );
    }

    // ----------------------------------------------------------------- CORS

    /** With an allowlist set, replace core's reflect-any-origin behaviour with exact matching. */
    public static function setup_cors(): void {
        if ( ! Config::get()['cors_origins'] ) {
            return; // not configured: leave WordPress's default untouched
        }
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', [ self::class, 'send_cors' ] );
    }

    public static function send_cors( $served ) {
        header( 'Vary: Origin', false );
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        if ( $origin !== '' && in_array( $origin, Config::get()['cors_origins'], true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-WP-Nonce, X-Idempotency-Key' );
            header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link, X-Idempotency-Replay' );
            header( 'Access-Control-Max-Age: 600' );
        }
        return $served;
    }

    // ------------------------------------------------------------- redirect

    public static function maybe_redirect(): void {
        $cfg = Config::get();
        if ( ! $cfg['redirect_frontend'] || ! $cfg['frontend_url'] ) {
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ( $method !== 'GET' && $method !== 'HEAD' ) {
            return;
        }
        if ( is_robots() || is_favicon() || is_embed() || is_trackback() || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
            return;
        }

        // Split by hand: parse_url would read '//evil.test/x' as a host.
        $uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
        $qpos  = strpos( $uri, '?' );
        $path  = $qpos === false ? $uri : substr( $uri, 0, $qpos );
        $query = $qpos === false ? '' : substr( $uri, $qpos + 1 );

        $home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home !== '' && strpos( $path, $home ) === 0 ) {
            $path = substr( $path, strlen( $home ) );
        }
        $path   = '/' . ltrim( $path, "/\\" );
        $target = $cfg['frontend_url'] . $path . ( $query !== '' ? '?' . $query : '' );

        wp_redirect( $target, (int) $cfg['redirect_status'], 'Agent-Bridge' );
        exit;
    }

    // -------------------------------------------------------------- preview

    public static function preview_link( $link, $post ) {
        $cfg = Config::get();
        if ( ! $cfg['preview'] || ! $cfg['frontend_url'] || ! $post instanceof \WP_Post ) {
            return $link;
        }
        return add_query_arg(
            [ 'post_id' => $post->ID, 'post_type' => $post->post_type, 'status' => $post->post_status ],
            $cfg['frontend_url'] . '/preview'
        );
    }
}
