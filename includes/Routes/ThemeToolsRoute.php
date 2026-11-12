<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Response;
use AgentBridge\Themes\BlockMarkup;
use AgentBridge\Themes\Validator;

/** Read-only checks that let an agent verify what it wrote before trusting or activating it. */
class ThemeToolsRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/theme-tools/validate', [
            [ 'methods' => 'GET', 'callback' => [ self::class, 'validate_theme' ], 'permission_callback' => [ Auth::class, 'require_manage' ] ],
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
