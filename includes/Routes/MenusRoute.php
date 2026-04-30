<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class MenusRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/menus', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_menus' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_menu' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/menus/(?P<id>\d+)/items', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'add_menu_item' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/menus/items/(?P<item_id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_menu_item' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_menu_item' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function list_menus( \WP_REST_Request $request ): \WP_REST_Response {
        $menus     = wp_get_nav_menus();
        $locations = get_registered_nav_menus();
        $assigned  = get_nav_menu_locations();

        // Build a lookup: menu_id => [location_slugs]
        $menu_locations = [];
        foreach ( $assigned as $location => $menu_id ) {
            if ( $menu_id ) {
                $menu_locations[ $menu_id ][] = $location;
            }
        }

        $result = [];
        foreach ( $menus as $menu ) {
            $result[] = [
                'id'        => $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'count'     => $menu->count,
                'locations' => $menu_locations[ $menu->term_id ] ?? [],
            ];
        }

        return Response::success( [
            'menus'              => $result,
            'registered_locations' => array_keys( $locations ),
        ] );
    }

    public static function get_menu( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $menu = wp_get_nav_menu_object( $id );

        if ( ! $menu ) {
            return Response::error( 'menu_not_found', 'No menu with that ID exists.', 404 );
        }

        $items = wp_get_nav_menu_items( $id );
        $tree  = $items ? self::build_item_tree( $items ) : [];

        return Response::success( [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'items' => $tree,
        ] );
    }

    public static function add_menu_item( \WP_REST_Request $request ): \WP_REST_Response {
        $menu_id = (int) $request->get_param( 'id' );
        $menu    = wp_get_nav_menu_object( $menu_id );

        if ( ! $menu ) {
            return Response::error( 'menu_not_found', 'No menu with that ID exists.', 404 );
        }

        $data = $request->get_json_params() ?? [];
        $type = sanitize_text_field( $data['type'] ?? 'custom' );

        $item_data = [
            'menu-item-status'    => 'publish',
            'menu-item-type'      => $type,
            'menu-item-title'     => sanitize_text_field( $data['title'] ?? '' ),
            'menu-item-url'       => esc_url_raw( $data['url'] ?? '' ),
            'menu-item-parent-id' => (int) ( $data['parent_item_id'] ?? 0 ),
            'menu-item-position'  => (int) ( $data['position'] ?? 0 ),
        ];

        if ( $type === 'post_type' || $type === 'taxonomy' ) {
            $item_data['menu-item-object-id'] = (int) ( $data['object_id'] ?? 0 );
            $item_data['menu-item-object']    = sanitize_text_field( $data['object'] ?? '' );
        }

        $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );

        if ( is_wp_error( $item_id ) ) {
            return Response::error( 'menu_item_create_failed', $item_id->get_error_message(), 500 );
        }

        return Response::created( self::format_item( get_post( $item_id ) ) );
    }

    public static function update_menu_item( \WP_REST_Request $request ): \WP_REST_Response {
        $item_id = (int) $request->get_param( 'item_id' );
        $item    = get_post( $item_id );

        if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
            return Response::error( 'menu_item_not_found', 'No menu item with that ID exists.', 404 );
        }

        $data      = $request->get_json_params() ?? [];
        $item_data = [];

        if ( isset( $data['title'] ) ) {
            $item_data['menu-item-title'] = sanitize_text_field( $data['title'] );
        }
        if ( isset( $data['url'] ) ) {
            $item_data['menu-item-url'] = esc_url_raw( $data['url'] );
        }
        if ( isset( $data['target'] ) ) {
            $item_data['menu-item-target'] = sanitize_text_field( $data['target'] );
        }
        if ( isset( $data['parent_item_id'] ) ) {
            $item_data['menu-item-parent-id'] = (int) $data['parent_item_id'];
        }
        if ( isset( $data['position'] ) ) {
            $item_data['menu-item-position'] = (int) $data['position'];
        }

        // Get the menu this item belongs to
        $menus = wp_get_post_terms( $item_id, 'nav_menu' );
        if ( empty( $menus ) ) {
            return Response::error( 'menu_item_orphaned', 'Menu item is not attached to any menu.', 500 );
        }

        $menu_id = $menus[0]->term_id;
        $result  = wp_update_nav_menu_item( $menu_id, $item_id, $item_data );

        if ( is_wp_error( $result ) ) {
            return Response::error( 'menu_item_update_failed', $result->get_error_message(), 500 );
        }

        return Response::success( self::format_item( get_post( $item_id ) ) );
    }

    public static function delete_menu_item( \WP_REST_Request $request ): \WP_REST_Response {
        $item_id = (int) $request->get_param( 'item_id' );
        $item    = get_post( $item_id );

        if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
            return Response::error( 'menu_item_not_found', 'No menu item with that ID exists.', 404 );
        }

        $result = wp_delete_post( $item_id, true );

        if ( ! $result ) {
            return Response::error( 'menu_item_delete_failed', 'Failed to delete menu item.', 500 );
        }

        return Response::success( [ 'deleted' => true, 'item_id' => $item_id ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function build_item_tree( array $items ): array {
        $map      = [];
        $children = [];

        foreach ( $items as $item ) {
            $map[ $item->ID ] = self::format_item( $item );
            $parent           = (int) $item->menu_item_parent;
            $children[ $parent ][] = $item->ID;
        }

        $build = function( int $parent_id ) use ( &$build, &$map, &$children ): array {
            $result = [];
            foreach ( $children[ $parent_id ] ?? [] as $id ) {
                $node             = $map[ $id ];
                $node['children'] = $build( $id );
                $result[]         = $node;
            }
            return $result;
        };

        return $build( 0 );
    }

    private static function format_item( \WP_Post $item ): array {
        return [
            'id'        => $item->ID,
            'title'     => $item->post_title,
            'url'       => get_post_meta( $item->ID, '_menu_item_url', true ),
            'type'      => get_post_meta( $item->ID, '_menu_item_type', true ),
            'object'    => get_post_meta( $item->ID, '_menu_item_object', true ),
            'object_id' => (int) get_post_meta( $item->ID, '_menu_item_object_id', true ),
            'parent'    => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ),
            'position'  => (int) $item->menu_order,
            'target'    => get_post_meta( $item->ID, '_menu_item_target', true ),
        ];
    }
}
