<?php

namespace AgentBridge\Modules;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

/**
 * WooCommerce module — loaded automatically when WooCommerce is active.
 * Exposes order and product management routes under /agent/v1/wc/.
 */
class WooCommerce {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        // Orders
        register_rest_route( self::NAMESPACE, '/wc/orders', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_orders' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
                'args'                => [
                    'status'   => [ 'type' => 'string', 'default' => 'any' ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/wc/orders/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_order' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_order' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        // Products
        register_rest_route( self::NAMESPACE, '/wc/products', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_products' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
                'args'                => [
                    'status'   => [ 'type' => 'string', 'default' => 'publish' ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                    'search'   => [ 'type' => 'string', 'default' => '' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'create_product' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/wc/products/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_product' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_product' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_product' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public static function list_orders( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'limit'    => (int) $request->get_param( 'per_page' ),
            'page'     => (int) $request->get_param( 'page' ),
            'status'   => sanitize_text_field( $request->get_param( 'status' ) ),
            'paginate' => true,
        ];

        $results = wc_get_orders( $args );

        return Response::success( [
            'orders'      => array_map( [ self::class, 'format_order' ], $results->orders ),
            'total'       => $results->total,
            'total_pages' => $results->max_num_pages,
        ] );
    }

    public static function get_order( \WP_REST_Request $request ): \WP_REST_Response {
        $order = wc_get_order( (int) $request->get_param( 'id' ) );

        if ( ! $order ) {
            return Response::error( 'order_not_found', 'No order with that ID exists.', 404 );
        }

        return Response::success( self::format_order( $order, true ) );
    }

    public static function update_order( \WP_REST_Request $request ): \WP_REST_Response {
        $order = wc_get_order( (int) $request->get_param( 'id' ) );

        if ( ! $order ) {
            return Response::error( 'order_not_found', 'No order with that ID exists.', 404 );
        }

        $data = $request->get_json_params() ?? [];

        if ( isset( $data['status'] ) ) {
            $order->update_status( sanitize_text_field( $data['status'] ), $data['note'] ?? '' );
        }

        if ( isset( $data['note'] ) && ! isset( $data['status'] ) ) {
            $order->add_order_note( sanitize_text_field( $data['note'] ) );
        }

        $order->save();

        return Response::success( self::format_order( $order, true ) );
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    public static function list_products( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => 'product',
            'post_status'    => sanitize_key( $request->get_param( 'status' ) ),
            'posts_per_page' => (int) $request->get_param( 'per_page' ),
            'paged'          => (int) $request->get_param( 'page' ),
        ];

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['s'] = sanitize_text_field( $search );
        }

        $query    = new \WP_Query( $args );
        $products = [];
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $products[] = self::format_product( $product );
            }
        }

        return Response::success( [
            'products'    => $products,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    public static function get_product( \WP_REST_Request $request ): \WP_REST_Response {
        $product = wc_get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return Response::error( 'product_not_found', 'No product with that ID exists.', 404 );
        }

        return Response::success( self::format_product( $product, true ) );
    }

    public static function create_product( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params() ?? [];

        if ( empty( $data['name'] ) ) {
            return Response::validation_error( 'Product name is required.' );
        }

        $product = new \WC_Product_Simple();
        self::apply_product_data( $product, $data );
        $product->save();

        return Response::created( self::format_product( $product, true ) );
    }

    public static function update_product( \WP_REST_Request $request ): \WP_REST_Response {
        $product = wc_get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return Response::error( 'product_not_found', 'No product with that ID exists.', 404 );
        }

        $data = $request->get_json_params() ?? [];
        self::apply_product_data( $product, $data );
        $product->save();

        return Response::success( self::format_product( $product, true ) );
    }

    public static function delete_product( \WP_REST_Request $request ): \WP_REST_Response {
        $product = wc_get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return Response::error( 'product_not_found', 'No product with that ID exists.', 404 );
        }

        $force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
        $product->delete( $force );

        return Response::success( [ 'deleted' => true, 'id' => (int) $request->get_param( 'id' ) ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_order( \WC_Order $order, bool $full = false ): array {
        $data = [
            'id'         => $order->get_id(),
            'status'     => $order->get_status(),
            'total'      => $order->get_total(),
            'currency'   => $order->get_currency(),
            'date'       => $order->get_date_created()?->format( 'c' ),
            'customer'   => [
                'id'    => $order->get_customer_id(),
                'email' => $order->get_billing_email(),
                'name'  => $order->get_formatted_billing_full_name(),
            ],
        ];

        if ( $full ) {
            $items = [];
            foreach ( $order->get_items() as $item ) {
                $items[] = [
                    'product_id' => $item->get_product_id(),
                    'name'       => $item->get_name(),
                    'quantity'   => $item->get_quantity(),
                    'total'      => $item->get_total(),
                ];
            }
            $data['items']    = $items;
            $data['shipping'] = $order->get_formatted_shipping_full_name();
            $data['notes']    = array_map(
                fn( $note ) => [ 'note' => $note->comment_content, 'date' => $note->comment_date_gmt ],
                wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
            );
        }

        return $data;
    }

    private static function format_product( \WC_Product $product, bool $full = false ): array {
        $data = [
            'id'         => $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => $product->get_price(),
            'status'     => $product->get_status(),
            'type'       => $product->get_type(),
            'permalink'  => get_permalink( $product->get_id() ),
            'stock'      => $product->get_stock_quantity(),
            'in_stock'   => $product->is_in_stock(),
        ];

        if ( $full ) {
            $data['description']       = $product->get_description();
            $data['short_description'] = $product->get_short_description();
            $data['regular_price']     = $product->get_regular_price();
            $data['sale_price']        = $product->get_sale_price();
            $data['categories']        = wp_get_object_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
            $data['tags']              = wp_get_object_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );
        }

        return $data;
    }

    private static function apply_product_data( \WC_Product $product, array $data ): void {
        if ( isset( $data['name'] ) ) {
            $product->set_name( sanitize_text_field( $data['name'] ) );
        }
        if ( isset( $data['description'] ) ) {
            $product->set_description( wp_kses_post( $data['description'] ) );
        }
        if ( isset( $data['short_description'] ) ) {
            $product->set_short_description( wp_kses_post( $data['short_description'] ) );
        }
        if ( isset( $data['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $data['sku'] ) );
        }
        if ( isset( $data['regular_price'] ) ) {
            $product->set_regular_price( wc_format_decimal( $data['regular_price'] ) );
        }
        if ( isset( $data['sale_price'] ) ) {
            $product->set_sale_price( wc_format_decimal( $data['sale_price'] ) );
        }
        if ( isset( $data['status'] ) ) {
            $product->set_status( sanitize_key( $data['status'] ) );
        }
        if ( isset( $data['stock_quantity'] ) ) {
            $product->set_stock_quantity( (int) $data['stock_quantity'] );
            $product->set_manage_stock( true );
        }
        if ( isset( $data['featured_media'] ) ) {
            $product->set_image_id( (int) $data['featured_media'] );
        }
    }
}
