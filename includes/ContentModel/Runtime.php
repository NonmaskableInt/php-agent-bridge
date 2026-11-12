<?php

namespace AgentBridge\ContentModel;

/**
 * Registers the stored content model on every request. A broken definition is skipped,
 * never fatal. Emergency off switch: define( 'AGENT_BRIDGE_DISABLE_CONTENT_MODEL', true ).
 */
class Runtime {

    public static function boot(): void {
        add_action( 'init', [ self::class, 'register' ], 0 );
        add_action( 'init', [ self::class, 'maybe_flush' ], 99 );
    }

    public static function register(): void {
        if ( defined( 'AGENT_BRIDGE_DISABLE_CONTENT_MODEL' ) && AGENT_BRIDGE_DISABLE_CONTENT_MODEL ) {
            return;
        }
        try {
            $model = Model::get();
        } catch ( \Throwable $e ) {
            return;
        }

        // Meta REST exposure needs the 'custom-fields' feature on the post type.
        $meta_targets = [];
        foreach ( $model['meta'] as $def ) {
            foreach ( $def['post_types'] as $pt ) {
                $meta_targets[ $pt ] = true;
            }
        }

        foreach ( $model['taxonomies'] as $slug => $def ) {
            try {
                register_taxonomy( $slug, [], [
                    'labels'            => [ 'name' => $def['label'], 'singular_name' => $def['singular_label'] ],
                    'public'            => $def['public'],
                    'hierarchical'      => $def['hierarchical'],
                    'show_ui'           => true,
                    'show_admin_column' => true,
                    'show_in_rest'      => true,
                    'rest_base'         => $def['rest_base'] ?? $slug,
                    'rewrite'           => [ 'slug' => $slug ],
                ] );
            } catch ( \Throwable $e ) {
                continue;
            }
        }

        foreach ( $model['post_types'] as $slug => $def ) {
            $supports = $def['supports'];
            if ( isset( $meta_targets[ $slug ] ) && ! in_array( 'custom-fields', $supports, true ) ) {
                $supports[] = 'custom-fields';
            }
            $args = [
                'labels'          => [ 'name' => $def['label'], 'singular_name' => $def['singular_label'] ],
                'description'     => $def['description'],
                'public'          => $def['public'],
                'hierarchical'    => $def['hierarchical'],
                'has_archive'     => $def['has_archive'],
                'show_ui'         => true,
                'show_in_menu'    => true,
                'show_in_rest'    => true,
                'rest_base'       => $def['rest_base'] ?? $slug,
                'supports'        => $supports,
                'rewrite'         => [ 'slug' => $def['rewrite_slug'] ?? $slug ],
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            ];
            if ( $def['menu_icon'] !== null ) {
                $args['menu_icon'] = $def['menu_icon'];
            }
            if ( $def['menu_position'] !== null ) {
                $args['menu_position'] = $def['menu_position'];
            }
            try {
                register_post_type( $slug, $args );
            } catch ( \Throwable $e ) {
                continue;
            }
        }

        // Wire both directions of the type <-> taxonomy relationship.
        foreach ( $model['post_types'] as $slug => $def ) {
            foreach ( $def['taxonomies'] as $tax ) {
                if ( taxonomy_exists( $tax ) && post_type_exists( $slug ) ) {
                    register_taxonomy_for_object_type( $tax, $slug );
                }
            }
        }
        foreach ( $model['taxonomies'] as $slug => $def ) {
            foreach ( $def['object_types'] as $pt ) {
                if ( post_type_exists( $pt ) && taxonomy_exists( $slug ) ) {
                    register_taxonomy_for_object_type( $slug, $pt );
                }
            }
        }

        foreach ( $model['meta'] as $key => $def ) {
            $args = [
                'type'          => $def['type'],
                'single'        => $def['single'],
                'description'   => $def['description'],
                'show_in_rest'  => $def['type'] === 'array'
                    ? [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => $def['items_type'] ?? 'string' ] ] ]
                    : true,
                'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
            ];
            if ( $def['default'] !== null ) {
                $args['default'] = $def['default'];
            }
            foreach ( $def['post_types'] as $pt ) {
                try {
                    register_post_meta( $pt, $key, $args );
                } catch ( \Throwable $e ) {
                    continue;
                }
            }
        }
    }

    /** New or removed post types need fresh rewrite rules; done once, on the request after the change. */
    public static function maybe_flush(): void {
        if ( get_option( Model::FLUSH_FLAG ) ) {
            delete_option( Model::FLUSH_FLAG );
            flush_rewrite_rules( false );
        }
    }
}
