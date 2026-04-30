<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Response;

/**
 * Read-only user management routes.
 * Write operations are intentionally excluded.
 */
class UsersRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/users', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_users' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
                'args'                => [
                    'role'     => [ 'type' => 'string', 'default' => '' ],
                    'search'   => [ 'type' => 'string', 'default' => '' ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                    'orderby'  => [
                        'type'    => 'string',
                        'default' => 'registered',
                        'enum'    => [ 'ID', 'login', 'nicename', 'email', 'registered', 'display_name', 'post_count' ],
                    ],
                    'order'    => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/users/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_user' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/users/me', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_current_user' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );
    }

    public static function list_users( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'number'  => (int) $request->get_param( 'per_page' ),
            'offset'  => ( (int) $request->get_param( 'page' ) - 1 ) * (int) $request->get_param( 'per_page' ),
            'orderby' => $request->get_param( 'orderby' ),
            'order'   => $request->get_param( 'order' ),
            'count_total' => true,
        ];

        $role = $request->get_param( 'role' );
        if ( $role ) {
            $args['role'] = sanitize_text_field( $role );
        }

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['search']         = '*' . sanitize_text_field( $search ) . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }

        $query = new \WP_User_Query( $args );
        $users = array_map( [ self::class, 'format_user' ], $query->get_results() );
        $total = (int) $query->get_total();

        return Response::success( [
            'users'       => $users,
            'total'       => $total,
            'total_pages' => (int) ceil( $total / (int) $request->get_param( 'per_page' ) ),
        ] );
    }

    public static function get_user( \WP_REST_Request $request ): \WP_REST_Response {
        $user = get_user_by( 'id', (int) $request->get_param( 'id' ) );

        if ( ! $user ) {
            return Response::error( 'user_not_found', 'No user with that ID exists.', 404 );
        }

        return Response::success( self::format_user( $user, true ) );
    }

    public static function get_current_user( \WP_REST_Request $request ): \WP_REST_Response {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return Response::unauthorized();
        }

        return Response::success( self::format_user( $user, true ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_user( \WP_User $user, bool $full = false ): array {
        $data = [
            'id'           => $user->ID,
            'login'        => $user->user_login,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
            'url'          => $user->user_url,
        ];

        if ( $full ) {
            $data['first_name']   = $user->first_name;
            $data['last_name']    = $user->last_name;
            $data['description']  = $user->description;
            $data['capabilities'] = array_keys( array_filter( $user->allcaps ) );
            $data['meta']         = self::get_public_user_meta( $user->ID );
            $data['post_count']   = count_user_posts( $user->ID );
            $data['avatar_url']   = get_avatar_url( $user->ID );
        }

        return $data;
    }

    private static function get_public_user_meta( int $user_id ): array {
        $all_meta = get_user_meta( $user_id );
        $public   = [];
        foreach ( $all_meta as $key => $values ) {
            // Skip private/internal meta keys (prefixed with _ or wp_)
            if ( substr( $key, 0, 1 ) === '_' || substr( $key, 0, 3 ) === 'wp_' ) {
                continue;
            }
            $public[ $key ] = count( $values ) === 1
                ? maybe_unserialize( $values[0] )
                : array_map( 'maybe_unserialize', $values );
        }
        return $public;
    }
}
