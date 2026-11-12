<?php

namespace AgentBridge\Themes;

use AgentBridge\Files\PhpGuard;

/** Structural checks for an installed theme (block, classic or child), before it is activated. */
class Validator {

    const MAX_FILES = 200;
    const TOP_KEYS  = [ '$schema', 'version', 'title', 'settings', 'styles', 'customTemplates', 'templateParts', 'patterns' ];

    /** @return array{stylesheet:string,theme_type:string,valid:bool,errors:list<array>,warnings:list<array>,checked:array} */
    public static function validate( \WP_Theme $theme ): array {
        $dir     = $theme->get_stylesheet_directory();
        $parent  = null;
        $issues  = [];
        $checked = 0;
        $add     = static function ( string $code, string $level, string $message, ?string $file = null, ?int $line = null ) use ( &$issues ) {
            $issues[] = BlockMarkup::issue( $code, $level, $message, $line, $file );
        };

        // style.css header
        $css = is_readable( "{$dir}/style.css" ) ? (string) file_get_contents( "{$dir}/style.css", false, null, 0, 8192 ) : '';
        if ( ! preg_match( '/^[ \t\/*#@]*Theme Name:\s*\S/mi', $css ) ) {
            $add( 'missing_theme_header', 'error', "style.css has no 'Theme Name:' header, so WordPress cannot list the theme.", 'style.css' );
        }

        // parent theme
        $template = $theme->get( 'Template' );
        if ( $template && $template !== $theme->get_stylesheet() ) {
            $parent = wp_get_theme( $template );
            if ( ! $parent->exists() ) {
                $add( 'missing_parent_theme', 'error', "This child theme needs the parent theme '{$template}', which is not installed.", 'style.css' );
                $parent = null;
            } elseif ( $parent->parent() ) {
                $add( 'parent_is_child', 'error', "Parent theme '{$template}' is itself a child theme; WordPress supports only one level.", 'style.css' );
            }
        }
        $roots = array_filter( [ $dir, $parent ? $parent->get_stylesheet_directory() : null ] );

        $has_json   = (bool) array_filter( $roots, fn( $r ) => is_file( "{$r}/theme.json" ) );
        $has_blocks = (bool) array_filter( $roots, fn( $r ) => glob( "{$r}/templates/*.html" ) || glob( "{$r}/block-templates/*.html" ) );
        $type       = ( $has_json || $has_blocks || $theme->is_block_theme() ) ? 'block' : 'classic';

        // index template
        $index = array_filter( $roots, fn( $r ) => is_file( "{$r}/templates/index.html" ) || is_file( "{$r}/block-templates/index.html" ) || is_file( "{$r}/index.php" ) );
        if ( $type === 'block' ) {
            $index = array_filter( $roots, fn( $r ) => is_file( "{$r}/templates/index.html" ) || is_file( "{$r}/block-templates/index.html" ) );
        } else {
            $index = array_filter( $roots, fn( $r ) => is_file( "{$r}/index.php" ) );
        }
        if ( ! $index ) {
            $add( 'missing_index_template', 'error', $type === 'block' ? 'A block theme needs templates/index.html.' : 'A classic theme needs index.php.', $type === 'block' ? 'templates/index.html' : 'index.php' );
        }

        // theme.json
        if ( is_file( "{$dir}/theme.json" ) ) {
            $checked++;
            self::theme_json( "{$dir}/theme.json", $add );
        }

        // block templates, parts and patterns
        if ( $type === 'block' ) {
            $parts_available = [];
            foreach ( $roots as $r ) {
                foreach ( array_merge( glob( "{$r}/parts/*.html" ) ?: [], glob( "{$r}/block-template-parts/*.html" ) ?: [] ) as $f ) {
                    $parts_available[ basename( $f, '.html' ) ] = true;
                }
            }
            $files = array_merge(
                glob( "{$dir}/templates/*.html" ) ?: [], glob( "{$dir}/block-templates/*.html" ) ?: [],
                glob( "{$dir}/parts/*.html" ) ?: [], glob( "{$dir}/block-template-parts/*.html" ) ?: []
            );
            foreach ( array_slice( $files, 0, self::MAX_FILES ) as $f ) {
                $checked++;
                $rel     = ltrim( substr( $f, strlen( $dir ) ), '/' );
                $content = (string) file_get_contents( $f, false, null, 0, BlockMarkup::MAX_BYTES );
                $r       = BlockMarkup::check( $content );
                foreach ( array_merge( $r['errors'], $r['warnings'] ) as $i ) {
                    $add( $i['code'], $i['level'], $i['message'], $rel, $i['line'] ?? null );
                }
                foreach ( BlockMarkup::template_part_refs( $content ) as $ref ) {
                    if ( ! isset( $parts_available[ $ref['slug'] ] ) && ( $ref['theme'] === null || $ref['theme'] === $theme->get_stylesheet() ) ) {
                        $add( 'missing_template_part', 'error', "Refers to template part '{$ref['slug']}', but parts/{$ref['slug']}.html does not exist in this theme.", $rel, $ref['line'] );
                    }
                }
            }
            foreach ( array_slice( glob( "{$dir}/patterns/*.php" ) ?: [], 0, self::MAX_FILES ) as $f ) {
                $checked++;
                $head = (string) file_get_contents( $f, false, null, 0, 4096 );
                $rel  = ltrim( substr( $f, strlen( $dir ) ), '/' );
                if ( ! preg_match( '/^\s*\*?\s*Title:\s*\S/mi', $head ) || ! preg_match( '/^\s*\*?\s*Slug:\s*\S/mi', $head ) ) {
                    $add( 'pattern_missing_header', 'warning', 'Pattern files need Title: and Slug: in their header comment to be registered.', $rel );
                }
            }
        }

        // PHP syntax (functions.php, top-level and pattern PHP)
        $php = array_merge( glob( "{$dir}/*.php" ) ?: [], $type === 'block' ? ( glob( "{$dir}/patterns/*.php" ) ?: [] ) : [], glob( "{$dir}/inc/*.php" ) ?: [] );
        $lint_unavailable = false;
        foreach ( array_slice( $php, 0, self::MAX_FILES ) as $f ) {
            $checked++;
            $res = PhpGuard::lint( $f );
            $rel = ltrim( substr( $f, strlen( $dir ) ), '/' );
            if ( ! empty( $res['unavailable'] ) ) {
                $lint_unavailable = true;
                break;
            }
            if ( ! $res['ok'] ) {
                $add( 'php_lint_failed', 'error', trim( str_replace( $f, $rel, $res['output'] ) ), $rel );
            }
        }
        if ( $lint_unavailable ) {
            $add( 'lint_unavailable', 'warning', 'PHP could not be run to check syntax (php binary or proc_open unavailable).' );
        }

        $errors   = array_values( array_filter( $issues, fn( $i ) => $i['level'] === 'error' ) );
        $warnings = array_values( array_filter( $issues, fn( $i ) => $i['level'] === 'warning' ) );
        return [
            'stylesheet' => $theme->get_stylesheet(),
            'theme_type' => $type,
            'valid'      => ! $errors,
            'errors'     => $errors,
            'warnings'   => $warnings,
            'checked'    => [ 'files' => $checked ],
        ];
    }

    /** theme.json: valid JSON, known version, known keys, and which properties WordPress silently drops. */
    private static function theme_json( string $path, callable $add ): void {
        $raw  = (string) file_get_contents( $path );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $add( 'invalid_theme_json', 'error', 'theme.json is not valid JSON: ' . json_last_error_msg() . '. WordPress ignores the whole file.', 'theme.json' );
            return;
        }
        if ( ! isset( $data['version'] ) || ! in_array( $data['version'], [ 1, 2, 3 ], true ) ) {
            $add( 'unsupported_theme_json_version', 'warning', "theme.json 'version' should be 3 (supported: 1, 2, 3).", 'theme.json' );
        }
        foreach ( array_diff( array_keys( $data ), self::TOP_KEYS ) as $key ) {
            $add( 'unknown_theme_json_key', 'warning', "Unknown top-level key '{$key}' in theme.json; WordPress ignores it.", 'theme.json' );
        }

        if ( class_exists( '\WP_Theme_JSON' ) ) {
            $kept = ( new \WP_Theme_JSON( $data, 'theme' ) )->get_raw_data();
            foreach ( [ 'settings', 'styles' ] as $section ) {
                if ( isset( $data[ $section ] ) && is_array( $data[ $section ] ) ) {
                    foreach ( self::dropped( $data[ $section ], $kept[ $section ] ?? [], $section ) as $dropped ) {
                        $add( 'ignored_theme_json_property', 'warning', "theme.json '{$dropped}' is not a valid property and is ignored by WordPress.", 'theme.json' );
                    }
                }
            }
        }
    }

    /** Dotted paths that exist in $input but were removed by WordPress's sanitiser. Lists are not compared. */
    public static function dropped( array $input, $kept, string $path ): array {
        $out = [];
        if ( array_values( $input ) === $input ) {
            return $out;
        }
        foreach ( $input as $key => $value ) {
            if ( ! is_array( $kept ) || ! array_key_exists( $key, $kept ) ) {
                $out[] = "{$path}.{$key}";
            } elseif ( is_array( $value ) ) {
                $out = array_merge( $out, self::dropped( $value, $kept[ $key ], "{$path}.{$key}" ) );
            }
        }
        return $out;
    }
}
