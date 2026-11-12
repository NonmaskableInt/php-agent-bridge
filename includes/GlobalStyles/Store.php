<?php

namespace AgentBridge\GlobalStyles;

use AgentBridge\Files\Backups;
use AgentBridge\Themes\Validator;

/**
 * The Site Editor's user-level theme.json layer (the "Styles" panel), stored in the database as a
 * wp_global_styles post. It OVERRIDES the theme's own theme.json, so editing theme.json can appear
 * to do nothing; this makes that layer readable, mergeable and resettable (with a backup).
 */
class Store {

    const MAX_BYTES  = 200000;
    const NAME_RE    = '/^[0-9]{8}-[0-9]{6}-global-styles\.json$/';

    public static function supported(): bool {
        return function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json();
    }

    public static function unsupported(): array {
        return self::fail( 'no_theme_json', 'The active theme has no theme.json, so it has no global styles. Style a classic theme with its CSS files, or use a block theme.', 409 );
    }

    public static function post_id(): int {
        return (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    }

    /** @return array{settings:array,styles:array} the stored user layer, read straight from the post (no caches). */
    public static function user(): array {
        $post = get_post( self::post_id() );
        $data = $post ? json_decode( (string) $post->post_content, true ) : null;
        return [
            'settings' => is_array( $data['settings'] ?? null ) ? $data['settings'] : [],
            'styles'   => is_array( $data['styles'] ?? null ) ? $data['styles'] : [],
        ];
    }

    public static function theme_defaults(): array {
        $raw = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
        return [ 'settings' => $raw['settings'] ?? (object) [], 'styles' => $raw['styles'] ?? (object) [] ];
    }

    public static function describe( bool $with_theme = false ): array {
        $user = self::user();
        $out  = [
            'post_id'            => self::post_id(),
            'theme'              => get_stylesheet(),
            'has_customizations' => (bool) ( $user['settings'] || $user['styles'] ),
            'user'               => [ 'settings' => (object) $user['settings'], 'styles' => (object) $user['styles'] ],
        ];
        if ( $with_theme ) {
            $out['theme_defaults'] = self::theme_defaults();
        }
        return $out;
    }

    /**
     * @return array{ok:true,result:array}|array{ok:false,code:string,message:string,status:int}
     */
    public static function set( array $input ): array {
        if ( ! self::supported() ) {
            return self::unsupported();
        }
        $allowed = [ 'settings', 'styles', 'merge' ];
        if ( $unknown = array_diff( array_keys( $input ), $allowed ) ) {
            return self::fail( 'validation_error', 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', $allowed ) . '.', 422 );
        }
        if ( ! isset( $input['settings'] ) && ! isset( $input['styles'] ) ) {
            return self::fail( 'validation_error', "Provide 'settings' and/or 'styles' (objects).", 422 );
        }
        $merge = $input['merge'] ?? true;
        if ( ! is_bool( $merge ) ) {
            return self::fail( 'validation_error', "'merge' must be true or false.", 422 );
        }
        foreach ( [ 'settings', 'styles' ] as $k ) {
            if ( isset( $input[ $k ] ) && ( ! is_array( $input[ $k ] ) || ( $input[ $k ] && array_values( $input[ $k ] ) === $input[ $k ] ) ) ) {
                return self::fail( 'validation_error', "'{$k}' must be an object.", 422 );
            }
        }
        if ( strlen( (string) wp_json_encode( array_intersect_key( $input, [ 'settings' => 1, 'styles' => 1 ] ) ) ) > self::MAX_BYTES ) {
            return self::fail( 'validation_error', 'Payload is larger than ' . self::MAX_BYTES . ' bytes.', 422 );
        }

        $current  = self::user();
        $settings = $current['settings'];
        $styles   = $current['styles'];
        if ( isset( $input['settings'] ) ) {
            $settings = $merge ? self::merge( $settings, $input['settings'] ) : $input['settings'];
        }
        if ( isset( $input['styles'] ) ) {
            $styles = $merge ? self::merge( $styles, $input['styles'] ) : $input['styles'];
        }

        // Store only what WordPress will actually use; report the rest.
        $clean = ( new \WP_Theme_JSON( [ 'version' => \WP_Theme_JSON::LATEST_SCHEMA, 'settings' => $settings, 'styles' => $styles ], 'custom' ) )->get_raw_data();
        $ignored = array_merge(
            Validator::dropped( $settings, $clean['settings'] ?? [], 'settings' ),
            Validator::dropped( $styles, $clean['styles'] ?? [], 'styles' )
        );

        $saved = self::save( $clean['settings'] ?? [], $clean['styles'] ?? [] );
        if ( $saved !== null ) {
            return $saved;
        }
        Backups::audit( WP_CONTENT_DIR, 'global_styles_set', 'global-styles', [ 'merge' => $merge, 'ignored' => count( $ignored ) ] );
        return [ 'ok' => true, 'result' => self::describe() + [ 'ignored' => $ignored ] ];
    }

    public static function reset(): array {
        if ( ! self::supported() ) {
            return self::unsupported();
        }
        $user = self::user();
        if ( ! $user['settings'] && ! $user['styles'] ) {
            return self::fail( 'no_customizations', 'There are no user style customisations to reset.', 409 );
        }
        $name = gmdate( 'Ymd-His' ) . '-global-styles.json';
        file_put_contents( self::dir() . '/' . $name, wp_json_encode( [
            'theme' => get_stylesheet(), 'settings' => (object) $user['settings'], 'styles' => (object) $user['styles'],
            'removed_at' => gmdate( 'c' ), 'user' => get_current_user_id(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );

        if ( $err = self::save( [], [] ) ) {
            return $err;
        }
        Backups::audit( WP_CONTENT_DIR, 'global_styles_reset', 'global-styles', [ 'backup' => $name ] );
        return [ 'ok' => true, 'result' => [ 'reset' => true, 'backup' => $name ] ];
    }

    public static function backups(): array {
        $out = [];
        foreach ( glob( self::dir() . '/*.json' ) ?: [] as $file ) {
            $d = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $d ) ) {
                $out[] = [ 'name' => basename( $file ), 'theme' => $d['theme'] ?? null, 'removed_at' => $d['removed_at'] ?? null ];
            }
        }
        usort( $out, fn( $a, $b ) => strcmp( $b['name'], $a['name'] ) );
        return array_slice( $out, 0, 50 );
    }

    public static function restore( $name ): array {
        if ( ! self::supported() ) {
            return self::unsupported();
        }
        if ( ! is_string( $name ) || ! preg_match( self::NAME_RE, $name ) ) {
            return self::fail( 'validation_error', "'name' must be a backup name from global_styles_backups.", 422 );
        }
        $file = self::dir() . '/' . $name;
        if ( ! is_file( $file ) ) {
            return self::fail( 'backup_not_found', "No backup '{$name}'.", 404 );
        }
        $d = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $d ) ) {
            return self::fail( 'backup_invalid', "Backup '{$name}' is unreadable.", 422 );
        }
        if ( ( $d['theme'] ?? null ) !== get_stylesheet() ) {
            return self::fail( 'wrong_theme', "Backup is for theme '" . ( $d['theme'] ?? '?' ) . "', not the active theme.", 422 );
        }
        return self::set( [ 'settings' => (array) ( $d['settings'] ?? [] ), 'styles' => (array) ( $d['styles'] ?? [] ), 'merge' => false ] );
    }

    // -------------------------------------------------------------- helpers

    /** Saves through WordPress's own controller; null on success. */
    private static function save( array $settings, array $styles ): ?array {
        $req = new \WP_REST_Request( 'POST', '/wp/v2/global-styles/' . self::post_id() );
        $req->set_body_params( [ 'settings' => (object) $settings, 'styles' => (object) $styles ] );
        $res = rest_do_request( $req );
        if ( $res->is_error() ) {
            $e = $res->as_error();
            return self::fail( (string) $e->get_error_code(), $e->get_error_message(), $res->get_status() ?: 500 );
        }
        \WP_Theme_JSON_Resolver::clean_cached_data();
        return null;
    }

    private static function dir(): string {
        $dir = Backups::dir( WP_CONTENT_DIR ) . '/global-styles-backups';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    /** Objects merge key by key; lists (a palette, font families) are replaced as a whole. */
    private static function merge( array $base, array $patch ): array {
        foreach ( $patch as $key => $value ) {
            $both_objects = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] )
                && array_values( $value ) !== $value && array_values( $base[ $key ] ) !== $base[ $key ];
            $base[ $key ] = $both_objects ? self::merge( $base[ $key ], $value ) : $value;
        }
        return $base;
    }

    private static function fail( string $code, string $message, int $status ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
