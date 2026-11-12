<?php

namespace AgentBridge\Themes;

use AgentBridge\Apps\Archive;
use AgentBridge\Files\Backups;
use AgentBridge\Files\PathGuard;
use AgentBridge\Files\PhpGuard;

/**
 * Creates a working block, classic or child theme in one step.
 *
 * All-or-nothing: files are written to a hidden staging directory (WordPress ignores dot-directories),
 * every path goes through the same guard as /files, generated PHP is linted, and the result must pass
 * the theme validator before it is moved into place. Never activates the theme.
 */
class Scaffold {

    const TYPES   = [ 'block', 'classic', 'child' ];
    const SLUG_RE = '/^[a-z][a-z0-9-]{1,40}$/';

    /**
     * @return array{ok:true,result:array}|array{ok:false,code:string,message:string,status:int}
     */
    public static function create( array $input ): array {
        $allowed = [ 'type', 'slug', 'name', 'description', 'author', 'parent' ];
        if ( $unknown = array_diff( array_keys( $input ), $allowed ) ) {
            return self::fail( 'validation_error', 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', $allowed ) . '.', 422 );
        }

        $type = $input['type'] ?? null;
        if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
            return self::fail( 'validation_error', "'type' must be one of: " . implode( ', ', self::TYPES ) . '.', 422 );
        }
        $slug = $input['slug'] ?? null;
        if ( ! is_string( $slug ) || ! preg_match( self::SLUG_RE, $slug ) || substr( $slug, -1 ) === '-' ) {
            return self::fail( 'validation_error', "'slug' must be 2–41 characters: lowercase letters, digits and dashes, starting with a letter.", 422 );
        }
        foreach ( [ 'name' => [ 1, 60, true ], 'description' => [ 0, 200, false ], 'author' => [ 0, 60, false ] ] as $field => [ $min, $max, $required ] ) {
            if ( ! isset( $input[ $field ] ) ) {
                if ( $required ) {
                    return self::fail( 'validation_error', "'{$field}' is required.", 422 );
                }
                continue;
            }
            if ( ! self::header_safe( $input[ $field ], $min, $max ) ) {
                return self::fail( 'validation_error', "'{$field}' must be {$min}–{$max} characters on a single line, with no control characters, '*/' or '<?'.", 422 );
            }
        }
        $name        = $input['name'];
        $description = $input['description'] ?? '';
        $author      = $input['author'] ?? '';

        $parent = null;
        if ( $type === 'child' ) {
            $parent = $input['parent'] ?? null;
            if ( ! is_string( $parent ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $parent ) ) {
                return self::fail( 'validation_error', "A child theme needs 'parent': the directory name of an installed theme.", 422 );
            }
            $parent_theme = wp_get_theme( $parent );
            if ( ! $parent_theme->exists() ) {
                return self::fail( 'parent_not_found', "Parent theme '{$parent}' is not installed.", 422 );
            }
            if ( $parent_theme->parent() ) {
                return self::fail( 'parent_is_child', "'{$parent}' is itself a child theme; WordPress supports only one level.", 422 );
            }
        } elseif ( isset( $input['parent'] ) ) {
            return self::fail( 'validation_error', "'parent' only applies to type 'child'.", 422 );
        }

        $root = WP_CONTENT_DIR . '/themes';
        if ( is_link( $root ) || ! is_dir( $root ) ) {
            return self::fail( 'file_write_failed', 'The themes directory is missing or is a symlink.', 500 );
        }
        if ( file_exists( "{$root}/{$slug}" ) || wp_get_theme( $slug )->exists() ) {
            return self::fail( 'theme_exists', "A theme '{$slug}' already exists; scaffolding never overwrites.", 409 );
        }

        $files = self::files( $type, $slug, $name, $description, $author, $parent );
        foreach ( array_keys( $files ) as $rel ) {
            if ( $deny = PathGuard::write_denial( "themes/{$slug}/{$rel}" ) ) {
                return self::fail( 'path_not_allowed', $deny, 500 ); // a template bug, never user input
            }
        }

        // Stage, verify, then move into place in one step.
        $stage = $root . '/.agent-scaffold-' . bin2hex( random_bytes( 6 ) );
        try {
            foreach ( $files as $rel => $content ) {
                $path = "{$stage}/{$rel}";
                if ( ! wp_mkdir_p( dirname( $path ) ) || file_put_contents( $path, $content ) === false ) {
                    throw new \RuntimeException( "Could not write {$rel}." );
                }
                if ( substr( $rel, -4 ) === '.php' ) {
                    $lint = PhpGuard::lint( $path );
                    if ( ! empty( $lint['unavailable'] ) ) {
                        Archive::remove_tree( $stage );
                        return self::fail( 'verification_unavailable', $lint['output'], 503 );
                    }
                    if ( ! $lint['ok'] ) {
                        throw new \RuntimeException( "Generated {$rel} failed PHP lint: " . $lint['output'] );
                    }
                }
            }

            $report = Validator::validate( new \WP_Theme( basename( $stage ), $root ) );
            if ( ! $report['valid'] ) {
                throw new \RuntimeException( 'Generated theme failed validation: ' . wp_json_encode( $report['errors'] ) );
            }
            if ( ! rename( $stage, "{$root}/{$slug}" ) ) {
                throw new \RuntimeException( 'Could not move the theme into place.' );
            }
        } catch ( \Throwable $e ) {
            Archive::remove_tree( $stage );
            return self::fail( 'scaffold_failed', $e->getMessage(), 500 );
        }

        wp_clean_themes_cache();
        $final = Validator::validate( wp_get_theme( $slug ) );
        Backups::audit( WP_CONTENT_DIR, 'theme_scaffold', "themes/{$slug}", [ 'type' => $type, 'files' => count( $files ) ] );

        return [ 'ok' => true, 'result' => [
            'stylesheet' => $slug,
            'type'       => $type,
            'path'       => "themes/{$slug}",
            'files'      => array_keys( $files ),
            'validation' => $final,
            'activated'  => false,
        ] ];
    }

    /** One line, no control characters, nothing that could end the header comment or open PHP. */
    private static function header_safe( $value, int $min, int $max ): bool {
        return is_string( $value )
            && mb_strlen( $value ) >= $min && mb_strlen( $value ) <= $max
            && ! preg_match( '/[\x00-\x1f\x7f]/', $value )
            && strpos( $value, '*/' ) === false
            && strpos( $value, '<?' ) === false
            && ( $min === 0 || trim( $value ) !== '' );
    }

    // ------------------------------------------------------------ templates

    /** @return array<string,string> relative path => contents */
    private static function files( string $type, string $slug, string $name, string $description, string $author, ?string $parent ): array {
        $header = self::style_header( $slug, $name, $description, $author, $parent );
        $fn     = str_replace( '-', '_', $slug );

        if ( $type === 'child' ) {
            $parent_is_block = is_file( wp_get_theme( $parent )->get_stylesheet_directory() . '/theme.json' )
                || (bool) glob( wp_get_theme( $parent )->get_stylesheet_directory() . '/templates/*.html' );
            $functions = $parent_is_block
                ? "<?php\n/**\n * Child theme functions. Templates and theme.json are inherited from the parent; add overrides here.\n */\n"
                : "<?php\n/**\n * Child theme functions.\n */\nadd_action( 'wp_enqueue_scripts', '{$fn}_enqueue_styles' );\nfunction {$fn}_enqueue_styles() {\n\twp_enqueue_style( '{$fn}-parent', get_template_directory_uri() . '/style.css' );\n\twp_enqueue_style( '{$fn}-child', get_stylesheet_uri(), array( '{$fn}-parent' ), wp_get_theme()->get( 'Version' ) );\n}\n";
            return [ 'style.css' => $header, 'functions.php' => $functions ];
        }

        if ( $type === 'classic' ) {
            return [
                'style.css'     => $header . "\nbody { font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; line-height: 1.6; margin: 0; }\n.site-header, .site-main, .site-footer { max-width: 720px; margin: 0 auto; padding: 1rem; }\n",
                'functions.php' => "<?php\n/**\n * Theme setup.\n */\nadd_action( 'after_setup_theme', '{$fn}_setup' );\nfunction {$fn}_setup() {\n\tadd_theme_support( 'title-tag' );\n\tadd_theme_support( 'post-thumbnails' );\n\tadd_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );\n\tregister_nav_menus( array( 'primary' => __( 'Primary menu', '{$slug}' ) ) );\n}\n\nadd_action( 'wp_enqueue_scripts', '{$fn}_styles' );\nfunction {$fn}_styles() {\n\twp_enqueue_style( '{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n}\n",
                'header.php'    => "<?php\n/** Site header. */\n?><!doctype html>\n<html <?php language_attributes(); ?>>\n<head>\n<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n<?php wp_body_open(); ?>\n<header class=\"site-header\">\n\t<p class=\"site-title\"><a href=\"<?php echo esc_url( home_url( '/' ) ); ?>\"><?php bloginfo( 'name' ); ?></a></p>\n\t<?php wp_nav_menu( array( 'theme_location' => 'primary', 'fallback_cb' => false ) ); ?>\n</header>\n<main class=\"site-main\">\n",
                'footer.php'    => "<?php\n/** Site footer. */\n?></main>\n<footer class=\"site-footer\">\n\t<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>\n</footer>\n<?php wp_footer(); ?>\n</body>\n</html>\n",
                'index.php'     => self::loop_template( 'Latest posts', true ),
                'archive.php'   => self::loop_template( 'Archive', true ),
                'search.php'    => "<?php\n/** Search results. */\nget_header();\n?>\n<h1><?php printf( esc_html__( 'Search results for: %s', '{$slug}' ), esc_html( get_search_query() ) ); ?></h1>\n<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>\n<article <?php post_class(); ?>><h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2><?php the_excerpt(); ?></article>\n<?php endwhile; the_posts_navigation(); else : ?>\n<p><?php esc_html_e( 'Nothing found.', '{$slug}' ); ?></p>\n<?php endif; ?>\n<?php get_footer();\n",
                'single.php'    => "<?php\n/** Single post. */\nget_header();\nwhile ( have_posts() ) : the_post();\n?>\n<article <?php post_class(); ?>>\n\t<h1><?php the_title(); ?></h1>\n\t<?php the_content(); ?>\n</article>\n<?php\n\tcomments_template();\nendwhile;\nget_footer();\n",
                'page.php'      => "<?php\n/** Page. */\nget_header();\nwhile ( have_posts() ) : the_post();\n?>\n<article <?php post_class(); ?>>\n\t<h1><?php the_title(); ?></h1>\n\t<?php the_content(); ?>\n</article>\n<?php\nendwhile;\nget_footer();\n",
                '404.php'       => "<?php\n/** Not found. */\nget_header();\n?>\n<h1><?php esc_html_e( 'Page not found', '{$slug}' ); ?></h1>\n<p><?php esc_html_e( 'Nothing lives at this address.', '{$slug}' ); ?></p>\n<?php get_search_form(); ?>\n<?php get_footer();\n",
            ];
        }

        // block
        $paragraph = static fn( string $t ) => "<!-- wp:paragraph -->\n<p>{$t}</p>\n<!-- /wp:paragraph -->";
        $header_part = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->';
        $footer_part = '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
        $main = static fn( string $inner ) => "<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"type\":\"constrained\"}} -->\n<main class=\"wp-block-group\">{$inner}</main>\n<!-- /wp:group -->";

        return [
            'style.css'  => $header,
            'theme.json' => wp_json_encode( [
                '$schema'  => 'https://schemas.wp.org/trunk/theme.json',
                'version'  => 3,
                'settings' => [
                    'layout'     => [ 'contentSize' => '720px', 'wideSize' => '1100px' ],
                    'color'      => [ 'palette' => [
                        [ 'slug' => 'base', 'name' => 'Base', 'color' => '#ffffff' ],
                        [ 'slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111' ],
                        [ 'slug' => 'primary', 'name' => 'Primary', 'color' => '#1d4ed8' ],
                    ] ],
                    'typography' => [ 'fontFamilies' => [
                        [ 'slug' => 'system', 'name' => 'System', 'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' ],
                    ] ],
                ],
                // Without this, header/footer parts are filed as "uncategorized" instead of their real areas.
                'templateParts' => [
                    [ 'name' => 'header', 'title' => 'Header', 'area' => 'header' ],
                    [ 'name' => 'footer', 'title' => 'Footer', 'area' => 'footer' ],
                ],
                'styles'   => [
                    'color'      => [ 'background' => 'var(--wp--preset--color--base)', 'text' => 'var(--wp--preset--color--contrast)' ],
                    'typography' => [ 'fontFamily' => 'var(--wp--preset--font-family--system)', 'fontSize' => '1.0625rem', 'lineHeight' => '1.6' ],
                    'elements'   => [ 'link' => [ 'color' => [ 'text' => 'var(--wp--preset--color--primary)' ] ] ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
            'functions.php' => "<?php\n/**\n * Theme setup.\n */\nadd_action( 'after_setup_theme', '{$fn}_setup' );\nfunction {$fn}_setup() {\n\tadd_theme_support( 'wp-block-styles' );\n}\n",
            'parts/header.html' => "<!-- wp:group {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"space-between\"}} -->\n<div class=\"wp-block-group\"><!-- wp:site-title /-->\n\n<!-- wp:navigation /--></div>\n<!-- /wp:group -->\n",
            'parts/footer.html' => "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:site-title {\"level\":0} /--></div>\n<!-- /wp:group -->\n",
            'templates/index.html' => "{$header_part}\n\n" . $main( "<!-- wp:query {\"queryId\":0,\"query\":{\"perPage\":10,\"pages\":0,\"offset\":0,\"postType\":\"post\",\"order\":\"desc\",\"orderBy\":\"date\",\"inherit\":true}} -->\n<div class=\"wp-block-query\"><!-- wp:post-template -->\n<!-- wp:post-title {\"isLink\":true} /-->\n\n<!-- wp:post-excerpt /-->\n<!-- /wp:post-template -->\n\n<!-- wp:query-pagination -->\n<!-- wp:query-pagination-previous /-->\n\n<!-- wp:query-pagination-next /-->\n<!-- /wp:query-pagination --></div>\n<!-- /wp:query -->" ) . "\n\n{$footer_part}\n",
            'templates/single.html' => "{$header_part}\n\n" . $main( "<!-- wp:post-title {\"level\":1} /-->\n\n<!-- wp:post-content /-->" ) . "\n\n{$footer_part}\n",
            'templates/page.html'   => "{$header_part}\n\n" . $main( "<!-- wp:post-title {\"level\":1} /-->\n\n<!-- wp:post-content /-->" ) . "\n\n{$footer_part}\n",
            'templates/404.html'    => "{$header_part}\n\n" . $main( "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Page not found</h1>\n<!-- /wp:heading -->\n\n" . $paragraph( 'Nothing lives at this address.' ) . "\n\n<!-- wp:search {\"label\":\"Search\",\"buttonText\":\"Search\"} /-->" ) . "\n\n{$footer_part}\n",
        ];
    }

    private static function loop_template( string $heading, bool $pagination ): string {
        return "<?php\n/** {$heading}. */\nget_header();\n?>\n<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>\n<article <?php post_class(); ?>>\n\t<h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n\t<?php the_excerpt(); ?>\n</article>\n<?php endwhile; " . ( $pagination ? 'the_posts_navigation(); ' : '' ) . "else : ?>\n<p><?php esc_html_e( 'Nothing to show yet.', 'default' ); ?></p>\n<?php endif; ?>\n<?php get_footer();\n";
    }

    private static function style_header( string $slug, string $name, string $description, string $author, ?string $parent ): string {
        $lines = [ "Theme Name: {$name}" ];
        if ( $author !== '' ) {
            $lines[] = "Author: {$author}";
        }
        if ( $description !== '' ) {
            $lines[] = "Description: {$description}";
        }
        $lines[] = 'Version: 1.0.0';
        $lines[] = 'Requires at least: 6.5';
        $lines[] = 'Requires PHP: 7.4';
        $lines[] = 'License: GNU General Public License v2 or later';
        $lines[] = 'License URI: https://www.gnu.org/licenses/gpl-2.0.html';
        $lines[] = "Text Domain: {$slug}";
        if ( $parent !== null ) {
            $lines[] = "Template: {$parent}";
        }
        return "/*\n" . implode( "\n", $lines ) . "\n*/\n";
    }

    private static function fail( string $code, string $message, int $status ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
