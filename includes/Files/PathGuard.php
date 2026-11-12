<?php

namespace AgentBridge\Files;

/**
 * Jails every file operation to a fixed set of roots under wp-content.
 * Pure filesystem logic — no WordPress calls — so it can be unit tested.
 */
class PathGuard {

    /** Roots (relative to wp-content) the bridge may touch. */
    const ROOTS = [ 'themes', 'plugins', 'mu-plugins', 'agent-apps' ];

    /** Never writable, wherever they appear. Config / access-control files. */
    const DENIED_BASENAMES = [ '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'wp-config.php' ];

    /** The bridge must not be able to edit its own guardrails. */
    const PROTECTED_PREFIXES = [ 'plugins/php-agent-bridge', 'mu-plugins/agent-bridge' ];

    /** Extensions an agent may write. Binary types need base64 encoding. */
    const WRITABLE_EXTENSIONS = [
        'php', 'js', 'mjs', 'css', 'json', 'html', 'htm', 'txt', 'md', 'xml', 'svg', 'map', 'twig',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'webmanifest',
    ];

    /**
     * @return array{ok:true,rel:string,abs:string,root:string}|array{ok:false,reason:string}
     */
    public static function resolve( $rel, string $content_dir ): array {
        if ( ! is_string( $rel ) || $rel === '' ) {
            return self::deny( 'Path is required.' );
        }
        if ( strpos( $rel, "\0" ) !== false || strpos( $rel, '\\' ) !== false ) {
            return self::deny( 'Path contains forbidden characters.' );
        }
        if ( $rel[0] === '/' || preg_match( '#^[A-Za-z]:#', $rel ) ) {
            return self::deny( 'Path must be relative to wp-content.' );
        }

        $rel      = rtrim( $rel, '/' );
        $segments = explode( '/', $rel );
        foreach ( $segments as $seg ) {
            if ( $seg === '' || $seg === '.' || $seg === '..' ) {
                return self::deny( 'Path contains empty or traversal segments.' );
            }
        }

        $root = $segments[0];
        if ( ! in_array( $root, self::ROOTS, true ) ) {
            return self::deny( "'{$root}' is not an allowed root. Allowed: " . implode( ', ', self::ROOTS ) . '.' );
        }

        foreach ( self::PROTECTED_PREFIXES as $prefix ) {
            if ( $rel === $prefix || strpos( $rel, $prefix . '/' ) === 0 ) {
                return self::deny( 'This path holds the bridge itself and is protected.' );
            }
        }

        $content_real = realpath( $content_dir );
        if ( $content_real === false ) {
            return self::deny( 'wp-content directory not found.' );
        }
        $abs = $content_real . '/' . $rel;

        // No symlinks anywhere along the path, and the nearest existing ancestor
        // must really live inside the root.
        $probe = $content_real . '/' . $root;
        if ( is_link( $probe ) ) {
            return self::deny( 'Symlinked roots are not allowed.' );
        }
        $nearest = $probe;
        for ( $i = 1; $i < count( $segments ); $i++ ) {
            $next = $nearest . '/' . $segments[ $i ];
            if ( is_link( $next ) ) {
                return self::deny( 'Symlinks are not allowed in paths.' );
            }
            if ( ! file_exists( $next ) ) {
                break;
            }
            $nearest = $next;
        }
        $root_real = realpath( $content_real . '/' . $root );
        if ( $root_real === false ) {
            // Root not created yet (e.g. a fresh site has no mu-plugins/). Nothing to
            // escape from; it will be created inside wp-content on first write.
            return [ 'ok' => true, 'rel' => $rel, 'abs' => $abs, 'root' => $root ];
        }
        $nearest_real = realpath( $nearest );
        if ( $nearest_real === false
            || ( $nearest_real !== $root_real && strpos( $nearest_real, $root_real . '/' ) !== 0 ) ) {
            return self::deny( 'Path escapes its root.' );
        }

        return [ 'ok' => true, 'rel' => $rel, 'abs' => $abs, 'root' => $root ];
    }

    /** @return string|null Reason the file may not be WRITTEN, or null if it may. */
    public static function write_denial( string $rel ): ?string {
        $base = strtolower( basename( $rel ) );
        if ( in_array( $base, self::DENIED_BASENAMES, true ) ) {
            return "'{$base}' can never be written through the bridge.";
        }
        $ext = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::WRITABLE_EXTENSIONS, true ) ) {
            return "Extension '.{$ext}' is not writable. Allowed: " . implode( ', ', self::WRITABLE_EXTENSIONS ) . '.';
        }
        if ( strpos( $rel, 'agent-apps/' ) === 0 && $ext === 'php' ) {
            return 'PHP is not allowed inside agent-apps (static app bundles only).';
        }
        return null;
    }

    private static function deny( string $reason ): array {
        return [ 'ok' => false, 'reason' => $reason ];
    }
}
