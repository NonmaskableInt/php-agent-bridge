<?php

namespace AgentBridge\Files;

/** Versioned copies of files before the bridge changes them, plus an audit log. */
class Backups {

    const KEEP           = 20;
    const DIRNAME_OPTION = 'agent_bridge_backup_dirname';

    /**
     * Where backups and the audit log live. They hold full source code, so the location matters:
     *  1. AGENT_BRIDGE_BACKUP_DIR if set — point it OUTSIDE the web root (recommended).
     *  2. Otherwise wp-content/agent-bridge-<128-bit random>, created once and remembered in an
     *     option. The name is unguessable, deny rules are written for Apache and IIS, and an
     *     index.php stops directory listings. This protects Apache, nginx and IIS alike, because
     *     it does not rely on the web server honouring an .htaccess file.
     * A pre-existing wp-content/agent-bridge-backups (older versions) is migrated, so nothing is
     * left at the guessable path.
     */
    public static function dir( string $content_dir ): string {
        static $resolved = [];
        $content_dir = rtrim( $content_dir, '/' );
        $key         = defined( 'AGENT_BRIDGE_BACKUP_DIR' ) ? 'const:' . AGENT_BRIDGE_BACKUP_DIR : $content_dir;
        if ( isset( $resolved[ $key ] ) && is_dir( $resolved[ $key ] ) ) {
            return $resolved[ $key ];
        }

        if ( defined( 'AGENT_BRIDGE_BACKUP_DIR' ) && is_string( AGENT_BRIDGE_BACKUP_DIR ) && AGENT_BRIDGE_BACKUP_DIR !== '' ) {
            $dir = rtrim( AGENT_BRIDGE_BACKUP_DIR, '/' );
        } else {
            // add_option is atomic: concurrent first requests all end up with the same name.
            add_option( self::DIRNAME_OPTION, 'agent-bridge-' . bin2hex( random_bytes( 16 ) ), '', false );
            $name = (string) get_option( self::DIRNAME_OPTION );
            if ( ! preg_match( '/^agent-bridge-[0-9a-f]{32}$/', $name ) ) {
                $name = 'agent-bridge-' . bin2hex( random_bytes( 16 ) );
                update_option( self::DIRNAME_OPTION, $name, false );
            }
            $dir    = $content_dir . '/' . $name;
            $legacy = $content_dir . '/agent-bridge-backups';
            if ( is_dir( $legacy ) && ! is_link( $legacy ) && ! is_dir( $dir ) ) {
                @rename( $legacy, $dir );
            }
        }

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Defence in depth, written whenever missing (also covers a relocated directory).
        $guards = [
            '.htaccess'  => "Require all denied\nDeny from all\n",
            'index.php'  => "<?php // Silence is golden.\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' . "\n",
        ];
        foreach ( $guards as $file => $body ) {
            if ( ! file_exists( $dir . '/' . $file ) ) {
                file_put_contents( $dir . '/' . $file, $body );
            }
        }

        // Earlier versions logged requests to a fixed, web-served wp-content/agent-bridge.log.
        // Move any such file into the protected directory so it is no longer reachable.
        $legacy_log = $content_dir . '/agent-bridge.log';
        if ( is_file( $legacy_log ) && ! is_link( $legacy_log ) ) {
            if ( file_put_contents( $dir . '/requests.log', (string) file_get_contents( $legacy_log ), FILE_APPEND | LOCK_EX ) !== false ) {
                @unlink( $legacy_log );
            }
        }
        return $resolved[ $key ] = $dir;
    }

    private static function bucket( string $content_dir, string $rel ): string {
        return self::dir( $content_dir ) . '/' . sha1( $rel );
    }

    /** Copies the current file aside. Returns the backup id, or null if there was nothing to back up. */
    public static function snapshot( string $content_dir, string $rel, string $abs ): ?string {
        if ( ! is_file( $abs ) ) {
            return null;
        }
        $bucket = self::bucket( $content_dir, $rel );
        wp_mkdir_p( $bucket );
        file_put_contents( $bucket . '/path.txt', $rel );
        $id = gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
        if ( ! copy( $abs, $bucket . '/' . $id . '.bak' ) ) {
            return null;
        }
        self::prune( $bucket );
        return $id;
    }

    /** @return list<array{id:string,size:int,created:string}> newest first */
    public static function list( string $content_dir, string $rel ): array {
        $bucket = self::bucket( $content_dir, $rel );
        $out    = [];
        foreach ( glob( $bucket . '/*.bak' ) ?: [] as $file ) {
            $out[] = [
                'id'      => basename( $file, '.bak' ),
                'size'    => (int) filesize( $file ),
                'created' => gmdate( 'c', (int) filemtime( $file ) ),
                'preview' => substr( (string) file_get_contents( $file, false, null, 0, 200 ), 0, 200 ),
            ];
        }
        usort( $out, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );
        return $out;
    }

    public static function path_for( string $content_dir, string $rel, string $id ): ?string {
        if ( ! preg_match( '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id ) ) {
            return null;
        }
        $file = self::bucket( $content_dir, $rel ) . '/' . $id . '.bak';
        return is_file( $file ) ? $file : null;
    }

    private static function prune( string $bucket ): void {
        $files = glob( $bucket . '/*.bak' ) ?: [];
        rsort( $files );
        foreach ( array_slice( $files, self::KEEP ) as $old ) {
            @unlink( $old );
        }
    }

    public static function audit( string $content_dir, string $action, string $rel, array $extra = [] ): void {
        $line = wp_json_encode( array_merge( [
            'ts'     => gmdate( 'c' ),
            'user'   => get_current_user_id(),
            'action' => $action,
            'path'   => $rel,
        ], $extra ) ) . "\n";
        file_put_contents( self::dir( $content_dir ) . '/audit.log', $line, FILE_APPEND | LOCK_EX );
    }
}
