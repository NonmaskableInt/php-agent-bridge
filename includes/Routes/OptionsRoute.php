<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Files\Backups;
use AgentBridge\Idempotency;
use AgentBridge\Options\Registry;
use AgentBridge\Options\Validator;
use AgentBridge\Response;

/**
 * Typed, grouped access to site options. See Options\Registry for what is allowed and why.
 * Writes are all-or-nothing: every key is checked before any is saved.
 */
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

        register_rest_route( self::NAMESPACE, '/options/schema', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_schema' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function get_options( \WP_REST_Request $request ): \WP_REST_Response {
        $allowed = Registry::allowed_keys();
        $keys    = $request->get_param( 'keys' );
        $keys    = empty( $keys ) ? $allowed : (array) $keys;

        $result = [];
        foreach ( $keys as $key ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $allowed, true ) ) {
                return Response::error( 'option_not_allowed', Registry::refusal( $key ), 403 );
            }
            $result[ $key ] = Registry::typed( $key, get_option( $key ) );
        }
        return Response::success( $result );
    }

    public static function update_options( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) || ! $data ) {
            return Response::validation_error( 'Request body must contain option key-value pairs.' );
        }

        $allowed = Registry::allowed_keys();

        // Phase 1a: every key must be allowed.
        $refused = [];
        foreach ( array_keys( $data ) as $key ) {
            $clean = is_string( $key ) ? sanitize_key( $key ) : '';
            if ( $clean === '' || ! in_array( $clean, $allowed, true ) ) {
                $refused[] = Registry::refusal( (string) $key );
            }
        }
        if ( $refused ) {
            return Response::error( 'option_not_allowed', implode( ' ', $refused ), 403 );
        }

        // Phase 1b: validate every value; collect all problems, write nothing yet.
        $clean_values = [];
        $errors       = [];
        foreach ( $data as $key => $value ) {
            $key   = sanitize_key( $key );
            $found = Registry::find( $key );
            if ( $found === null ) {
                // A key the site owner listed explicitly in AGENT_BRIDGE_ALLOWED_OPTIONS: passed through as before.
                $clean_values[ $key ] = $value;
                continue;
            }
            [ $normalised, $error ] = Validator::check( $key, $found['spec'], $value );
            if ( $error !== null ) {
                $errors[ $key ] = $error;
            } else {
                $clean_values[ $key ] = $normalised;
            }
        }
        $errors += self::cross_field_errors( $clean_values, $errors );

        if ( $errors ) {
            $message = implode( '; ', array_map( fn( $k, $e ) => "{$k} {$e}", array_keys( $errors ), $errors ) );
            return new \WP_REST_Response(
                [ 'ok' => false, 'code' => 'validation_error', 'message' => $message, 'data' => [ 'status' => 422, 'errors' => $errors ] ],
                422
            );
        }

        // Phase 2: apply.
        $before  = [];
        $updated = [];
        foreach ( $clean_values as $key => $value ) {
            $before[ $key ] = get_option( $key );
            self::apply( $key, $value );
            $updated[ $key ] = Registry::typed( $key, get_option( $key ) );
        }
        if ( array_intersect_key( $clean_values, array_flip( [ 'permalink_structure', 'category_base', 'tag_base' ] ) ) ) {
            flush_rewrite_rules( false );
        }
        Backups::audit( WP_CONTENT_DIR, 'options_set', 'options', [ 'keys' => array_keys( $clean_values ) ] );

        return Response::success( [ 'updated' => $updated ] );
    }

    public static function get_schema(): \WP_REST_Response {
        $enabled = Registry::enabled_groups();
        $legacy  = Registry::legacy_allowlist();
        $groups  = [];
        foreach ( Registry::groups() as $name => $keys ) {
            $groups[ $name ] = [
                'enabled'     => $legacy === null && in_array( $name, $enabled, true ),
                'default'     => in_array( $name, Registry::DEFAULT_GROUPS, true ),
                'description' => Registry::GROUP_INFO[ $name ],
                'keys'        => $keys,
            ];
        }
        return Response::success( [
            'groups'         => $groups,
            'never_settable' => Registry::NEVER,
            'allowed_keys'   => Registry::allowed_keys(),
            'legacy_allowlist_in_use' => $legacy !== null,
        ] );
    }

    // -------------------------------------------------------------- helpers

    /** Rules that involve more than one key, judged on the state AFTER the change. */
    private static function cross_field_errors( array $values, array $already ): array {
        $errors = [];
        if ( ! array_intersect_key( $values, array_flip( [ 'show_on_front', 'page_on_front', 'page_for_posts' ] ) ) ) {
            return $errors;
        }
        $get = static fn( string $k ) => array_key_exists( $k, $values ) ? $values[ $k ] : get_option( $k );

        $mode  = $get( 'show_on_front' );
        $front = (int) $get( 'page_on_front' );
        $posts = (int) $get( 'page_for_posts' );

        if ( $mode === 'page' && $front === 0 && ! isset( $already['page_on_front'], $already['show_on_front'] ) ) {
            $errors['show_on_front'] = "cannot be 'page' without a valid page_on_front (set page_on_front in the same request)";
        }
        if ( $front !== 0 && $front === $posts && ! isset( $already['page_for_posts'], $already['page_on_front'] ) ) {
            $errors['page_for_posts'] = 'must be a different page from page_on_front';
        }
        return $errors;
    }

    private static function apply( string $key, $value ): void {
        global $wp_rewrite;
        if ( $key === 'permalink_structure' && $wp_rewrite ) {
            $wp_rewrite->set_permalink_structure( $value );
            return;
        }
        update_option( $key, $value );
    }
}
