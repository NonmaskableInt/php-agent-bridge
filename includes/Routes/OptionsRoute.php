<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class OptionsRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/options', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_options' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_options' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function get_options( \WP_REST_Request $request ): \WP_REST_Response {
        $allowlist = self::get_allowlist();
        $keys      = $request->get_param( 'keys' );

        if ( empty( $keys ) ) {
            // Return all allowlisted options
            $keys = $allowlist;
        } else {
            $keys = (array) $keys;
        }

        $result = [];
        foreach ( $keys as $key ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $allowlist, true ) ) {
                return Response::error(
                    'option_not_allowed',
                    "Option key '{$key}' is not in the allowlist.",
                    403
                );
            }
            $result[ $key ] = get_option( $key );
        }

        return Response::success( $result );
    }

    public static function update_options( \WP_REST_Request $request ): \WP_REST_Response {
        $allowlist = self::get_allowlist();
        $data      = $request->get_json_params() ?? [];

        if ( empty( $data ) ) {
            return Response::validation_error( 'Request body must contain option key-value pairs.' );
        }

        $updated = [];
        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $allowlist, true ) ) {
                return Response::error(
                    'option_not_allowed',
                    "Option key '{$key}' is not in the allowlist.",
                    403
                );
            }
            update_option( $key, $value );
            $updated[ $key ] = get_option( $key );
        }

        return Response::success( [ 'updated' => $updated ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function get_allowlist(): array {
        if ( defined( 'AGENT_BRIDGE_ALLOWED_OPTIONS' ) && is_array( AGENT_BRIDGE_ALLOWED_OPTIONS ) ) {
            return AGENT_BRIDGE_ALLOWED_OPTIONS;
        }

        return [
            'blogname',
            'blogdescription',
            'siteurl',
            'home',
            'default_comment_status',
            'posts_per_page',
        ];
    }
}
