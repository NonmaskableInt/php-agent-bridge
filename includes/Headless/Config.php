<?php

namespace AgentBridge\Headless;

/**
 * Headless-mode settings (stored in one option) with strict, all-or-nothing validation.
 */
class Config {

    const OPTION      = 'agent_bridge_headless';
    const MAX_ORIGINS = 20;

    const DEFAULTS = [
        'frontend_url'      => null,
        'cors_origins'      => [],
        'redirect_frontend' => false,
        'redirect_status'   => 302,
        'preview'           => true,
    ];

    public static function get(): array {
        $stored = get_option( self::OPTION, [] );
        return array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : [] );
    }

    /**
     * Apply a partial update. Nothing is saved unless the whole resulting config is valid.
     *
     * @return array{ok:true,config:array}|array{ok:false,code:string,message:string}
     */
    public static function update( array $patch ): array {
        if ( ! $patch ) {
            return self::fail( 'validation_error', 'Provide at least one setting: ' . implode( ', ', array_keys( self::DEFAULTS ) ) . '.' );
        }
        $unknown = array_diff( array_keys( $patch ), array_keys( self::DEFAULTS ) );
        if ( $unknown ) {
            return self::fail( 'validation_error', 'Unknown setting(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', array_keys( self::DEFAULTS ) ) . '.' );
        }

        $next = self::get();

        if ( array_key_exists( 'frontend_url', $patch ) ) {
            if ( $patch['frontend_url'] === null ) {
                $next['frontend_url'] = null;
            } else {
                $url = self::normalise_frontend_url( $patch['frontend_url'] );
                if ( $url === null ) {
                    return self::fail( 'validation_error', "'frontend_url' must be an http(s) URL like https://app.example.com or https://example.com/app, with no credentials, query string or fragment." );
                }
                $next['frontend_url'] = $url;
            }
        }

        if ( array_key_exists( 'cors_origins', $patch ) ) {
            $list = $patch['cors_origins'];
            if ( ! is_array( $list ) || array_values( $list ) !== $list ) {
                return self::fail( 'validation_error', "'cors_origins' must be a list of origins." );
            }
            if ( count( $list ) > self::MAX_ORIGINS ) {
                return self::fail( 'validation_error', "'cors_origins' may hold at most " . self::MAX_ORIGINS . ' origins.' );
            }
            $clean = [];
            foreach ( $list as $origin ) {
                $norm = self::normalise_origin( $origin );
                if ( $norm === null ) {
                    $shown = is_string( $origin ) ? $origin : gettype( $origin );
                    return self::fail( 'validation_error', "Invalid CORS origin '{$shown}'. Use exact origins like https://app.example.com (no wildcard, path, query or credentials)." );
                }
                $clean[ $norm ] = true;
            }
            $next['cors_origins'] = array_keys( $clean );
        }

        foreach ( [ 'redirect_frontend', 'preview' ] as $flag ) {
            if ( array_key_exists( $flag, $patch ) ) {
                if ( ! is_bool( $patch[ $flag ] ) ) {
                    return self::fail( 'validation_error', "'{$flag}' must be true or false." );
                }
                $next[ $flag ] = $patch[ $flag ];
            }
        }

        if ( array_key_exists( 'redirect_status', $patch ) ) {
            if ( ! in_array( $patch['redirect_status'], [ 301, 302 ], true ) ) {
                return self::fail( 'validation_error', "'redirect_status' must be 301 or 302." );
            }
            $next['redirect_status'] = $patch['redirect_status'];
        }

        // Cross-field rules on the final state.
        if ( $next['frontend_url'] !== null && self::is_site_root( $next['frontend_url'] ) ) {
            return self::fail( 'frontend_same_origin', "'frontend_url' is this site's own root. Mount an app at '/' instead of pointing the site at itself." );
        }
        if ( $next['redirect_frontend'] ) {
            if ( $next['frontend_url'] === null ) {
                return self::fail( 'frontend_url_required', "Set 'frontend_url' before enabling redirect_frontend (and do not clear it while the redirect is on)." );
            }
            if ( self::same_origin( $next['frontend_url'] ) ) {
                return self::fail( 'frontend_same_origin', 'Redirecting to a path on this same origin can loop. Use a different origin, or mount an app instead.' );
            }
        }

        update_option( self::OPTION, $next, true );
        return [ 'ok' => true, 'config' => $next ];
    }

    /** @return string|null scheme://host[:port], lowercase; null if not a bare http(s) origin */
    public static function normalise_origin( $value ): ?string {
        $u = self::parse( $value );
        if ( $u === null || isset( $u['query'] ) || isset( $u['fragment'] ) ) {
            return null;
        }
        if ( isset( $u['path'] ) && $u['path'] !== '' && $u['path'] !== '/' ) {
            return null;
        }
        return self::origin_of( $u );
    }

    /** @return string|null origin + optional path, no trailing slash */
    public static function normalise_frontend_url( $value ): ?string {
        $u = self::parse( $value );
        if ( $u === null || isset( $u['query'] ) || isset( $u['fragment'] ) ) {
            return null;
        }
        $path = rtrim( (string) ( $u['path'] ?? '' ), '/' );
        if ( $path !== '' && ! preg_match( '#^(/[A-Za-z0-9._~%-]+)+$#', $path ) ) {
            return null;
        }
        return self::origin_of( $u ) . $path;
    }

    /** Origin (scheme://host[:port]) of any http(s) URL, ignoring its path. '' if unparseable. */
    private static function origin_ignoring_path( string $url ): string {
        $u = self::parse( $url );
        return $u === null ? '' : self::origin_of( $u );
    }

    public static function site_origin(): string {
        return self::origin_ignoring_path( home_url() );
    }

    public static function same_origin( string $url ): bool {
        $origin = self::origin_ignoring_path( $url );
        return $origin !== '' && $origin === self::site_origin();
    }

    /** True when $url is the origin root with no path. */
    private static function is_site_root( string $url ): bool {
        return self::same_origin( $url ) && ( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === '';
    }

    private static function parse( $value ): ?array {
        if ( ! is_string( $value ) || $value === '' || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
            return null;
        }
        $u = wp_parse_url( $value );
        if ( ! is_array( $u ) || ! isset( $u['scheme'], $u['host'] ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
            return null;
        }
        if ( ! in_array( strtolower( $u['scheme'] ), [ 'http', 'https' ], true ) ) {
            return null;
        }
        if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $u['host'] ) ) {
            return null;
        }
        return $u;
    }

    private static function origin_of( array $u ): string {
        return strtolower( $u['scheme'] ) . '://' . strtolower( $u['host'] ) . ( isset( $u['port'] ) ? ':' . (int) $u['port'] : '' );
    }

    private static function fail( string $code, string $message ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message ];
    }
}
