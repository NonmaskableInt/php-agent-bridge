<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Files\WriteGate;
use AgentBridge\Idempotency;
use AgentBridge\Response;
use AgentBridge\Themes\Scaffold;
use AgentBridge\Themes\BlockMarkup;
use AgentBridge\Themes\Validator;

/** Checks that let an agent verify what it wrote before activating it, and one-step theme scaffolding. */
class ThemeToolsRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/theme-tools/validate', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'validate_theme' ], 'permission_callback' => [ Auth::class, 'require_manage' ] ],
        ] );
        register_rest_route( self::NAMESPACE, '/theme-tools/scaffold', [
            [ 'methods' => 'POST', 'callback' => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'scaffold' ] ), 'permission_callback' => [ Auth::class, 'require_manage' ] ],
        ] );
        register_rest_route( self::NAMESPACE, '/theme-tools/validate-markup', [
            [ 'methods' => 'POST', 'callback' => [ self::class, 'validate_markup' ], 'permission_callback' => [ Auth::class, 'require_manage' ] ],
        ] );
    }

    public static function validate_theme( \WP_REST_Request $request ): \WP_REST_Response {
        $name = $request->get_param( 'stylesheet' );
        if ( $name === null || $name === '' ) {
            $name = get_stylesheet();
        }
        if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name ) ) {
            return Response::validation_error( "'stylesheet' must be an installed theme's directory name." );
        }
        $theme = wp_get_theme( $name );
        if ( ! $theme->exists() ) {
            return Response::error( 'theme_not_found', "Theme '{$name}' is not installed.", 404 );
        }
        return Response::success( Validator::validate( $theme ) );
    }

    /** Creates a theme directory, so it is gated like every other file write. */
    public static function scaffold( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $gate = WriteGate::check_site() ?? WriteGate::check_capability( 'themes' ) ) {
            return $gate;
        }
        $r = Scaffold::create( $request->get_json_params() ?? [] );
        return $r['ok'] ? Response::created( $r['result'] ) : Response::error( $r['code'], $r['message'], $r['status'] );
    }

    public static function validate_markup( \WP_REST_Request $request ): \WP_REST_Response {
        $data    = $request->get_json_params() ?? [];
        $content = $data['content'] ?? null;
        if ( ! is_string( $content ) ) {
            return Response::validation_error( "'content' (block markup, a string) is required." );
        }
        if ( strlen( $content ) > BlockMarkup::MAX_BYTES ) {
            return Response::validation_error( "'content' is larger than " . BlockMarkup::MAX_BYTES . ' bytes.' );
        }
        return Response::success( BlockMarkup::check( $content ) );
    }
}
