<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;
use AgentBridge\GlobalStyles\Store;

/** The Site Editor's "Styles" layer: read it, merge changes into it, reset it with a backup. */
class GlobalStylesRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        $manage = [ Auth::class, 'require_manage' ];
        register_rest_route( self::NAMESPACE, '/global-styles', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'get_styles' ], 'permission_callback' => $manage ],
            [ 'methods' => 'POST', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'set_styles' ] ), 'permission_callback' => $manage ],
            [ 'methods' => 'DELETE', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'reset_styles' ] ), 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/global-styles/backups', [
            [ 'methods' => 'GET', 'callback' => fn() => Response::success( [ 'backups' => Store::backups() ] ), 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/global-styles/restore', [
            [ 'methods' => 'POST', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'restore_styles' ] ), 'permission_callback' => $manage ],
        ] );
    }

    public static function get_styles( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! Store::supported() ) {
            return self::error( Store::unsupported() );
        }
        return Response::success( Store::describe( $request->get_param( 'include' ) === 'theme' ) );
    }

    public static function set_styles( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $denied = self::guard() ) {
            return $denied;
        }
        $r = Store::set( $request->get_json_params() ?? [] );
        return $r['ok'] ? Response::success( $r['result'] ) : self::error( $r );
    }

    public static function reset_styles(): \WP_REST_Response {
        if ( $denied = self::guard() ) {
            return $denied;
        }
        $r = Store::reset();
        return $r['ok'] ? Response::success( $r['result'] ) : self::error( $r );
    }

    public static function restore_styles( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $denied = self::guard() ) {
            return $denied;
        }
        $data = $request->get_json_params() ?? [];
        $r    = Store::restore( $data['name'] ?? null );
        return $r['ok'] ? Response::success( $r['result'] ) : self::error( $r );
    }

    private static function guard(): ?\WP_REST_Response {
        return current_user_can( 'edit_theme_options' )
            ? null
            : Response::error( 'insufficient_capability', 'Changing global styles requires the edit_theme_options capability.', 403 );
    }

    private static function error( array $e ): \WP_REST_Response {
        return Response::error( $e['code'], $e['message'], $e['status'] );
    }
}
