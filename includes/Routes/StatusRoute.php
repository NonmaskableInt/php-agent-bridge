<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Files\PhpGuard;
use AgentBridge\Options\Registry as OptionRegistry;
use AgentBridge\Response;

/**
 * GET /status: what this bridge is and what it is allowed to do on this site, for diagnostics.
 * Reports THAT things are enabled, never secrets, credentials or server paths.
 */
class StatusRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/status', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_status' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function get_status(): \WP_REST_Response {
        $flag = static fn( string $name ): bool => defined( $name ) && (bool) constant( $name );
        $user = wp_get_current_user();

        $header  = get_file_data( dirname( __DIR__, 2 ) . '/agent-bridge.php', [ 'Version' => 'Version' ] );
        $routes  = array_filter( array_keys( rest_get_server()->get_routes() ), static fn( $r ) => strpos( $r, '/' . self::NAMESPACE ) === 0 );
        $backup  = defined( 'AGENT_BRIDGE_BACKUP_DIR' ) && is_string( AGENT_BRIDGE_BACKUP_DIR ) && AGENT_BRIDGE_BACKUP_DIR !== '';

        return Response::success( [
            'bridge'    => [
                'version'   => $header['Version'] ?: 'unknown',
                'namespace' => self::NAMESPACE,
                'routes'    => count( $routes ),
            ],
            'wordpress' => [
                'version'   => get_bloginfo( 'version' ),
                'multisite' => is_multisite(),
            ],
            'php'       => [ 'version' => PHP_VERSION ],
            'site'      => [
                'url'        => home_url(),
                'theme'      => get_stylesheet(),
                'theme_type' => wp_is_block_theme() ? 'block' : 'classic',
            ],
            'features'  => [
                'file_write'         => $flag( 'AGENT_BRIDGE_ALLOW_FILE_WRITE' ),
                'file_edit_blocked'  => $flag( 'DISALLOW_FILE_EDIT' ) || $flag( 'DISALLOW_FILE_MODS' ),
                'theme_install'      => $flag( 'AGENT_BRIDGE_ALLOW_THEME_INSTALL' ),
                'plugin_install'     => $flag( 'AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL' ),
                'cli'                => [ 'enabled' => true, 'mode' => $flag( 'AGENT_BRIDGE_CLI_OPEN' ) ? 'open' : 'allowlist' ],
                'db_api'             => $flag( 'AGENT_BRIDGE_DB_API' ),
                'content_model'      => ! $flag( 'AGENT_BRIDGE_DISABLE_CONTENT_MODEL' ),
                'option_groups'      => OptionRegistry::legacy_allowlist() === null ? OptionRegistry::enabled_groups() : [],
                'legacy_option_list' => OptionRegistry::legacy_allowlist() !== null,
                'backup_dir'         => $backup ? 'custom' : 'default',
                // PHP writes and template resolving need a php binary and proc_open; shared hosts sometimes lack them.
                'php_verification'   => PhpGuard::php_binary() !== null && function_exists( 'proc_open' ),
            ],
            'user'      => [
                'login' => $user->user_login,
                'roles' => array_values( $user->roles ),
                'can'   => [
                    'manage_options'     => current_user_can( 'manage_options' ),
                    'edit_themes'        => current_user_can( 'edit_themes' ),
                    'edit_plugins'       => current_user_can( 'edit_plugins' ),
                    'edit_theme_options' => current_user_can( 'edit_theme_options' ),
                    'unfiltered_html'    => current_user_can( 'unfiltered_html' ),
                ],
            ],
        ] );
    }
}
