<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class PluginsRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/plugins', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_plugins' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/install', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'install_plugin' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9_-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_plugin' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_plugin' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9_-]+)/activate', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'activate_plugin' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9_-]+)/deactivate', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'deactivate_plugin' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9_-]+)/update', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_plugin' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9_-]+)/options', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_plugin_options' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_plugin_options' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function list_plugins( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active      = get_option( 'active_plugins', [] );
        $updates     = get_site_transient( 'update_plugins' );

        $result = [];
        foreach ( $all_plugins as $file => $data ) {
            $slug = dirname( $file );
            if ( $slug === '.' ) {
                $slug = basename( $file, '.php' );
            }

            $has_update = isset( $updates->response[ $file ] );

            $result[] = [
                'slug'        => $slug,
                'file'        => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'author'      => $data['AuthorName'] ?? $data['Author'],
                'description' => $data['Description'],
                'active'      => in_array( $file, $active, true ),
                'has_update'  => $has_update,
                'new_version' => $has_update ? $updates->response[ $file ]->new_version : null,
            ];
        }

        return Response::success( $result );
    }

    public static function get_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug   = sanitize_key( $request->get_param( 'slug' ) );
        $file   = self::slug_to_file( $slug );

        if ( ! $file ) {
            return Response::error( 'plugin_not_found', "Plugin '{$slug}' is not installed.", 404 );
        }

        $all_plugins = get_plugins();
        $data        = $all_plugins[ $file ];
        $active      = in_array( $file, get_option( 'active_plugins', [] ), true );

        return Response::success( [
            'slug'         => $slug,
            'file'         => $file,
            'name'         => $data['Name'],
            'version'      => $data['Version'],
            'author'       => $data['AuthorName'] ?? $data['Author'],
            'description'  => $data['Description'],
            'active'       => $active,
            'settings_url' => admin_url( "options-general.php?page={$slug}" ),
        ] );
    }

    public static function install_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL' ) && AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL ) ) {
            return Response::error(
                'plugin_install_disabled',
                'Plugin installation is disabled. Set AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL to true.',
                403
            );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $data     = $request->get_json_params() ?? [];
        $source   = sanitize_text_field( $data['source'] ?? 'wordpress.org' );
        $activate = filter_var( $data['activate'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $activate && ! ( defined( 'AGENT_BRIDGE_ALLOW_PLUGIN_AUTOACTIVATE' ) && AGENT_BRIDGE_ALLOW_PLUGIN_AUTOACTIVATE ) ) {
            $activate = false;
        }

        WP_Filesystem();

        if ( $source === 'wordpress.org' ) {
            $slug    = sanitize_key( $data['slug'] ?? '' );
            if ( ! $slug ) {
                return Response::validation_error( 'Plugin slug is required for wordpress.org source.' );
            }
            $api = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
            if ( is_wp_error( $api ) ) {
                return Response::error( 'plugin_not_found', $api->get_error_message(), 404 );
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

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $package );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'plugin_install_failed', $result->get_error_message(), 500 );
        }

        if ( $result === false ) {
            return Response::error( 'plugin_install_failed', 'Installation failed. Check server file permissions.', 500 );
        }

        $plugin_file = $upgrader->plugin_info();

        if ( $activate && $plugin_file ) {
            $activated = activate_plugin( $plugin_file );
            if ( is_wp_error( $activated ) ) {
                return Response::error( 'plugin_activation_error', $activated->get_error_message(), 500 );
            }
        }

        return Response::created( [
            'installed' => true,
            'file'      => $plugin_file,
            'activated' => $activate && $plugin_file,
        ] );
    }

    public static function activate_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = sanitize_key( $request->get_param( 'slug' ) );
        $file = self::slug_to_file( $slug );

        if ( ! $file ) {
            return Response::error( 'plugin_not_found', "Plugin '{$slug}' is not installed.", 404 );
        }

        if ( is_plugin_active( $file ) ) {
            return Response::success( [ 'activated' => true, 'already_active' => true, 'file' => $file ] );
        }

        // Check multisite network-activated constraints
        if ( is_multisite() && ! ( defined( 'AGENT_BRIDGE_ALLOW_NETWORK_PLUGIN_OPS' ) && AGENT_BRIDGE_ALLOW_NETWORK_PLUGIN_OPS ) ) {
            if ( is_network_only_plugin( $file ) ) {
                return Response::error(
                    'plugin_network_only',
                    'This plugin must be network-activated. Enable AGENT_BRIDGE_ALLOW_NETWORK_PLUGIN_OPS.',
                    403
                );
            }
        }

        $result = activate_plugin( $file );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'plugin_activation_error', $result->get_error_message(), 500 );
        }

        return Response::success( [ 'activated' => true, 'file' => $file ] );
    }

    public static function deactivate_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = sanitize_key( $request->get_param( 'slug' ) );
        $file = self::slug_to_file( $slug );

        if ( ! $file ) {
            return Response::error( 'plugin_not_found', "Plugin '{$slug}' is not installed.", 404 );
        }

        deactivate_plugins( $file );

        return Response::success( [ 'deactivated' => true, 'file' => $file ] );
    }

    public static function update_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $slug = sanitize_key( $request->get_param( 'slug' ) );
        $file = self::slug_to_file( $slug );

        if ( ! $file ) {
            return Response::error( 'plugin_not_found', "Plugin '{$slug}' is not installed.", 404 );
        }

        WP_Filesystem();

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $file );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'plugin_update_failed', $result->get_error_message(), 500 );
        }

        if ( $result === false ) {
            return Response::success( [ 'updated' => false, 'message' => 'Plugin is already up to date.' ] );
        }

        return Response::success( [ 'updated' => true, 'file' => $file ] );
    }

    public static function delete_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL' ) && AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL ) ) {
            return Response::error(
                'plugin_install_disabled',
                'Plugin deletion is disabled. Set AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL to true.',
                403
            );
        }

        if ( ! function_exists( 'delete_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $slug = sanitize_key( $request->get_param( 'slug' ) );
        $file = self::slug_to_file( $slug );

        if ( ! $file ) {
            return Response::error( 'plugin_not_found', "Plugin '{$slug}' is not installed.", 404 );
        }

        if ( is_plugin_active( $file ) ) {
            return Response::error( 'plugin_still_active', 'Deactivate the plugin before deleting it.', 409 );
        }

        WP_Filesystem();

        $result = delete_plugins( [ $file ] );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'plugin_delete_failed', $result->get_error_message(), 500 );
        }

        return Response::success( [ 'deleted' => true, 'file' => $file ] );
    }

    public static function get_plugin_options( \WP_REST_Request $request ): \WP_REST_Response {
        $allowlist = self::get_options_allowlist();
        $result    = [];

        foreach ( $allowlist as $key ) {
            $result[ $key ] = get_option( $key );
        }

        return Response::success( $result );
    }

    public static function update_plugin_options( \WP_REST_Request $request ): \WP_REST_Response {
        $allowlist = self::get_options_allowlist();
        $data      = $request->get_json_params() ?? [];
        $updated   = [];

        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $allowlist, true ) ) {
                return Response::error(
                    'plugin_option_not_allowed',
                    "Option key '{$key}' is not in the plugin options allowlist.",
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

    private static function slug_to_file( string $slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        // Try slug/slug.php first (most common convention)
        $candidate = "{$slug}/{$slug}.php";
        if ( isset( $all_plugins[ $candidate ] ) ) {
            return $candidate;
        }

        // Search all plugins for a matching directory slug
        foreach ( array_keys( $all_plugins ) as $file ) {
            $dir = dirname( $file );
            if ( $dir === $slug || basename( $file, '.php' ) === $slug ) {
                return $file;
            }
        }

        return null;
    }

    private static function get_options_allowlist(): array {
        if ( defined( 'AGENT_BRIDGE_PLUGIN_OPTIONS_ALLOWLIST' ) && is_array( AGENT_BRIDGE_PLUGIN_OPTIONS_ALLOWLIST ) ) {
            return AGENT_BRIDGE_PLUGIN_OPTIONS_ALLOWLIST;
        }
        return [];
    }
}
