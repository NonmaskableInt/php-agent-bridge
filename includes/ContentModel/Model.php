<?php

namespace AgentBridge\ContentModel;

/**
 * The site's custom data model — post types, taxonomies and meta fields — stored as data
 * and registered on every request by Runtime. Validation is strict and all-or-nothing.
 */
class Model {

    const OPTION         = 'agent_bridge_content_model';
    const FLUSH_FLAG     = 'agent_bridge_cm_flush';
    const MAX_TYPES      = 30;
    const MAX_TAXONOMIES = 30;
    const MAX_META       = 100;

    const SLUG_RE     = '/^[a-z][a-z0-9_-]{0,19}$/';
    const META_KEY_RE = '/^[a-z][a-z0-9_]{0,63}$/';
    const REST_BASE_RE = '/^[a-z][a-z0-9_-]{0,39}$/';

    const SUPPORTS   = [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' ];
    const META_TYPES = [ 'string', 'integer', 'number', 'boolean', 'array' ];
    const ITEM_TYPES = [ 'string', 'integer', 'number', 'boolean' ];

    const RESERVED_TYPES = [
        'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'action', 'author', 'order', 'theme',
    ];
    const RESERVED_TAXONOMIES = [
        'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
    ];
    /** First path segments core already serves under wp/v2. */
    const RESERVED_REST_BASES = [
        'posts', 'pages', 'media', 'users', 'types', 'taxonomies', 'statuses', 'comments', 'search', 'settings', 'themes',
        'plugins', 'blocks', 'block-types', 'block-renderer', 'block-directory', 'block-patterns', 'menus', 'menu-items',
        'menu-locations', 'widgets', 'widget-types', 'sidebars', 'templates', 'template-parts', 'navigation',
        'global-styles', 'font-families', 'pattern-directory', 'categories', 'tags', 'autosaves', 'revisions',
        'application-passwords', 'sites', 'wp-site-health', 'abilities',
    ];

    // ------------------------------------------------------------- storage

    public static function get(): array {
        $stored = get_option( self::OPTION, [] );
        $stored = is_array( $stored ) ? $stored : [];
        $out    = [ 'post_types' => [], 'taxonomies' => [], 'meta' => [] ];

        // Defensive, and independent of write-time validation: whatever is in the option (a past bug,
        // a direct database edit) must never be able to redefine WordPress core or break registration.
        foreach ( (array) ( $stored['post_types'] ?? [] ) as $slug => $def ) {
            if ( is_string( $slug ) && preg_match( self::SLUG_RE, $slug ) && ! in_array( $slug, self::RESERVED_TYPES, true ) && is_array( $def ) && ! empty( $def['label'] ) ) {
                $out['post_types'][ $slug ] = array_merge( self::type_defaults(), $def );
            }
        }
        foreach ( (array) ( $stored['taxonomies'] ?? [] ) as $slug => $def ) {
            if ( is_string( $slug ) && preg_match( self::SLUG_RE, $slug ) && ! in_array( $slug, self::RESERVED_TAXONOMIES, true ) && is_array( $def ) && ! empty( $def['label'] ) ) {
                $out['taxonomies'][ $slug ] = array_merge( self::taxonomy_defaults(), $def );
            }
        }
        foreach ( (array) ( $stored['meta'] ?? [] ) as $key => $def ) {
            if ( is_string( $key ) && preg_match( self::META_KEY_RE, $key ) && strpos( $key, 'wp_' ) !== 0 && is_array( $def )
                && in_array( $def['type'] ?? null, self::META_TYPES, true ) && ! empty( $def['post_types'] ) && is_array( $def['post_types'] ) ) {
                $out['meta'][ $key ] = array_merge( self::meta_defaults(), $def );
            }
        }
        return $out;
    }

    private static function save( array $model ): void {
        update_option( self::OPTION, $model, true );
        update_option( self::FLUSH_FLAG, 1, false ); // Runtime flushes rewrite rules on the next request
    }

    public static function type_defaults(): array {
        return [
            'label' => '', 'singular_label' => '', 'description' => '', 'public' => true, 'hierarchical' => false,
            'has_archive' => false, 'supports' => [ 'title', 'editor' ], 'taxonomies' => [], 'rest_base' => null,
            'menu_icon' => null, 'menu_position' => null, 'rewrite_slug' => null,
        ];
    }

    public static function taxonomy_defaults(): array {
        return [ 'label' => '', 'singular_label' => '', 'object_types' => [], 'hierarchical' => false, 'rest_base' => null, 'public' => true ];
    }

    public static function meta_defaults(): array {
        return [ 'post_types' => [], 'type' => 'string', 'items_type' => null, 'single' => true, 'description' => '', 'default' => null ];
    }

    // ---------------------------------------------------------- operations

    public static function set_type( $slug, array $input ): array {
        $model = self::get();
        if ( ! is_string( $slug ) || ! preg_match( self::SLUG_RE, $slug ) ) {
            return self::fail( 'validation_error', "Post type slug must match " . self::SLUG_RE . ' (lowercase, starts with a letter, max 20 characters).' );
        }
        if ( in_array( $slug, self::RESERVED_TYPES, true ) ) {
            return self::fail( 'slug_reserved', "'{$slug}' is a WordPress core post type and cannot be redefined." );
        }
        if ( ! isset( $model['post_types'][ $slug ] ) && post_type_exists( $slug ) ) {
            return self::fail( 'slug_in_use', "Post type '{$slug}' is already registered by WordPress, a theme or a plugin.", 409 );
        }
        if ( ! isset( $model['post_types'][ $slug ] ) && count( $model['post_types'] ) >= self::MAX_TYPES ) {
            return self::fail( 'limit_reached', 'At most ' . self::MAX_TYPES . ' post types can be defined.', 409 );
        }
        if ( $bad = self::unknown_keys( $input, array_keys( self::type_defaults() ) ) ) {
            return $bad;
        }

        $def = array_merge( self::type_defaults(), $input );

        if ( ! self::is_label( $def['label'] ) ) {
            return self::fail( 'validation_error', "'label' (plural display name, max 60 characters) is required." );
        }
        $def['singular_label'] = $def['singular_label'] === '' ? $def['label'] : $def['singular_label'];
        if ( ! self::is_label( $def['singular_label'] ) ) {
            return self::fail( 'validation_error', "'singular_label' must be a non-empty string of at most 60 characters." );
        }
        if ( ! is_string( $def['description'] ) || strlen( $def['description'] ) > 200 ) {
            return self::fail( 'validation_error', "'description' must be a string of at most 200 characters." );
        }
        foreach ( [ 'public', 'hierarchical', 'has_archive' ] as $flag ) {
            if ( ! is_bool( $def[ $flag ] ) ) {
                return self::fail( 'validation_error', "'{$flag}' must be true or false." );
            }
        }
        if ( ! is_array( $def['supports'] ) || array_values( $def['supports'] ) !== $def['supports'] || array_diff( $def['supports'], self::SUPPORTS ) || count( $def['supports'] ) !== count( array_unique( $def['supports'] ) ) ) {
            return self::fail( 'validation_error', "'supports' must be a list drawn from: " . implode( ', ', self::SUPPORTS ) . '.' );
        }
        if ( ! self::is_slug_list( $def['taxonomies'], 20 ) ) {
            return self::fail( 'validation_error', "'taxonomies' must be a list of taxonomy slugs." );
        }
        if ( $def['menu_icon'] !== null && ( ! is_string( $def['menu_icon'] ) || ! preg_match( '/^dashicons-[a-z0-9-]{1,40}$/', $def['menu_icon'] ) ) ) {
            return self::fail( 'validation_error', "'menu_icon' must be a dashicons class such as dashicons-book." );
        }
        if ( $def['menu_position'] !== null && ( ! is_int( $def['menu_position'] ) || $def['menu_position'] < 5 || $def['menu_position'] > 100 ) ) {
            return self::fail( 'validation_error', "'menu_position' must be an integer from 5 to 100." );
        }
        if ( $def['rewrite_slug'] !== null && ( ! is_string( $def['rewrite_slug'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $def['rewrite_slug'] ) ) ) {
            return self::fail( 'validation_error', "'rewrite_slug' must be lowercase letters, digits and dashes." );
        }
        if ( $err = self::check_rest_base( $def['rest_base'], $slug, 'post_types', $model ) ) {
            return $err;
        }

        $created                   = ! isset( $model['post_types'][ $slug ] );
        $model['post_types'][ $slug ] = $def;
        self::save( $model );
        return [ 'ok' => true, 'created' => $created, 'definition' => $def ];
    }

    public static function set_taxonomy( $slug, array $input ): array {
        $model = self::get();
        if ( ! is_string( $slug ) || ! preg_match( self::SLUG_RE, $slug ) ) {
            return self::fail( 'validation_error', "Taxonomy slug must match " . self::SLUG_RE . '.' );
        }
        if ( in_array( $slug, self::RESERVED_TAXONOMIES, true ) ) {
            return self::fail( 'slug_reserved', "'{$slug}' is a WordPress core taxonomy and cannot be redefined." );
        }
        if ( ! isset( $model['taxonomies'][ $slug ] ) && taxonomy_exists( $slug ) ) {
            return self::fail( 'slug_in_use', "Taxonomy '{$slug}' is already registered by WordPress, a theme or a plugin.", 409 );
        }
        if ( ! isset( $model['taxonomies'][ $slug ] ) && count( $model['taxonomies'] ) >= self::MAX_TAXONOMIES ) {
            return self::fail( 'limit_reached', 'At most ' . self::MAX_TAXONOMIES . ' taxonomies can be defined.', 409 );
        }
        if ( $bad = self::unknown_keys( $input, array_keys( self::taxonomy_defaults() ) ) ) {
            return $bad;
        }

        $def = array_merge( self::taxonomy_defaults(), $input );
        if ( ! self::is_label( $def['label'] ) ) {
            return self::fail( 'validation_error', "'label' (plural display name, max 60 characters) is required." );
        }
        $def['singular_label'] = $def['singular_label'] === '' ? $def['label'] : $def['singular_label'];
        if ( ! self::is_label( $def['singular_label'] ) ) {
            return self::fail( 'validation_error', "'singular_label' must be a non-empty string of at most 60 characters." );
        }
        foreach ( [ 'hierarchical', 'public' ] as $flag ) {
            if ( ! is_bool( $def[ $flag ] ) ) {
                return self::fail( 'validation_error', "'{$flag}' must be true or false." );
            }
        }
        if ( ! self::is_slug_list( $def['object_types'], 20 ) ) {
            return self::fail( 'validation_error', "'object_types' must be a list of post type slugs." );
        }
        if ( $err = self::check_rest_base( $def['rest_base'], $slug, 'taxonomies', $model ) ) {
            return $err;
        }

        $created                     = ! isset( $model['taxonomies'][ $slug ] );
        $model['taxonomies'][ $slug ] = $def;
        self::save( $model );
        return [ 'ok' => true, 'created' => $created, 'definition' => $def ];
    }

    public static function set_meta( $key, array $input ): array {
        $model = self::get();
        if ( ! is_string( $key ) || ! preg_match( self::META_KEY_RE, $key ) || strpos( $key, 'wp_' ) === 0 ) {
            return self::fail( 'validation_error', "Meta key must match " . self::META_KEY_RE . " (lowercase; no leading underscore, which WordPress treats as private) and not start with 'wp_'." );
        }
        if ( ! isset( $model['meta'][ $key ] ) && count( $model['meta'] ) >= self::MAX_META ) {
            return self::fail( 'limit_reached', 'At most ' . self::MAX_META . ' meta fields can be defined.', 409 );
        }
        if ( $bad = self::unknown_keys( $input, array_keys( self::meta_defaults() ) ) ) {
            return $bad;
        }

        $def = array_merge( self::meta_defaults(), $input );
        if ( ! self::is_slug_list( $def['post_types'], 10 ) || ! $def['post_types'] ) {
            return self::fail( 'validation_error', "'post_types' must be a non-empty list of post type slugs." );
        }
        if ( ! in_array( $def['type'], self::META_TYPES, true ) ) {
            return self::fail( 'validation_error', "'type' must be one of: " . implode( ', ', self::META_TYPES ) . '.' );
        }
        if ( ! is_bool( $def['single'] ) ) {
            return self::fail( 'validation_error', "'single' must be true or false." );
        }
        if ( ! is_string( $def['description'] ) || strlen( $def['description'] ) > 200 ) {
            return self::fail( 'validation_error', "'description' must be a string of at most 200 characters." );
        }

        if ( $def['type'] === 'array' ) {
            $def['items_type'] = $def['items_type'] ?? 'string';
            if ( ! in_array( $def['items_type'], self::ITEM_TYPES, true ) ) {
                return self::fail( 'validation_error', "'items_type' must be one of: " . implode( ', ', self::ITEM_TYPES ) . '.' );
            }
            if ( ! $def['single'] ) {
                return self::fail( 'validation_error', "Array fields must have single=true (the array is the single value)." );
            }
            if ( $def['default'] !== null ) {
                return self::fail( 'validation_error', "'default' is not supported for array fields." );
            }
        } else {
            if ( $def['items_type'] !== null ) {
                return self::fail( 'validation_error', "'items_type' only applies to type=array." );
            }
            if ( $def['default'] !== null && ! self::matches_type( $def['default'], $def['type'] ) ) {
                return self::fail( 'validation_error', "'default' must be a {$def['type']}." );
            }
        }

        $created             = ! isset( $model['meta'][ $key ] );
        $model['meta'][ $key ] = $def;
        self::save( $model );
        return [ 'ok' => true, 'created' => $created, 'definition' => $def ];
    }

    /** @param string $kind post_types|taxonomies|meta */
    public static function delete( string $kind, $id ): array {
        $model = self::get();
        if ( ! is_string( $id ) || ! isset( $model[ $kind ][ $id ] ) ) {
            return self::fail( 'not_found', "'" . ( is_string( $id ) ? $id : '' ) . "' is not defined in the content model.", 404 );
        }
        unset( $model[ $kind ][ $id ] );
        self::save( $model );
        return [ 'ok' => true ];
    }

    /** Posts left in the database after a post type definition is removed. */
    public static function orphaned_posts( string $slug ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $slug ) );
    }

    /** Non-blocking problems (dangling references), computed against what is actually registered. */
    public static function warnings( array $model ): array {
        $w = [];
        foreach ( $model['post_types'] as $slug => $def ) {
            foreach ( $def['taxonomies'] as $tax ) {
                if ( ! taxonomy_exists( $tax ) ) {
                    $w[] = "Post type '{$slug}' references taxonomy '{$tax}', which is not registered.";
                }
            }
        }
        foreach ( $model['taxonomies'] as $slug => $def ) {
            foreach ( $def['object_types'] as $pt ) {
                if ( ! post_type_exists( $pt ) ) {
                    $w[] = "Taxonomy '{$slug}' references post type '{$pt}', which is not registered.";
                }
            }
        }
        foreach ( $model['meta'] as $key => $def ) {
            foreach ( $def['post_types'] as $pt ) {
                if ( ! post_type_exists( $pt ) ) {
                    $w[] = "Meta field '{$key}' targets post type '{$pt}', which is not registered.";
                }
            }
        }
        return $w;
    }

    // ------------------------------------------------------------- helpers

    private static function check_rest_base( $base, string $slug, string $kind, array $model ): ?array {
        if ( $base !== null && ( ! is_string( $base ) || ! preg_match( self::REST_BASE_RE, $base ) ) ) {
            return self::fail( 'validation_error', "'rest_base' must be lowercase letters, digits, '-' or '_' (no slashes)." );
        }
        $effective = $base ?? $slug;
        if ( in_array( $effective, self::RESERVED_REST_BASES, true ) ) {
            return self::fail( 'rest_base_reserved', "REST base '{$effective}' is used by WordPress core." );
        }
        foreach ( [ 'post_types', 'taxonomies' ] as $k ) {
            foreach ( $model[ $k ] as $other_slug => $other ) {
                if ( $k === $kind && $other_slug === $slug ) {
                    continue;
                }
                if ( ( $other['rest_base'] ?? $other_slug ) === $effective ) {
                    return self::fail( 'rest_base_in_use', "REST base '{$effective}' is already used by '{$other_slug}' in the content model.", 409 );
                }
            }
        }
        foreach ( get_post_types( [], 'objects' ) as $name => $obj ) {
            if ( ! isset( $model['post_types'][ $name ] ) && $obj->show_in_rest && ( $obj->rest_base ?: $name ) === $effective ) {
                return self::fail( 'rest_base_in_use', "REST base '{$effective}' is already served for '{$name}'.", 409 );
            }
        }
        foreach ( get_taxonomies( [], 'objects' ) as $name => $obj ) {
            if ( ! isset( $model['taxonomies'][ $name ] ) && $obj->show_in_rest && ( $obj->rest_base ?: $name ) === $effective ) {
                return self::fail( 'rest_base_in_use', "REST base '{$effective}' is already served for taxonomy '{$name}'.", 409 );
            }
        }
        return null;
    }

    private static function is_label( $v ): bool {
        return is_string( $v ) && trim( $v ) !== '' && strlen( $v ) <= 60;
    }

    private static function is_slug_list( $list, int $max ): bool {
        if ( ! is_array( $list ) || array_values( $list ) !== $list || count( $list ) > $max ) {
            return false;
        }
        foreach ( $list as $s ) {
            if ( ! is_string( $s ) || ! preg_match( self::SLUG_RE, $s ) ) {
                return false;
            }
        }
        return count( $list ) === count( array_unique( $list ) );
    }

    private static function matches_type( $v, string $type ): bool {
        switch ( $type ) {
            case 'string':  return is_string( $v );
            case 'integer': return is_int( $v );
            case 'number':  return is_int( $v ) || is_float( $v );
            case 'boolean': return is_bool( $v );
        }
        return false;
    }

    private static function unknown_keys( array $input, array $allowed ): ?array {
        $unknown = array_diff( array_keys( $input ), $allowed );
        return $unknown ? self::fail( 'validation_error', 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', $allowed ) . '.' ) : null;
    }

    private static function fail( string $code, string $message, int $status = 422 ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
