<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Files\Backups;
use AgentBridge\Headless\Config;
use AgentBridge\Idempotency;
use AgentBridge\Response;

/**
 * Configure WordPress as a headless CMS: CORS allowlist for SPA origins, optional redirect of the
 * public site to a separate front end, and editor preview links that point at it.
 * Changes site options only (no file writes), so no AGENT_BRIDGE_ALLOW_FILE_WRITE needed.
 */
class HeadlessRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/headless', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_config' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_config' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/headless/preview-link', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'preview_link' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );
    }

    public static function get_config(): \WP_REST_Response {
        return Response::success( self::describe() );
    }

    public static function update_config( \WP_REST_Request $request ): \WP_REST_Response {
        $patch  = $request->get_json_params();
        $before = Config::get();
        $result = Config::update( is_array( $patch ) ? $patch : [] );
        if ( ! $result['ok'] ) {
            $status = $result['code'] === 'validation_error' ? 422 : 409;
            return Response::error( $result['code'], $result['message'], $status );
        }
        Backups::audit( WP_CONTENT_DIR, 'headless_config', 'option:' . Config::OPTION, [
            'changed' => array_keys( array_filter( $result['config'], fn( $v, $k ) => $v !== $before[ $k ], ARRAY_FILTER_USE_BOTH ) ),
        ] );
        return Response::success( self::describe() );
    }

    public static function preview_link( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'post_id' );
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return Response::error( 'post_not_found', "Post {$id} does not exist.", 404 );
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return Response::forbidden();
        }
        $cfg = Config::get();
        return Response::success( [
            'post_id'  => $post->ID,
            'url'      => get_preview_post_link( $post ),
            'headless' => (bool) ( $cfg['preview'] && $cfg['frontend_url'] ),
        ] );
    }

    private static function describe(): array {
        $cfg = Config::get();
        return [
            'config' => $cfg,
            'status' => [
                'site_origin'     => Config::site_origin(),
                'home_url'        => home_url(),
                'rest_url'        => rest_url(),
                'cors_active'     => (bool) $cfg['cors_origins'],
                'redirect_active' => (bool) ( $cfg['redirect_frontend'] && $cfg['frontend_url'] ),
            ],
        ];
    }
}
