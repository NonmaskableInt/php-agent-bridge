<?php

namespace AgentBridge\Routes;

use AgentBridge\Apps\Archive;
use AgentBridge\Apps\Registry;
use AgentBridge\Auth;
use AgentBridge\Files\Backups;
use AgentBridge\Idempotency;
use AgentBridge\Response;

/**
 * Deploy built front-end apps (React, Vue, plain static) into wp-content/agent-apps/{slug}/
 * and serve the live release at a mount path (see Apps\Server).
 *
 * Releases are immutable directories; "going live" is a single option update, so a deploy is
 * atomic and a rollback is instant. Gated by AGENT_BRIDGE_ALLOW_FILE_WRITE like /files.
 */
class AppsRoute {

    const NAMESPACE   = 'agent/v1';
    const MAX_ARCHIVE = 20 * 1024 * 1024;

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/apps', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_apps' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/apps/deploy', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'deploy' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/apps/(?P<slug>[a-z0-9-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_app' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_app' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/apps/(?P<slug>[a-z0-9-]+)/rollback', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'rollback' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    // ---------------------------------------------------------------- reads

    public static function list_apps(): \WP_REST_Response {
        $out = [];
        foreach ( Registry::all() as $slug => $app ) {
            $out[] = self::describe( $slug, $app );
        }
        return Response::success( $out );
    }

    public static function get_app( \WP_REST_Request $request ): \WP_REST_Response {
        $slug = (string) $request->get_param( 'slug' );
        $app  = Registry::all()[ $slug ] ?? null;
        if ( ! $app ) {
            return Response::error( 'app_not_found', "App '{$slug}' does not exist.", 404 );
        }
        return Response::success( array_merge( self::describe( $slug, $app ), [ 'releases' => Registry::releases( $slug ) ] ) );
    }

    // --------------------------------------------------------------- writes

    public static function deploy( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $gate = self::gate() ) {
            return $gate;
        }
        $data = $request->get_json_params() ?? [];

        $slug = $data['slug'] ?? null;
        if ( ! Registry::valid_slug( $slug ) ) {
            return Response::validation_error( "'slug' must match " . Registry::SLUG_RE . '.' );
        }
        $mount = Registry::normalise_mount( $data['mount'] ?? null );
        if ( $mount === null ) {
            return Response::validation_error( "'mount' must be a path like /app or /apps/dashboard (letters, digits, . _ - only)." );
        }
        if ( Registry::is_reserved( $mount ) ) {
            return Response::error( 'mount_reserved', "'{$mount}' belongs to WordPress and cannot host an app.", 422 );
        }
        $allow_root = filter_var( $data['allow_root'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( $mount === '/' && ! $allow_root ) {
            return Response::error( 'root_mount_requires_allow_root', "Mounting at '/' replaces the site's front end. Pass allow_root=true to confirm.", 422 );
        }
        $owner = Registry::mount_owner( $mount, $slug );
        if ( $owner !== null ) {
            return Response::error( 'mount_in_use', "Mount '{$mount}' is already used by app '{$owner}'.", 409 );
        }

        $b64 = $data['archive_base64'] ?? null;
        if ( ! is_string( $b64 ) || $b64 === '' ) {
            return Response::validation_error( "'archive_base64' (a zip of the build output) is required." );
        }
        $bytes = base64_decode( $b64, true );
        if ( $bytes === false ) {
            return Response::validation_error( "'archive_base64' is not valid base64." );
        }
        if ( strlen( $bytes ) > self::MAX_ARCHIVE ) {
            return Response::error( 'archive_too_large', 'Archive exceeds ' . self::MAX_ARCHIVE . ' bytes.', 413 );
        }

        $apps_dir = Registry::apps_dir();
        if ( is_link( $apps_dir ) ) {
            return Response::error( 'path_not_allowed', 'agent-apps must not be a symlink.', 403 );
        }
        $app_dir = Registry::app_dir( $slug );
        if ( ! wp_mkdir_p( $app_dir . '/releases' ) ) {
            return Response::error( 'file_write_failed', 'Could not create the app directory.', 500 );
        }

        $zip_tmp = tempnam( get_temp_dir(), 'agent-app-' );
        if ( $zip_tmp === false || file_put_contents( $zip_tmp, $bytes ) === false ) {
            return Response::error( 'file_write_failed', 'Could not stage the upload in the temp directory.', 500 );
        }
        $staging = $app_dir . '/.staging-' . bin2hex( random_bytes( 4 ) );
        try {
            $result = Archive::extract( $zip_tmp, $staging, $slug );
        } finally {
            @unlink( $zip_tmp );
        }
        if ( ! $result['ok'] ) {
            Archive::remove_tree( $staging );
            self::tidy_empty( $slug );
            return Response::error( $result['code'], $result['message'], $result['status'] );
        }

        $release = Registry::new_release_id( $slug );
        if ( ! rename( $staging, Registry::release_dir( $slug, $release ) ) ) {
            Archive::remove_tree( $staging );
            return Response::error( 'file_write_failed', 'Could not publish the release.', 500 );
        }

        $apps          = Registry::all();
        $apps[ $slug ] = [
            'mount'   => $mount,
            'spa'     => filter_var( $data['spa'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'current' => $release,
            'updated' => gmdate( 'c' ),
        ];
        Registry::save( $apps );
        Registry::prune( $slug );
        Backups::audit( WP_CONTENT_DIR, 'app_deploy', "agent-apps/{$slug}", [ 'release' => $release, 'mount' => $mount, 'files' => $result['files'] ] );

        return Response::created( array_merge( self::describe( $slug, $apps[ $slug ] ), [
            'release_id' => $release,
            'files'      => $result['files'],
            'bytes'      => $result['bytes'],
        ] ) );
    }

    public static function rollback( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $gate = self::gate() ) {
            return $gate;
        }
        $slug = (string) $request->get_param( 'slug' );
        $apps = Registry::all();
        if ( ! isset( $apps[ $slug ] ) ) {
            return Response::error( 'app_not_found', "App '{$slug}' does not exist.", 404 );
        }

        $data   = $request->get_json_params() ?? [];
        $target = $data['release_id'] ?? null;
        if ( $target === null ) {
            // newest release older than the live one
            foreach ( Registry::releases( $slug ) as $r ) {
                if ( strcmp( $r['id'], $apps[ $slug ]['current'] ) < 0 ) {
                    $target = $r['id'];
                    break;
                }
            }
            if ( $target === null ) {
                return Response::error( 'no_previous_release', 'There is no earlier release to roll back to.', 409 );
            }
        }
        if ( ! Registry::valid_release( $target ) || ! is_dir( Registry::release_dir( $slug, $target ) ) ) {
            return Response::error( 'release_not_found', "Release '{$target}' does not exist.", 404 );
        }

        $apps[ $slug ]['current'] = $target;
        $apps[ $slug ]['updated'] = gmdate( 'c' );
        Registry::save( $apps );
        Backups::audit( WP_CONTENT_DIR, 'app_rollback', "agent-apps/{$slug}", [ 'release' => $target ] );

        return Response::success( array_merge( self::describe( $slug, $apps[ $slug ] ), [ 'release_id' => $target ] ) );
    }

    public static function delete_app( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $gate = self::gate() ) {
            return $gate;
        }
        $slug = (string) $request->get_param( 'slug' );
        $apps = Registry::all();
        if ( ! isset( $apps[ $slug ] ) && ! is_dir( Registry::app_dir( $slug ) ) ) {
            return Response::error( 'app_not_found', "App '{$slug}' does not exist.", 404 );
        }

        // Unroute first so nothing is served while files are being removed.
        unset( $apps[ $slug ] );
        Registry::save( $apps );
        Archive::remove_tree( Registry::app_dir( $slug ) );
        Backups::audit( WP_CONTENT_DIR, 'app_delete', "agent-apps/{$slug}" );

        return Response::success( [ 'slug' => $slug, 'deleted' => true ] );
    }

    // -------------------------------------------------------------- helpers

    private static function gate(): ?\WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_FILE_WRITE' ) && AGENT_BRIDGE_ALLOW_FILE_WRITE ) ) {
            return Response::error( 'file_write_disabled', 'App deploys are disabled. Set AGENT_BRIDGE_ALLOW_FILE_WRITE to true.', 403 );
        }
        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            return Response::error( 'file_edit_disabled', 'DISALLOW_FILE_MODS is set on this site.', 403 );
        }
        return null;
    }

    private static function describe( string $slug, array $app ): array {
        return [
            'slug'    => $slug,
            'mount'   => $app['mount'],
            'url'     => home_url( $app['mount'] === '/' ? '/' : $app['mount'] . '/' ),
            'spa'     => (bool) $app['spa'],
            'current' => $app['current'],
            'updated' => $app['updated'],
            // false if the live release's files were removed out-of-band (the app then falls through to WordPress)
            'release_exists' => is_dir( Registry::release_dir( $slug, $app['current'] ) ),
        ];
    }

    /** Remove an app dir left empty by a failed first deploy. */
    private static function tidy_empty( string $slug ): void {
        if ( isset( Registry::all()[ $slug ] ) ) {
            return;
        }
        $dir = Registry::app_dir( $slug );
        @rmdir( $dir . '/releases' );
        @rmdir( $dir );
    }
}
