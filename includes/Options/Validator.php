<?php

namespace AgentBridge\Options;

/** Validates option values against their spec. Pure checks, plus page lookups for page_id. */
class Validator {

    const PERMALINK_TAGS = [ '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%' ];

    /**
     * @return array{0:mixed,1:?string} normalised value to store, or an error message
     */
    public static function check( string $key, array $spec, $value ): array {
        switch ( $spec['type'] ) {
            case 'integer':
                if ( ! is_int( $value ) ) {
                    return [ null, 'must be an integer' ];
                }
                if ( ( $spec['format'] ?? '' ) === 'page_id' ) {
                    return self::page( $value );
                }
                if ( $value < $spec['min'] || $value > $spec['max'] ) {
                    return [ null, "must be between {$spec['min']} and {$spec['max']}" ];
                }
                return [ $value, null ];

            case 'boolean':
                return is_bool( $value ) ? [ $value ? 1 : 0, null ] : [ null, 'must be true or false' ];

            case 'enum':
                return is_string( $value ) && in_array( $value, $spec['values'], true )
                    ? [ $value, null ]
                    : [ null, 'must be one of: ' . implode( ', ', $spec['values'] ) ];

            case 'string':
                if ( ! is_string( $value ) ) {
                    return [ null, 'must be a string' ];
                }
                return self::string( $spec, $value );
        }
        return [ null, 'has an unsupported type' ];
    }

    private static function page( int $id ): array {
        if ( $id === 0 ) {
            return [ 0, null ];
        }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'page' || $post->post_status !== 'publish' ) {
            return [ null, "page {$id} does not exist or is not a published page" ];
        }
        return [ $id, null ];
    }

    private static function string( array $spec, string $value ): array {
        $format = $spec['format'] ?? '';
        if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) {
            return [ null, 'contains control characters' ];
        }

        switch ( $format ) {
            case 'timezone':
                return in_array( $value, timezone_identifiers_list(), true ) ? [ $value, null ] : [ null, 'is not a valid timezone identifier (e.g. Europe/Paris, UTC)' ];
            case 'url':
                return self::url( $value );
            case 'permalink':
                return self::permalink( $value );
            case 'slug_base':
                return $value === '' || ( strlen( $value ) <= 50 && preg_match( '#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#', $value ) )
                    ? [ $value, null ]
                    : [ null, 'must be empty or lowercase letters, digits, - and _ (segments separated by /)' ];
        }

        $len = strlen( trim( $value ) );
        if ( $len < ( $spec['min_length'] ?? 0 ) || strlen( $value ) > ( $spec['max_length'] ?? PHP_INT_MAX ) ) {
            return [ null, "must be {$spec['min_length']}–{$spec['max_length']} characters" ];
        }
        return [ $value, null ];
    }

    private static function url( string $value ): array {
        $u = wp_parse_url( $value );
        $ok = is_array( $u ) && isset( $u['scheme'], $u['host'] )
            && in_array( strtolower( $u['scheme'] ), [ 'http', 'https' ], true )
            && ! isset( $u['user'] ) && ! isset( $u['pass'] ) && ! isset( $u['query'] ) && ! isset( $u['fragment'] )
            && ! preg_match( '/[\s\\\\]/', $value )
            && preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $u['host'] );
        return $ok ? [ rtrim( $value, '/' ), null ] : [ null, 'must be an http(s) URL without credentials, query or fragment' ];
    }

    private static function permalink( string $value ): array {
        if ( $value === '' ) {
            return [ null, 'must not be empty: plain permalinks break the REST API the bridge itself uses' ];
        }
        if ( strlen( $value ) > 100 || $value[0] !== '/' || strpos( $value, '//' ) !== false ) {
            return [ null, 'must start with / and be at most 100 characters, with no empty segments' ];
        }
        preg_match_all( '/%[a-z_]+%/', $value, $m );
        foreach ( $m[0] as $tag ) {
            if ( ! in_array( $tag, self::PERMALINK_TAGS, true ) ) {
                return [ null, "uses an unknown tag {$tag}. Allowed: " . implode( ' ', self::PERMALINK_TAGS ) ];
            }
        }
        if ( ! in_array( '%postname%', $m[0], true ) && ! in_array( '%post_id%', $m[0], true ) ) {
            return [ null, 'must contain %postname% or %post_id% so every post gets a unique URL' ];
        }
        if ( preg_match( '#[^A-Za-z0-9/_.%-]#', str_replace( $m[0], '', $value ) ) ) {
            return [ null, 'may only contain letters, digits, / _ . - and structure tags' ];
        }
        return [ $value, null ];
    }
}
