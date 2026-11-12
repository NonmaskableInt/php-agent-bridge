<?php
/**
 * Plugin Name:  Agent Bridge
 * Plugin URI:   https://github.com/your-org/agent-bridge
 * Description:  Structured, agent-friendly REST API layer on top of WordPress. Every operation is atomic, idempotent, and returns machine-readable JSON.
 * Version:      1.0.0
 * Requires PHP: 8.1
 * Author:       Your Name
 * License:      MIT
 *
 * @package AgentBridge
 */

defined( 'ABSPATH' ) || exit;

// -------------------------------------------------------------------------
// Autoloader
// -------------------------------------------------------------------------

spl_autoload_register( function ( string $class ): void {
    $prefix   = 'AgentBridge\\';
    $base_dir = __DIR__ . '/includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative = substr( $class, $len );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// -------------------------------------------------------------------------
// Activation / Deactivation
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, function (): void {
    \AgentBridge\Idempotency::create_table();

    if ( ! wp_next_scheduled( 'agent_bridge_cleanup_idempotency' ) ) {
        wp_schedule_event( time(), 'hourly', 'agent_bridge_cleanup_idempotency' );
    }
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'agent_bridge_cleanup_idempotency' );
} );

add_action( 'agent_bridge_cleanup_idempotency', [ \AgentBridge\Idempotency::class, 'cleanup' ] );

// -------------------------------------------------------------------------
// Request logging
// -------------------------------------------------------------------------

if ( defined( 'AGENT_BRIDGE_LOG_REQUESTS' ) && AGENT_BRIDGE_LOG_REQUESTS ) {
    add_action( 'rest_api_init', function (): void {
        add_filter( 'rest_pre_dispatch', function ( $result, $server, \WP_REST_Request $request ) {
            if ( strpos( $request->get_route(), '/agent/v1/' ) === 0 ) {
                $log_line = sprintf(
                    "[%s] %s %s user=%d\n",
                    gmdate( 'Y-m-d H:i:s' ),
                    $request->get_method(),
                    $request->get_route(),
                    get_current_user_id()
                );
                // Not under a fixed, web-reachable name: see Files\Backups::dir().
                file_put_contents(
                    \AgentBridge\Files\Backups::dir( WP_CONTENT_DIR ) . '/requests.log',
                    $log_line,
                    FILE_APPEND | LOCK_EX
                );
            }
            return $result;
        }, 10, 3 );
    } );
}

// Headless mode: CORS allowlist, front-end redirect, preview links.
\AgentBridge\Headless\Runtime::boot();

// Custom post types / taxonomies / meta defined through the content model.
\AgentBridge\ContentModel\Runtime::boot();

// Serve deployed apps at their mount paths (runs before WordPress templating).
add_action( 'template_redirect', [ \AgentBridge\Apps\Server::class, 'maybe_serve' ], 0 );

// -------------------------------------------------------------------------
// Route Registration
// -------------------------------------------------------------------------

add_action( 'rest_api_init', function (): void {
    \AgentBridge\Routes\PostsRoute::register();
    \AgentBridge\Routes\MediaRoute::register();
    \AgentBridge\Routes\TaxonomyRoute::register();
    \AgentBridge\Routes\OptionsRoute::register();
    \AgentBridge\Routes\UsersRoute::register();
    \AgentBridge\Routes\MenusRoute::register();
    \AgentBridge\Routes\PluginsRoute::register();
    \AgentBridge\Routes\ThemesRoute::register();
    \AgentBridge\Routes\CliRoute::register();
    \AgentBridge\Routes\DatabaseRoute::register();
    \AgentBridge\Routes\FilesRoute::register();
    \AgentBridge\Routes\AppsRoute::register();
    \AgentBridge\Routes\HeadlessRoute::register();
    \AgentBridge\Routes\ContentModelRoute::register();
    \AgentBridge\Routes\TemplatesRoute::register();
    \AgentBridge\Routes\ThemeToolsRoute::register();
    \AgentBridge\Routes\GlobalStylesRoute::register();

    // Optional WooCommerce module — loaded only when WooCommerce is active
    if ( class_exists( 'WooCommerce' ) ) {
        \AgentBridge\Modules\WooCommerce::register();
    }
} );

// -------------------------------------------------------------------------
// Ensure JSON error responses (never HTML) for this namespace
// -------------------------------------------------------------------------

add_filter( 'rest_authentication_errors', function ( $result ) {
    // Only act on unauthenticated requests to our namespace
    $route = $_SERVER['REQUEST_URI'] ?? '';
    if ( strpos( $route, '/agent/v1/' ) === false ) {
        return $result;
    }

    if ( ! is_user_logged_in() && $result === null ) {
        return new \WP_Error(
            'auth_required',
            'Missing or invalid credentials.',
            [ 'status' => 401 ]
        );
    }

    return $result;
} );
