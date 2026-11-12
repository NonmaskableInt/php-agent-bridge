<?php

namespace AgentBridge\Files;

/** Versioned copies of files before the bridge changes them, plus an audit log. */
class Backups {

    const KEEP = 20;

    public static function dir( string $content_dir ): string {
        $dir = rtrim( $content_dir, '/' ) . '/agent-bridge-backups';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Backups hold source code: never web-served.
            file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
            file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
        }
        return $dir;
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
