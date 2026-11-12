<?php

namespace AgentBridge\Templates;

/** Template files of a classic (PHP) theme, so agents know what to edit with the files API. */
class Classic {

    const TEMPLATE_RE = '/^(index|home|front-page|singular|single(-[a-z0-9_-]+)?|page(-[a-z0-9_-]+)?|archive(-[a-z0-9_-]+)?|category(-[a-z0-9_-]+)?|tag(-[a-z0-9_-]+)?|taxonomy(-[a-z0-9_-]+)?|author(-[a-z0-9_-]+)?|date|search|404|attachment|image|privacy-policy|embed(-[a-z0-9_-]+)?|comments-popup)\.php$/';
    const PART_FILES  = [ 'header.php', 'footer.php', 'sidebar.php', 'comments.php', 'searchform.php' ];

    /** @return list<array{file:string,kind:string,name?:string,theme:string,path:string}> */
    public static function files( \WP_Theme $theme ): array {
        $dirs = [ $theme->get_stylesheet() => $theme->get_stylesheet_directory() ];
        if ( $theme->parent() ) {
            $dirs[ $theme->get_template() ] = $theme->get_template_directory();
        }
        $page_templates = $theme->get_page_templates( null, 'page' );

        $out = [];
        foreach ( $dirs as $slug => $dir ) {
            foreach ( self::php_files( $dir ) as $rel ) {
                if ( isset( $out[ $rel ] ) ) {
                    continue; // child theme wins
                }
                $entry = [ 'file' => $rel, 'kind' => self::kind( $rel ), 'theme' => $slug, 'path' => "themes/{$slug}/{$rel}" ];
                if ( isset( $page_templates[ $rel ] ) ) {
                    $entry['kind'] = 'page_template';
                    $entry['name'] = $page_templates[ $rel ];
                }
                if ( $entry['kind'] !== 'other' ) {
                    $out[ $rel ] = $entry;
                }
            }
        }
        ksort( $out );
        return array_values( $out );
    }

    private static function kind( string $rel ): string {
        if ( $rel === 'functions.php' ) {
            return 'functions';
        }
        if ( in_array( $rel, self::PART_FILES, true ) || preg_match( '#^(template-parts|parts)/#', $rel ) ) {
            return 'part';
        }
        return preg_match( self::TEMPLATE_RE, $rel ) ? 'template' : 'other';
    }

    /** Top-level PHP files plus template-parts/, parts/ and templates/ (one level of nesting). */
    private static function php_files( string $dir ): array {
        $found = [];
        foreach ( glob( $dir . '/*.php' ) ?: [] as $f ) {
            $found[] = basename( $f );
        }
        foreach ( [ 'template-parts', 'parts', 'templates' ] as $sub ) {
            foreach ( glob( "{$dir}/{$sub}/*.php" ) ?: [] as $f ) {
                $found[] = $sub . '/' . basename( $f );
            }
            foreach ( glob( "{$dir}/{$sub}/*/*.php" ) ?: [] as $f ) {
                $found[] = $sub . '/' . basename( dirname( $f ) ) . '/' . basename( $f );
            }
        }
        return $found;
    }
}
