<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class ThemesRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/themes', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_themes' ],
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/install', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'install_theme' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/mods', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_theme_mods' ],
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_theme_mods' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/customizer', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'export_customizer' ],
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'import_customizer' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/(?P<stylesheet>[a-z0-9_-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_theme' ],
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_theme' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/(?P<stylesheet>[a-z0-9_-]+)/activate', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'activate_theme' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/themes/(?P<stylesheet>[a-z0-9_-]+)/update', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_theme' ] ),
                'permission_callback' => [ Auth::class, 'require_theme_management' ],
            ],
        ] );
    }

    public static function list_themes( \WP_REST_Request $request ): \WP_REST_Response {
        $themes  = wp_get_themes();
        $active  = get_stylesheet();
        $updates = get_site_transient( 'update_themes' );

        $result = [];
        foreach ( $themes as $stylesheet => $theme ) {
            $has_update = isset( $updates->response[ $stylesheet ] );
            $result[]   = [
                'stylesheet'  => $stylesheet,
                'name'        => $theme->get( 'Name' ),
                'version'     => $theme->get( 'Version' ),
                'active'      => $stylesheet === $active,
                'parent'      => $theme->get( 'Template' ) ?: null,
                'screenshot'  => $theme->get_screenshot(),
                'has_update'  => $has_update,
                'new_version' => $has_update ? $updates->response[ $stylesheet ]['new_version'] : null,
            ];
        }

        return Response::success( $result );
    }

    public static function get_theme( \WP_REST_Request $request ): \WP_REST_Response {
        $stylesheet = sanitize_text_field( $request->get_param( 'stylesheet' ) );
        $theme      = wp_get_theme( $stylesheet );

        if ( ! $theme->exists() ) {
            return Response::error( 'theme_not_found', "Theme '{$stylesheet}' is not installed.", 404 );
        }

        return Response::success( [
            'stylesheet'  => $theme->get_stylesheet(),
            'template'    => $theme->get_template(),
            'name'        => $theme->get( 'Name' ),
            'version'     => $theme->get( 'Version' ),
            'author'      => $theme->get( 'Author' ),
            'description' => $theme->get( 'Description' ),
            'active'      => get_stylesheet() === $stylesheet,
            'parent'      => $theme->get( 'Template' ) ?: null,
            'screenshot'  => $theme->get_screenshot(),
        ] );
    }

    public static function install_theme( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_THEME_INSTALL' ) && AGENT_BRIDGE_ALLOW_THEME_INSTALL ) ) {
            return Response::error(
                'theme_install_disabled',
                'Theme installation is disabled. Set AGENT_BRIDGE_ALLOW_THEME_INSTALL to true.',
                403
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $data     = $request->get_json_params() ?? [];
        $source   = sanitize_text_field( $data['source'] ?? 'wordpress.org' );
        $activate = filter_var( $data['activate'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $source === 'wordpress.org' ) {
            $slug = sanitize_key( $data['slug'] ?? '' );
            if ( ! $slug ) {
                return Response::validation_error( 'Theme slug is required for wordpress.org source.' );
            }
            $api = themes_api( 'theme_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
            if ( is_wp_error( $api ) ) {
                return Response::error( 'theme_not_found', $api->get_error_message(), 404 );
            }
            $package = $api->download_link;
        } elseif ( $source === 'url' ) {
            $url = esc_url_raw( $data['url'] ?? '' );
            if ( ! $url ) {
                return Response::validation_error( 'URL is required for url source.' );
            }
            $package = $url;
        } else {
            return Response::validation_error( "Unknown source '{$source}'. Use 'wordpress.org' or 'url'." );
        }

        WP_Filesystem();

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader( $skin );
        $result   = $upgrader->install( $package );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'theme_install_failed', $result->get_error_message(), 500 );
        }

        $stylesheet = $upgrader->result['destination_name'] ?? null;

        if ( $activate && $stylesheet ) {
            switch_theme( $stylesheet );
        }

        return Response::created( [
            'installed'   => true,
            'stylesheet'  => $stylesheet,
            'activated'   => $activate && $stylesheet,
        ] );
    }

    public static function activate_theme( \WP_REST_Request $request ): \WP_REST_Response {
        $stylesheet = sanitize_text_field( $request->get_param( 'stylesheet' ) );
        $theme      = wp_get_theme( $stylesheet );

        if ( ! $theme->exists() ) {
            return Response::error( 'theme_not_found', "Theme '{$stylesheet}' is not installed.", 404 );
        }

        // Check parent is installed for child themes
        $parent_stylesheet = $theme->get( 'Template' );
        if ( $parent_stylesheet && $parent_stylesheet !== $stylesheet ) {
            $parent = wp_get_theme( $parent_stylesheet );
            if ( ! $parent->exists() ) {
                return Response::error(
                    'theme_parent_missing',
                    "Parent theme '{$parent_stylesheet}' is not installed.",
                    422
                );
            }
        }

        if ( get_stylesheet() === $stylesheet ) {
            return Response::success( [ 'activated' => true, 'already_active' => true ] );
        }

        switch_theme( $stylesheet );

        return Response::success( [ 'activated' => true, 'stylesheet' => $stylesheet ] );
    }

    public static function update_theme( \WP_REST_Request $request ): \WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $stylesheet = sanitize_text_field( $request->get_param( 'stylesheet' ) );
        $theme      = wp_get_theme( $stylesheet );

        if ( ! $theme->exists() ) {
            return Response::error( 'theme_not_found', "Theme '{$stylesheet}' is not installed.", 404 );
        }

        WP_Filesystem();

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader( $skin );
        $result   = $upgrader->upgrade( $stylesheet );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'theme_update_failed', $result->get_error_message(), 500 );
        }

        if ( $result === false ) {
            return Response::success( [ 'updated' => false, 'message' => 'Theme is already up to date.' ] );
        }

        return Response::success( [ 'updated' => true, 'stylesheet' => $stylesheet ] );
    }

    public static function delete_theme( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_THEME_INSTALL' ) && AGENT_BRIDGE_ALLOW_THEME_INSTALL ) ) {
            return Response::error(
                'theme_install_disabled',
                'Theme deletion is disabled. Set AGENT_BRIDGE_ALLOW_THEME_INSTALL to true.',
                403
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $stylesheet = sanitize_text_field( $request->get_param( 'stylesheet' ) );
        $theme      = wp_get_theme( $stylesheet );

        if ( ! $theme->exists() ) {
            return Response::error( 'theme_not_found', "Theme '{$stylesheet}' is not installed.", 404 );
        }

        if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
            return Response::error( 'theme_is_active', 'Cannot delete the currently active theme.', 409 );
        }

        WP_Filesystem();

        $result = delete_theme( $stylesheet );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'theme_delete_failed', $result->get_error_message(), 500 );
        }

        return Response::success( [ 'deleted' => true, 'stylesheet' => $stylesheet ] );
    }

    public static function get_theme_mods( \WP_REST_Request $request ): \WP_REST_Response {
        return Response::success( get_theme_mods() ?: [] );
    }

    public static function update_theme_mods( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];

        if ( empty( $data ) ) {
            return Response::validation_error( 'Request body must contain theme mod key-value pairs.' );
        }

        $current_mods   = get_theme_mods() ?: [];
        $known_mod_keys = array_keys( $current_mods );

        // Also allow well-known WordPress mod keys
        $well_known = [
            'custom_logo', 'custom_css_post_id', 'header_textcolor', 'header_image',
            'background_color', 'background_image', 'nav_menu_locations',
        ];

        $updated = [];
        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $known_mod_keys, true ) && ! in_array( $key, $well_known, true ) ) {
                return Response::error(
                    'theme_mod_not_allowed',
                    "Theme mod key '{$key}' is not recognized.",
                    422
                );
            }
            set_theme_mod( $key, $value );
            $updated[ $key ] = get_theme_mod( $key );
        }

        return Response::success( [ 'updated' => $updated ] );
    }

    public static function export_customizer( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $mods = get_theme_mods() ?: [];

        // Export widget options for current sidebar registrations
        $widget_options = [];
        $sidebars       = wp_get_sidebars_widgets();
        foreach ( array_unique( array_merge( ...array_values( $sidebars ) ) ) as $widget_id ) {
            $type = preg_replace( '/-\d+$/', '', $widget_id );
            $opts = get_option( "widget_{$type}" );
            if ( $opts !== false ) {
                $widget_options[ "widget_{$type}" ] = $opts;
            }
        }

        return Response::success( [
            'stylesheet'     => get_stylesheet(),
            'theme_mods'     => $mods,
            'widget_options' => $widget_options,
            'sidebars'       => $sidebars,
        ] );
    }

    public static function import_customizer( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];

        if ( empty( $data['theme_mods'] ) || ! is_array( $data['theme_mods'] ) ) {
            return Response::validation_error( 'Import data must include a theme_mods array.' );
        }

        // Basic schema validation
        if ( isset( $data['stylesheet'] ) && $data['stylesheet'] !== get_stylesheet() ) {
            // Warn but proceed — allow cross-theme import for compatibility
            do_action( 'agent_bridge_customizer_import_stylesheet_mismatch', $data['stylesheet'], get_stylesheet() );
        }

        foreach ( $data['theme_mods'] as $key => $value ) {
            set_theme_mod( sanitize_key( $key ), $value );
        }

        if ( ! empty( $data['widget_options'] ) && is_array( $data['widget_options'] ) ) {
            foreach ( $data['widget_options'] as $option_key => $option_value ) {
                // Only allow widget_* options
                if ( strpos( $option_key, 'widget_' ) === 0 ) {
                    update_option( sanitize_key( $option_key ), $option_value );
                }
            }
        }

        if ( ! empty( $data['sidebars'] ) && is_array( $data['sidebars'] ) ) {
            wp_set_sidebars_widgets( $data['sidebars'] );
        }

        return Response::success( [
            'imported'   => true,
            'theme_mods' => get_theme_mods(),
        ] );
    }
}
