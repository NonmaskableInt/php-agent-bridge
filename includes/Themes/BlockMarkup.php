<?php

namespace AgentBridge\Themes;

/**
 * Checks block markup (the <!-- wp:… --> comment syntax) without saving or rendering it.
 * WordPress's own parser is deliberately forgiving — it will happily "parse" broken markup and render
 * something odd — so this reports what the parser silently swallows.
 */
class BlockMarkup {

    const MAX_BYTES = 500000;

    // Delimiter: <!-- wp:name {attrs} --> / <!-- /wp:name --> / <!-- wp:name {attrs} /-->
    const DELIMITER = '/<!--\s+(\/)?wp:(?:([a-z][a-z0-9_-]*)\/)?([a-z][a-z0-9_-]*)((?:\s+.*?)?)\s*(\/)?-->/s';

    /**
     * @return array{valid:bool,errors:list<array>,warnings:list<array>}
     */
    public static function check( string $content ): array {
        $errors   = [];
        $warnings = [];

        preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

        $stack     = [];
        $cursor    = 0;
        $freeform  = null;
        $registry  = \WP_Block_Type_Registry::get_instance();
        $unknown_seen = [];

        foreach ( $matches as $m ) {
            [ $whole, $offset ] = $m[0];
            $line   = self::line( $content, $offset );
            $closer = $m[1][0] === '/';
            $void   = ( $m[5][0] ?? '' ) === '/';
            $ns     = $m[2][0] !== '' ? $m[2][0] : 'core';
            $name   = $ns . '/' . $m[3][0];

            // Loose text between delimiters at the top level is free-form HTML.
            if ( ! $stack && $freeform === null && self::has_loose_content( substr( $content, $cursor, $offset - $cursor ) ) ) {
                $freeform = self::line( $content, $cursor + self::leading_space( substr( $content, $cursor, $offset - $cursor ) ) );
            }
            $cursor = $offset + strlen( $whole );

            if ( $closer ) {
                if ( ! $stack ) {
                    $errors[] = self::issue( 'unexpected_closer', 'error', "Closing <!-- /wp:{$m[3][0]} --> has no matching opening block.", $line );
                } else {
                    $open = array_pop( $stack );
                    if ( $open['name'] !== $name ) {
                        $errors[] = self::issue( 'mismatched_closer', 'error', "Closing {$name} does not match the open {$open['name']} (opened on line {$open['line']}).", $line );
                    }
                }
                continue;
            }

            $attrs = trim( $m[4][0] );
            if ( $attrs !== '' ) {
                $decoded = $attrs[0] === '{' ? json_decode( $attrs, true ) : null;
                if ( ! is_array( $decoded ) ) {
                    $errors[] = self::issue( 'invalid_attributes', 'error', "Attributes of {$name} are not a valid JSON object.", $line );
                }
            }

            if ( ! $registry->is_registered( $name ) && ! isset( $unknown_seen[ $name ] ) ) {
                $unknown_seen[ $name ] = true;
                $warnings[] = self::issue( 'unregistered_block', 'warning', "Block {$name} is not registered (misspelt, or its plugin is inactive).", $line );
            }

            if ( ! $void ) {
                $stack[] = [ 'name' => $name, 'line' => $line ];
            }
        }

        foreach ( $stack as $open ) {
            $errors[] = self::issue( 'unclosed_block', 'error', "Block {$open['name']} opened on line {$open['line']} is never closed.", $open['line'] );
        }

        if ( $freeform === null && ! $stack && self::has_loose_content( substr( $content, $cursor ) ) ) {
            $freeform = self::line( $content, $cursor + self::leading_space( substr( $content, $cursor ) ) );
        }
        if ( $freeform !== null ) {
            $warnings[] = self::issue( 'freeform_content', 'warning', 'HTML outside any block (a free-form "Classic" block); it renders, but is not editable as blocks.', $freeform );
        }

        return [ 'valid' => ! $errors, 'errors' => $errors, 'warnings' => $warnings ];
    }

    /** Slugs of every <!-- wp:template-part {"slug":"x"} /--> in the content, with line numbers. */
    public static function template_part_refs( string $content ): array {
        $refs = [];
        preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        foreach ( $matches as $m ) {
            if ( $m[1][0] === '/' || ( $m[2][0] !== '' && $m[2][0] !== 'core' ) || $m[3][0] !== 'template-part' ) {
                continue;
            }
            $attrs = json_decode( trim( $m[4][0] ), true );
            if ( is_array( $attrs ) && ! empty( $attrs['slug'] ) && is_string( $attrs['slug'] ) ) {
                $refs[] = [ 'slug' => $attrs['slug'], 'theme' => $attrs['theme'] ?? null, 'line' => self::line( $content, $m[0][1] ) ];
            }
        }
        return $refs;
    }

    private static function has_loose_content( string $text ): bool {
        // Ordinary HTML comments are not content.
        return trim( preg_replace( '/<!--.*?-->/s', '', $text ) ) !== '';
    }

    private static function leading_space( string $text ): int {
        return strlen( $text ) - strlen( ltrim( $text ) );
    }

    private static function line( string $content, int $offset ): int {
        return substr_count( $content, "\n", 0, max( 0, $offset ) ) + 1;
    }

    public static function issue( string $code, string $level, string $message, ?int $line = null, ?string $file = null ): array {
        $i = [ 'code' => $code, 'level' => $level, 'message' => $message ];
        if ( $file !== null ) {
            $i['file'] = $file;
        }
        if ( $line !== null ) {
            $i['line'] = $line;
        }
        return $i;
    }
}
