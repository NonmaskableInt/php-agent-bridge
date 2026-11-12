<?php

namespace AgentBridge\Apps;

/** Which apps exist, where they are mounted and which release is live. Stored in one option. */
class Registry {

    const OPTION   = 'agent_bridge_apps';
    const KEEP     = 5;
    const SLUG_RE  = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    /** First path segments WordPress owns; an app can never be mounted on them. */
    const RESERVED = [
        'wp-admin', 'wp-login.php', 'wp-json', 'wp-content', 'wp-includes', 'wp-cron.php', 'xmlrpc.php',
        'wp-signup.php', 'wp-activate.php', 'wp-comments-post.php', 'wp-links-opml.php', 'wp-mail.php',
        'wp-trackback.php', 'index.php', 'wp-load.php', 'wp-settings.php', 'wp-config.php', 'wp-blog-header.php',
    ];

    /** @return array<string,array{mount:string,spa:bool,current:string,updated:string}> */
    public static function all(): array {
        $apps = get_option( self::OPTION, [] );
        return is_array( $apps ) ? $apps : [];
    }

    public static function save( array $apps ): void {
        update_option( self::OPTION, $apps, true );
    }

    public static function apps_dir(): string {
        return WP_CONTENT_DIR . '/agent-apps';
    }

    public static function app_dir( string $slug ): string {
        return self::apps_dir() . '/' . $slug;
    }

    public static function release_dir( string $slug, string $release ): string {
        return self::app_dir( $slug ) . '/releases/' . $release;
    }

    public static function valid_slug( $slug ): bool {
        return is_string( $slug ) && preg_match( self::SLUG_RE, $slug ) === 1;
    }

    public static function valid_release( $id ): bool {
        return is_string( $id ) && preg_match( '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id ) === 1;
    }

    /** @return string|null normalised mount ('/' or '/a/b'), null if malformed */
    public static function normalise_mount( $mount ): ?string {
        if ( ! is_string( $mount ) || $mount === '' || $mount[0] !== '/' ) {
            return null;
        }
        $mount = '/' . trim( $mount, '/' );
        if ( $mount === '/' ) {
            return '/';
        }
        foreach ( explode( '/', substr( $mount, 1 ) ) as $seg ) {
            if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $seg ) || $seg === '.' || $seg === '..' ) {
                return null;
            }
        }
        return $mount;
    }

    public static function is_reserved( string $mount ): bool {
        if ( $mount === '/' ) {
            return false;
        }
        $first = strtolower( explode( '/', substr( $mount, 1 ) )[0] );
        return in_array( $first, self::RESERVED, true );
    }

    /** Slug of another app already on this mount, or null. */
    public static function mount_owner( string $mount, string $except_slug ): ?string {
        foreach ( self::all() as $slug => $app ) {
            if ( $slug !== $except_slug && $app['mount'] === $mount ) {
                return $slug;
            }
        }
        return null;
    }

    /** @return list<array{id:string,created:string,current:bool}> newest first */
    public static function releases( string $slug ): array {
        $current = self::all()[ $slug ]['current'] ?? null;
        $out     = [];
        foreach ( glob( self::app_dir( $slug ) . '/releases/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
            $id = basename( $dir );
            if ( self::valid_release( $id ) ) {
                $out[] = [ 'id' => $id, 'created' => gmdate( 'c', (int) filemtime( $dir ) ), 'current' => $id === $current ];
            }
        }
        usort( $out, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );
        return $out;
    }

    /**
     * Release ids sort chronologically as plain strings: 'Ymd-His-' + 5 hex digits of microseconds
     * + 1 random hex digit. Guaranteed to sort after every existing release of the app, even if two
     * deploys land in the same microsecond or the clock moved backwards.
     */
    public static function new_release_id( string $slug ): string {
        $now = microtime( true );
        $id  = gmdate( 'Ymd-His', (int) $now ) . '-' . sprintf( '%05x%x', (int) ( ( $now - floor( $now ) ) * 1000000 ), random_int( 0, 15 ) );

        $existing = self::releases( $slug );
        if ( $existing && strcmp( $id, $existing[0]['id'] ) <= 0 ) {
            $newest = \DateTimeImmutable::createFromFormat( 'Ymd-His', substr( $existing[0]['id'], 0, 15 ), new \DateTimeZone( 'UTC' ) );
            $id     = $newest->modify( '+1 second' )->format( 'Ymd-His' ) . '-000000';
        }
        return $id;
    }

    /** Remove old releases beyond KEEP, never the live one. */
    public static function prune( string $slug ): void {
        $current = self::all()[ $slug ]['current'] ?? null;
        $kept    = 0;
        foreach ( self::releases( $slug ) as $r ) {
            if ( $r['id'] === $current || $kept < self::KEEP ) {
                $kept++;
                continue;
            }
            Archive::remove_tree( self::release_dir( $slug, $r['id'] ) );
        }
    }
}
