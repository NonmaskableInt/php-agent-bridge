<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;
use AgentBridge\Templates\Classic;
use AgentBridge\Templates\Resolver;
use AgentBridge\Templates\Store;

/**
 * Inspect which template renders what, and manage block-theme template overrides.
 *
 * In a block theme, anything saved in the Site Editor is stored in the database and OVERRIDES the
 * theme's file — so editing a template file can appear to do nothing. This route makes overrides
 * visible (source, masks_theme_file), shows what they hide, and resets them with a backup.
 */
class TemplatesRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        $manage = [ Auth::class, 'require_manage' ];

        register_rest_route( self::NAMESPACE, '/templates', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'list_templates' ], 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/templates/item', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'get_template' ], 'permission_callback' => $manage ],
            [ 'methods' => 'POST', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'set_template' ] ), 'permission_callback' => $manage ],
            [ 'methods' => 'DELETE', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'reset_template' ] ), 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/templates/resolve', [
            [ 'methods' => 'POST', 'callback' => [ self::class, 'resolve' ], 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/templates/backups', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'backups' ], 'permission_callback' => $manage ],
        ] );
        register_rest_route( self::NAMESPACE, '/templates/restore', [
            [ 'methods' => 'POST', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'restore' ] ), 'permission_callback' => $manage ],
        ] );
    }

    // ---------------------------------------------------------------- reads

    public static function list_templates( \WP_REST_Request $request ): \WP_REST_Response {
        $theme = wp_get_theme();
        $base  = [
            'stylesheet' => $theme->get_stylesheet(),
            'parent'     => $theme->parent() ? $theme->get_template() : null,
            'theme_type' => wp_is_block_theme() ? 'block' : 'classic',
        ];

        if ( ! wp_is_block_theme() ) {
            return Response::success( $base + [ 'files' => Classic::files( $theme ) ] );
        }
        [ $type, $err ] = Store::type( $request->get_param( 'type' ) );
        if ( $err ) {
            return self::error( $err );
        }
        return Response::success( $base + [ 'templates' => Store::list( $type ) ] );
    }

    public static function get_template( \WP_REST_Request $request ): \WP_REST_Response {
        [ $type, $err ] = Store::type( $request->get_param( 'type' ) );
        if ( $err ) {
            return self::error( $err );
        }
        if ( $err = Store::require_block_theme() ) {
            return self::error( $err );
        }
        [ $parsed, $err ] = Store::parse_id( $request->get_param( 'id' ) );
        if ( $err ) {
            return self::error( $err );
        }
        $t = Store::find( $parsed[2], $type );
        if ( $t === null ) {
            return Response::error( 'template_not_found', "No {$type['name']} '{$parsed[2]}'.", 404 );
        }
        return Response::success( Store::describe( $t, $type['name'], true ) );
    }

    public static function resolve( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];
        $r    = Resolver::resolve( $data['url'] ?? null );
        return $r['ok'] ? Response::success( $r['result'] ) : self::error( $r );
    }

    public static function backups(): \WP_REST_Response {
        return Response::success( [ 'backups' => Store::backups() ] );
    }

    // --------------------------------------------------------------- writes

    public static function set_template( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $denied = self::require_edit_theme_options() ) {
            return $denied;
        }
        $r = Store::upsert( $request->get_json_params() ?? [] );
        if ( ! $r['ok'] ) {
            return self::error( $r );
        }
        return $r['created'] ? Response::created( $r['template'] ) : Response::success( $r['template'] );
    }

    public static function reset_template( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $denied = self::require_edit_theme_options() ) {
            return $denied;
        }
        $r = Store::reset( $request->get_param( 'id' ), $request->get_param( 'type' ) );
        return $r['ok'] ? Response::success( $r['result'] ) : self::error( $r );
    }

    public static function restore( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $denied = self::require_edit_theme_options() ) {
            return $denied;
        }
        $data = $request->get_json_params() ?? [];
        $r    = Store::restore( $data['name'] ?? null );
        return $r['ok'] ? Response::success( $r['template'] ) : self::error( $r );
    }

    // -------------------------------------------------------------- helpers

    private static function require_edit_theme_options(): ?\WP_REST_Response {
        return current_user_can( 'edit_theme_options' )
            ? null
            : Response::error( 'insufficient_capability', 'Changing templates requires the edit_theme_options capability.', 403 );
    }

    private static function error( array $e ): \WP_REST_Response {
        return Response::error( $e['code'], $e['message'], $e['status'] );
    }
}
