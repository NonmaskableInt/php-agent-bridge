<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Files\Backups;
use AgentBridge\Files\PathGuard;
use AgentBridge\Files\PhpGuard;
use AgentBridge\Idempotency;
use AgentBridge\Response;

/**
 * Read / write / delete files under wp-content/{themes,plugins,mu-plugins,agent-apps}.
 *
 * Writes are OFF unless AGENT_BRIDGE_ALLOW_FILE_WRITE is true, and every write is:
 *   path-jailed -> extension-checked -> linted (PHP) -> backed up -> swapped in atomically
 *   -> health-checked (PHP) -> rolled back automatically if WordPress no longer boots.
 *
 * Known limit: between the swap and the health check a PHP file is live for the
 * duration of one WordPress boot (typically <1s). A bad file is removed immediately
 * after, but a request landing in that window can see the fault.
 */
class FilesRoute {

    const NAMESPACE = 'agent/v1';
    const MAX_BYTES = 5 * 1024 * 1024;
    const MAX_READ  = 2 * 1024 * 1024;

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/files', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_files' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_file' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/files/content', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'read_file' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'write_file' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/files/backups', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_backups' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/files/restore', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'restore_file' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    // ---------------------------------------------------------------- reads

    public static function list_files( \WP_REST_Request $request ): \WP_REST_Response {
        $path = (string) $request->get_param( 'path' );

        if ( $path === '' ) {
            return Response::success( [
                'path'    => '',
                'entries' => array_map( fn( $r ) => [ 'name' => $r, 'type' => 'dir' ], PathGuard::ROOTS ),
            ] );
        }

        $p = self::resolve( $path );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }
        if ( ! is_dir( $p['abs'] ) ) {
            return Response::error( 'file_not_found', "Directory '{$p['rel']}' does not exist.", 404 );
        }

        $entries = [];
        foreach ( scandir( $p['abs'] ) ?: [] as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $full      = $p['abs'] . '/' . $name;
            $entries[] = [
                'name'     => $name,
                'type'     => is_link( $full ) ? 'symlink' : ( is_dir( $full ) ? 'dir' : 'file' ),
                'size'     => is_file( $full ) ? (int) filesize( $full ) : null,
                'modified' => gmdate( 'c', (int) filemtime( $full ) ),
            ];
        }

        return Response::success( [ 'path' => $p['rel'], 'entries' => $entries ] );
    }

    public static function read_file( \WP_REST_Request $request ): \WP_REST_Response {
        $p = self::resolve( $request->get_param( 'path' ) );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }
        if ( ! is_file( $p['abs'] ) ) {
            return Response::error( 'file_not_found', "File '{$p['rel']}' does not exist.", 404 );
        }
        $size = (int) filesize( $p['abs'] );
        if ( $size > self::MAX_READ ) {
            return Response::error( 'file_too_large', "File is {$size} bytes; read limit is " . self::MAX_READ . '.', 413 );
        }

        $raw    = (string) file_get_contents( $p['abs'] );
        $binary = ! mb_check_encoding( $raw, 'UTF-8' ) || strpos( $raw, "\0" ) !== false;

        return Response::success( [
            'path'     => $p['rel'],
            'encoding' => $binary ? 'base64' : 'utf8',
            'content'  => $binary ? base64_encode( $raw ) : $raw,
            'size'     => $size,
            'sha256'   => hash( 'sha256', $raw ),
        ] );
    }

    public static function list_backups( \WP_REST_Request $request ): \WP_REST_Response {
        $p = self::resolve( $request->get_param( 'path' ) );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }
        return Response::success( [ 'path' => $p['rel'], 'backups' => Backups::list( WP_CONTENT_DIR, $p['rel'] ) ] );
    }

    // --------------------------------------------------------------- writes

    public static function write_file( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];
        $p    = self::resolve_writable( $data['path'] ?? null );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }

        $encoding = $data['encoding'] ?? 'utf8';
        $content  = $data['content'] ?? null;
        if ( ! is_string( $content ) ) {
            return Response::validation_error( "'content' (string) is required." );
        }
        if ( $encoding === 'base64' ) {
            $bytes = base64_decode( $content, true );
            if ( $bytes === false ) {
                return Response::validation_error( "'content' is not valid base64." );
            }
        } elseif ( $encoding === 'utf8' ) {
            $bytes = $content;
        } else {
            return Response::validation_error( "'encoding' must be 'utf8' or 'base64'." );
        }
        if ( strlen( $bytes ) > self::MAX_BYTES ) {
            return Response::error( 'file_too_large', 'Content exceeds ' . self::MAX_BYTES . ' bytes.', 413 );
        }

        if ( isset( $data['expected_sha256'] ) && is_file( $p['abs'] )
            && ! hash_equals( (string) $data['expected_sha256'], hash_file( 'sha256', $p['abs'] ) ) ) {
            return Response::error( 'conflict', 'File changed since it was read (sha256 mismatch).', 409 );
        }

        return self::commit( $p, $bytes, 'write' );
    }

    public static function restore_file( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];
        $p    = self::resolve_writable( $data['path'] ?? null );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }

        $id = $data['backup_id'] ?? null;
        if ( $id === null ) {
            $list = Backups::list( WP_CONTENT_DIR, $p['rel'] );
            if ( empty( $list ) ) {
                return Response::error( 'backup_not_found', "No backups exist for '{$p['rel']}'.", 404 );
            }
            $id = $list[0]['id'];
        }
        $file = Backups::path_for( WP_CONTENT_DIR, $p['rel'], (string) $id );
        if ( $file === null ) {
            return Response::error( 'backup_not_found', "Backup '{$id}' not found.", 404 );
        }

        return self::commit( $p, (string) file_get_contents( $file ), 'restore', [ 'restored_from' => $id ] );
    }

    public static function delete_file( \WP_REST_Request $request ): \WP_REST_Response {
        $p = self::resolve_writable( $request->get_param( 'path' ), false );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }
        if ( ! file_exists( $p['abs'] ) ) {
            return Response::error( 'file_not_found', "'{$p['rel']}' does not exist.", 404 );
        }

        if ( is_dir( $p['abs'] ) ) {
            $recursive = filter_var( $request->get_param( 'recursive' ), FILTER_VALIDATE_BOOLEAN );
            if ( ! $recursive ) {
                return Response::validation_error( "'{$p['rel']}' is a directory; pass recursive=true to delete it." );
            }
            if ( strpos( $p['rel'], '/' ) === false ) {
                return Response::error( 'path_not_allowed', 'Root directories cannot be deleted.', 403 );
            }
            if ( self::is_active_code_dir( $p['rel'] ) ) {
                return Response::error( 'path_in_use', "'{$p['rel']}' is the active theme or an active plugin.", 409 );
            }
            self::remove_tree( $p['abs'] );
            Backups::audit( WP_CONTENT_DIR, 'delete_dir', $p['rel'] );
            return Response::success( [ 'path' => $p['rel'], 'deleted' => true ] );
        }

        $backup = Backups::snapshot( WP_CONTENT_DIR, $p['rel'], $p['abs'] );
        unlink( $p['abs'] );
        Backups::audit( WP_CONTENT_DIR, 'delete', $p['rel'], [ 'backup' => $backup ] );

        return Response::success( [ 'path' => $p['rel'], 'deleted' => true, 'backup_id' => $backup ] );
    }

    // ------------------------------------------------------------- pipeline

    /** Lint -> backup -> atomic swap -> health check -> rollback on failure. */
    private static function commit( array $p, string $bytes, string $action, array $extra = [] ): \WP_REST_Response {
        $is_php = strtolower( pathinfo( $p['abs'], PATHINFO_EXTENSION ) ) === 'php';
        $dir    = dirname( $p['abs'] );
        if ( ! wp_mkdir_p( $dir ) ) {
            return Response::error( 'file_write_failed', "Could not create directory for '{$p['rel']}'.", 500 );
        }

        $tmp = $dir . '/.agent-tmp-' . bin2hex( random_bytes( 4 ) ) . ( $is_php ? '.php' : '.tmp' );
        if ( file_put_contents( $tmp, $bytes ) === false ) {
            return Response::error( 'file_write_failed', "Could not write '{$p['rel']}'.", 500 );
        }

        if ( $is_php ) {
            $lint = PhpGuard::lint( $tmp );
            if ( ! $lint['ok'] ) {
                unlink( $tmp );
                if ( ! empty( $lint['unavailable'] ) ) {
                    return Response::error( 'verification_unavailable', $lint['output'], 503 );
                }
                $msg = str_replace( $tmp, $p['rel'], $lint['output'] );
                return Response::error( 'php_lint_failed', $msg, 422 );
            }
        }

        $existed = is_file( $p['abs'] );
        $backup  = Backups::snapshot( WP_CONTENT_DIR, $p['rel'], $p['abs'] );

        if ( ! rename( $tmp, $p['abs'] ) ) {
            @unlink( $tmp );
            return Response::error( 'file_write_failed', "Could not move '{$p['rel']}' into place.", 500 );
        }
        clearstatcache( true, $p['abs'] );
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $p['abs'], true );
        }

        if ( $is_php ) {
            $health = PhpGuard::health_check();
            if ( ! $health['ok'] ) {
                self::rollback( $p, $existed, $backup );
                Backups::audit( WP_CONTENT_DIR, $action . '_rolled_back', $p['rel'], [ 'reason' => substr( $health['output'], 0, 300 ) ] );
                return Response::error(
                    'health_check_failed',
                    'WordPress failed to boot with this change; it was rolled back. Detail: ' . $health['output'],
                    422
                );
            }
        }

        $sha = hash( 'sha256', $bytes );
        Backups::audit( WP_CONTENT_DIR, $action, $p['rel'], [ 'sha256' => $sha, 'bytes' => strlen( $bytes ), 'backup' => $backup ] );

        return Response::success( array_merge( [
            'path'           => $p['rel'],
            'bytes'          => strlen( $bytes ),
            'sha256'         => $sha,
            'created'        => ! $existed,
            'backup_id'      => $backup,
            'linted'         => $is_php,
            'health_checked' => $is_php,
        ], $extra ) );
    }

    private static function rollback( array $p, bool $existed, ?string $backup ): void {
        if ( $existed && $backup !== null ) {
            $src = Backups::path_for( WP_CONTENT_DIR, $p['rel'], $backup );
            if ( $src !== null ) {
                copy( $src, $p['abs'] );
            }
        } else {
            @unlink( $p['abs'] );
        }
        clearstatcache( true, $p['abs'] );
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $p['abs'], true );
        }
    }

    // -------------------------------------------------------------- helpers

    /** @return array|\WP_REST_Response */
    private static function resolve( $path ) {
        $p = PathGuard::resolve( $path, WP_CONTENT_DIR );
        if ( ! $p['ok'] ) {
            return Response::error( 'path_not_allowed', $p['reason'], 403 );
        }
        return $p;
    }

    /** Resolve + all write-side gates. @return array|\WP_REST_Response */
    private static function resolve_writable( $path, bool $check_extension = true ) {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_FILE_WRITE' ) && AGENT_BRIDGE_ALLOW_FILE_WRITE ) ) {
            return Response::error( 'file_write_disabled', 'File writes are disabled. Set AGENT_BRIDGE_ALLOW_FILE_WRITE to true.', 403 );
        }
        if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT && ! self::is_apps_path( $path ) ) ) {
            return Response::error( 'file_edit_disabled', 'DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS is set on this site.', 403 );
        }

        $p = self::resolve( $path );
        if ( $p instanceof \WP_REST_Response ) {
            return $p;
        }

        $cap = in_array( $p['root'], [ 'plugins', 'mu-plugins' ], true ) ? 'edit_plugins' : ( $p['root'] === 'themes' ? 'edit_themes' : 'manage_options' );
        if ( ! current_user_can( $cap ) ) {
            return Response::error( 'insufficient_capability', "Writing under '{$p['root']}' requires the {$cap} capability.", 403 );
        }

        if ( $check_extension ) {
            $deny = PathGuard::write_denial( $p['rel'] );
            if ( $deny !== null ) {
                return Response::error( 'path_not_allowed', $deny, 403 );
            }
        }
        return $p;
    }

    private static function is_apps_path( $path ): bool {
        return is_string( $path ) && strpos( $path, 'agent-apps/' ) === 0;
    }

    private static function is_active_code_dir( string $rel ): bool {
        $parts = explode( '/', $rel );
        if ( $parts[0] === 'themes' && count( $parts ) === 2 ) {
            return in_array( $parts[1], [ get_stylesheet(), get_template() ], true );
        }
        if ( $parts[0] === 'plugins' && count( $parts ) === 2 ) {
            foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
                if ( strpos( $plugin, $parts[1] . '/' ) === 0 ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function remove_tree( string $dir ): void {
        foreach ( scandir( $dir ) ?: [] as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $full = $dir . '/' . $name;
            if ( is_dir( $full ) && ! is_link( $full ) ) {
                self::remove_tree( $full );
            } else {
                unlink( $full );
            }
        }
        rmdir( $dir );
    }
}
