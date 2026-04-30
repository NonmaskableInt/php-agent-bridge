<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class TaxonomyRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/taxonomies', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_taxonomies' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_terms' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
                'args'                => [
                    'search'     => [ 'type' => 'string', 'default' => '' ],
                    'parent'     => [ 'type' => 'integer', 'default' => 0 ],
                    'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
                    'per_page'   => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'create_term' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_term' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_term' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function list_taxonomies( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomies = get_taxonomies( [], 'objects' );
        $result     = [];

        foreach ( $taxonomies as $taxonomy ) {
            $result[] = [
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->label,
                'hierarchical' => $taxonomy->hierarchical,
                'public'       => $taxonomy->public,
                'object_type'  => $taxonomy->object_type,
            ];
        }

        return Response::success( $result );
    }

    public static function list_terms( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist.", 404 );
        }

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => (bool) $request->get_param( 'hide_empty' ),
            'number'     => (int) $request->get_param( 'per_page' ),
        ];

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['search'] = sanitize_text_field( $search );
        }

        $parent = (int) $request->get_param( 'parent' );
        if ( $parent > 0 ) {
            $args['parent'] = $parent;
        }

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return Response::error( 'term_fetch_failed', $terms->get_error_message(), 500 );
        }

        return Response::success( array_map( [ self::class, 'format_term' ], $terms ) );
    }

    public static function create_term( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist.", 404 );
        }

        $data = $request->get_json_params() ?? [];

        if ( empty( $data['name'] ) ) {
            return Response::validation_error( 'Term name is required.' );
        }

        $args = [];
        if ( ! empty( $data['slug'] ) ) {
            $args['slug'] = sanitize_title( $data['slug'] );
        }
        if ( ! empty( $data['parent'] ) ) {
            $args['parent'] = (int) $data['parent'];
        }
        if ( ! empty( $data['description'] ) ) {
            $args['description'] = sanitize_text_field( $data['description'] );
        }

        $result = wp_insert_term( sanitize_text_field( $data['name'] ), $taxonomy, $args );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'term_create_failed', $result->get_error_message(), 500 );
        }

        $term = get_term( $result['term_id'], $taxonomy );
        return Response::created( self::format_term( $term ) );
    }

    public static function update_term( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
        $id       = (int) $request->get_param( 'id' );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist.", 404 );
        }

        $term = get_term( $id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return Response::error( 'term_not_found', 'No term with that ID exists in this taxonomy.', 404 );
        }

        $data = $request->get_json_params() ?? [];
        $args = [];

        if ( isset( $data['name'] ) ) {
            $args['name'] = sanitize_text_field( $data['name'] );
        }
        if ( isset( $data['slug'] ) ) {
            $args['slug'] = sanitize_title( $data['slug'] );
        }
        if ( isset( $data['parent'] ) ) {
            $args['parent'] = (int) $data['parent'];
        }
        if ( isset( $data['description'] ) ) {
            $args['description'] = sanitize_text_field( $data['description'] );
        }

        $result = wp_update_term( $id, $taxonomy, $args );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'term_update_failed', $result->get_error_message(), 500 );
        }

        return Response::success( self::format_term( get_term( $id, $taxonomy ) ) );
    }

    public static function delete_term( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
        $id       = (int) $request->get_param( 'id' );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist.", 404 );
        }

        $term = get_term( $id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return Response::error( 'term_not_found', 'No term with that ID exists in this taxonomy.', 404 );
        }

        $result = wp_delete_term( $id, $taxonomy );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'term_delete_failed', $result->get_error_message(), 500 );
        }

        return Response::success( [ 'deleted' => true, 'id' => $id, 'taxonomy' => $taxonomy ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_term( \WP_Term $term ): array {
        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
        ];
    }
}
