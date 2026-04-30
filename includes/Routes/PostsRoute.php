<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class PostsRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/posts', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_posts' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
                'args'                => [
                    'post_type' => [ 'type' => 'string', 'default' => 'post' ],
                    'status'    => [ 'type' => 'string', 'default' => 'publish' ],
                    'per_page'  => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'search'    => [ 'type' => 'string', 'default' => '' ],
                    'tag'       => [ 'type' => 'string', 'default' => '' ],
                    'category'  => [ 'type' => 'string', 'default' => '' ],
                    'page'      => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'create_post' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_post' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_post' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_post' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'duplicate_post' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );
    }

    public static function list_posts( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => sanitize_key( $request->get_param( 'post_type' ) ),
            'post_status'    => sanitize_key( $request->get_param( 'status' ) ),
            'posts_per_page' => (int) $request->get_param( 'per_page' ),
            'paged'          => (int) $request->get_param( 'page' ),
            'suppress_filters' => false,
        ];

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['s'] = sanitize_text_field( $search );
        }

        $tag = $request->get_param( 'tag' );
        if ( $tag ) {
            $args['tag'] = sanitize_text_field( $tag );
        }

        $category = $request->get_param( 'category' );
        if ( $category ) {
            $args['category_name'] = sanitize_text_field( $category );
        }

        $query = new \WP_Query( $args );
        $posts = array_map( [ self::class, 'format_post' ], $query->posts );

        return Response::success( [
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    public static function get_post( \WP_REST_Request $request ): \WP_REST_Response {
        $post = get_post( (int) $request->get_param( 'id' ) );

        if ( ! $post ) {
            return Response::error( 'post_not_found', 'No post with that ID exists.', 404 );
        }

        return Response::success( self::format_post( $post, true ) );
    }

    public static function create_post( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];

        if ( empty( $data['title'] ) && empty( $data['content'] ) ) {
            return Response::validation_error( 'Either title or content is required.' );
        }

        $post_data = [
            'post_title'   => sanitize_text_field( $data['title'] ?? '' ),
            'post_content' => wp_kses_post( $data['content'] ?? '' ),
            'post_excerpt' => sanitize_text_field( $data['excerpt'] ?? '' ),
            'post_status'  => sanitize_key( $data['status'] ?? 'draft' ),
            'post_type'    => sanitize_key( $data['post_type'] ?? 'post' ),
        ];

        if ( ! empty( $data['date'] ) ) {
            $post_data['post_date']     = get_date_from_gmt( sanitize_text_field( $data['date'] ) );
            $post_data['post_date_gmt'] = sanitize_text_field( $data['date'] );
        }

        if ( ! empty( $data['featured_media'] ) ) {
            $post_data['meta_input']['_thumbnail_id'] = (int) $data['featured_media'];
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return Response::error( 'post_create_failed', $post_id->get_error_message(), 500 );
        }

        self::apply_terms( $post_id, $data['terms'] ?? [] );
        self::apply_meta( $post_id, $data['meta'] ?? [] );
        self::apply_acf( $post_id, $data['acf'] ?? [] );

        if ( ! empty( $data['featured_media'] ) ) {
            set_post_thumbnail( $post_id, (int) $data['featured_media'] );
        }

        return Response::created( self::format_post( get_post( $post_id ), true ) );
    }

    public static function update_post( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'post_not_found', 'No post with that ID exists.', 404 );
        }

        $data      = $request->get_json_params() ?? [];
        $post_data = [ 'ID' => $id ];

        if ( isset( $data['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $data['title'] );
        }
        if ( isset( $data['content'] ) ) {
            $post_data['post_content'] = wp_kses_post( $data['content'] );
        }
        if ( isset( $data['excerpt'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $data['excerpt'] );
        }
        if ( isset( $data['status'] ) ) {
            $post_data['post_status'] = sanitize_key( $data['status'] );
        }
        if ( isset( $data['date'] ) ) {
            $post_data['post_date']     = get_date_from_gmt( sanitize_text_field( $data['date'] ) );
            $post_data['post_date_gmt'] = sanitize_text_field( $data['date'] );
        }

        $result = wp_update_post( $post_data, true );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'post_update_failed', $result->get_error_message(), 500 );
        }

        if ( isset( $data['terms'] ) ) {
            self::apply_terms( $id, $data['terms'] );
        }
        if ( isset( $data['meta'] ) ) {
            self::apply_meta( $id, $data['meta'] );
        }
        if ( isset( $data['acf'] ) ) {
            self::apply_acf( $id, $data['acf'] );
        }
        if ( isset( $data['featured_media'] ) ) {
            set_post_thumbnail( $id, (int) $data['featured_media'] );
        }

        return Response::success( self::format_post( get_post( $id ), true ) );
    }

    public static function delete_post( \WP_REST_Request $request ): \WP_REST_Response {
        $id    = (int) $request->get_param( 'id' );
        $post  = get_post( $id );
        $force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

        if ( ! $post ) {
            return Response::error( 'post_not_found', 'No post with that ID exists.', 404 );
        }

        $result = wp_delete_post( $id, $force );

        if ( ! $result ) {
            return Response::error( 'post_delete_failed', 'Failed to delete the post.', 500 );
        }

        return Response::success( [
            'deleted' => true,
            'force'   => $force,
            'id'      => $id,
        ] );
    }

    public static function duplicate_post( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'post_not_found', 'No post with that ID exists.', 404 );
        }

        $new_data = [
            'post_title'   => $post->post_title . ' (Copy)',
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => $post->post_type,
            'post_author'  => get_current_user_id(),
        ];

        $new_id = wp_insert_post( $new_data, true );

        if ( is_wp_error( $new_id ) ) {
            return Response::error( 'post_duplicate_failed', $new_id->get_error_message(), 500 );
        }

        // Copy all meta
        $all_meta = get_post_meta( $id );
        foreach ( $all_meta as $key => $values ) {
            foreach ( $values as $value ) {
                add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
            }
        }

        // Copy all terms
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, $taxonomy );
            }
        }

        return Response::created( self::format_post( get_post( $new_id ), true ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_post( \WP_Post $post, bool $full = false ): array {
        $data = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'post_type'      => $post->post_type,
            'date'           => $post->post_date_gmt,
            'modified'       => $post->post_modified_gmt,
            'featured_media' => (int) get_post_thumbnail_id( $post->ID ) ?: null,
            'permalink'      => get_permalink( $post->ID ),
        ];

        if ( $full ) {
            $data['content'] = $post->post_content;
            $data['excerpt'] = $post->post_excerpt;
            $data['meta']    = self::get_public_meta( $post->ID );
            $data['terms']   = self::get_all_terms( $post->ID, $post->post_type );
            $data['acf']     = self::get_acf_fields( $post->ID );
        }

        return $data;
    }

    private static function get_public_meta( int $post_id ): array {
        $all_meta = get_post_meta( $post_id );
        $public   = [];
        foreach ( $all_meta as $key => $values ) {
            if ( substr( $key, 0, 1 ) !== '_' ) {
                $public[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
            }
        }
        return $public;
    }

    private static function get_all_terms( int $post_id, string $post_type ): array {
        $result     = [];
        $taxonomies = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy );
            if ( ! is_wp_error( $terms ) ) {
                $result[ $taxonomy ] = array_map( fn( $t ) => [
                    'id'   => $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ], $terms );
            }
        }
        return $result;
    }

    private static function get_acf_fields( int $post_id ): array {
        if ( function_exists( 'get_fields' ) ) {
            return get_fields( $post_id ) ?: [];
        }
        return [];
    }

    private static function apply_terms( int $post_id, array $terms ): void {
        foreach ( $terms as $taxonomy => $slugs ) {
            if ( ! taxonomy_exists( sanitize_key( $taxonomy ) ) ) {
                continue;
            }
            $term_ids = [];
            foreach ( (array) $slugs as $slug ) {
                $term = get_term_by( 'slug', sanitize_text_field( $slug ), $taxonomy );
                if ( $term ) {
                    $term_ids[] = $term->term_id;
                }
            }
            wp_set_object_terms( $post_id, $term_ids, $taxonomy );
        }
    }

    private static function apply_meta( int $post_id, array $meta ): void {
        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, sanitize_key( $key ), $value );
        }
    }

    private static function apply_acf( int $post_id, array $acf ): void {
        if ( ! function_exists( 'update_field' ) || empty( $acf ) ) {
            return;
        }
        foreach ( $acf as $key => $value ) {
            update_field( $key, $value, $post_id );
        }
    }
}
