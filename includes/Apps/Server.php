<?php

namespace AgentBridge\Apps;

/**
 * Serves the live release of each registered app at its mount path.
 * Static files only — never executes anything. Runs on template_redirect (priority 0),
 * so wp-admin, wp-json, wp-login.php and other WordPress entry points are never intercepted.
 */
class Server {

    const MIME = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8', 'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'twig' => 'text/plain; charset=utf-8',
    ];

    public static function maybe_serve(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ( $method !== 'GET' && $method !== 'HEAD' ) {
            return;
        }
        $apps = Registry::all();
        if ( ! $apps ) {
            return;
        }

        $path = self::request_path();
        if ( $path === null ) {
            return;
        }

        // Longest mount wins.
        uasort( $apps, fn( $a, $b ) => strlen( $b['mount'] ) <=> strlen( $a['mount'] ) );
        foreach ( $apps as $slug => $app ) {
            $rel = self::relative_to_mount( $path, $app['mount'] );
            if ( $rel === null ) {
                continue;
            }
            self::serve( $slug, $app, $rel, $method === 'HEAD' );
            return;
        }
    }

    /** Path of the request relative to the site root, decoded; null if unsafe. */
    private static function request_path(): ?string {
        $uri  = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
        $home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home !== '' && strpos( $uri, $home ) === 0 ) {
            $uri = substr( $uri, strlen( $home ) );
        }
        $uri = rawurldecode( $uri === '' ? '/' : $uri );
        if ( strpos( $uri, "\0" ) !== false || strpos( $uri, '\\' ) !== false ) {
            return null;
        }
        return $uri[0] === '/' ? $uri : '/' . $uri;
    }

    private static function relative_to_mount( string $path, string $mount ): ?string {
        if ( $mount === '/' ) {
            $first = strtolower( explode( '/', ltrim( $path, '/' ) )[0] );
            return in_array( $first, Registry::RESERVED, true ) ? null : ltrim( $path, '/' );
        }
        if ( $path === $mount ) {
            return '';
        }
        if ( strpos( $path, $mount . '/' ) === 0 ) {
            return substr( $path, strlen( $mount ) + 1 );
        }
        return null;
    }

    private static function serve( string $slug, array $app, string $rel, bool $head ): void {
        $root_mount = $app['mount'] === '/';
        $base       = realpath( Registry::release_dir( $slug, $app['current'] ) );
        if ( $base === false ) {
            return; // release missing: let WordPress answer
        }

        $rel      = trim( $rel, '/' );
        $segments = $rel === '' ? [] : explode( '/', $rel );
        foreach ( $segments as $seg ) {
            if ( $seg === '..' || $seg === '.' || $seg === '' || $seg[0] === '.' ) {
                self::not_found( $root_mount );
                return;
            }
        }

        $file = null;
        if ( $rel !== '' ) {
            $candidate = realpath( $base . '/' . $rel );
            if ( $candidate !== false && is_file( $candidate ) && strpos( $candidate, $base . '/' ) === 0 ) {
                $file = $candidate;
            }
        }
        if ( $file === null ) {
            $last = $segments ? end( $segments ) : '';
            $has_ext = strpos( $last, '.' ) !== false;
            if ( ! empty( $app['spa'] ) && ! $has_ext ) {
                $file = $base . '/index.html';
            } elseif ( $rel === '' ) {
                $file = $base . '/index.html';
            } else {
                self::not_found( $root_mount );
                return;
            }
        }
        if ( ! is_file( $file ) ) {
            self::not_found( $root_mount );
            return;
        }

        $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $mime = self::MIME[ $ext ] ?? null;
        if ( $mime === null ) {
            self::not_found( $root_mount );
            return;
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        status_header( 200 );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $file ) );
        header( 'X-Content-Type-Options: nosniff' );
        if ( $ext === 'svg' ) {
            header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
        }
        $is_html = $ext === 'html' || $ext === 'htm';
        header( 'Cache-Control: ' . ( $is_html ? 'no-cache' : ( strpos( $rel, 'assets/' ) === 0 ? 'public, max-age=31536000, immutable' : 'public, max-age=3600' ) ) );
        if ( ! $head ) {
            readfile( $file );
        }
        exit;
    }

    /** Sub-path mounts own their 404; a root mount defers unknown files to WordPress. */
    private static function not_found( bool $root_mount ): void {
        if ( $root_mount ) {
            return;
        }
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        status_header( 404 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'Not found';
        exit;
    }
}
