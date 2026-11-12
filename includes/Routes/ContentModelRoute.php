<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\ContentModel\Model;
use AgentBridge\Files\Backups;
use AgentBridge\Idempotency;
use AgentBridge\Response;

/**
 * Define custom post types, taxonomies and meta fields as data. They are registered on every
 * request with show_in_rest forced on, so a headless front end can use them immediately via
 * /wp-json/wp/v2/{rest_base}. No PHP files are written, so no AGENT_BRIDGE_ALLOW_FILE_WRITE needed.
 */
class ContentModelRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/content-model', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_model' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        foreach ( [ 'post-types' => 'post_types', 'taxonomies' => 'taxonomies', 'meta' => 'meta' ] as $path => $kind ) {
            register_rest_route( self::NAMESPACE, "/content-model/{$path}/(?P<id>[A-Za-z0-9_-]+)", [
                [
                    'methods'             => 'POST',
                    'callback'            => fn( $r ) => Idempotency::wrap( $r, fn( $req ) => self::upsert( $req, $kind ) ),
                    'permission_callback' => [ Auth::class, 'require_manage' ],
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => fn( $r ) => Idempotency::wrap( $r, fn( $req ) => self::remove( $req, $kind ) ),
                    'permission_callback' => [ Auth::class, 'require_manage' ],
                ],
            ] );
        }
    }

    public static function get_model(): \WP_REST_Response {
        $model = Model::get();
        return Response::success( [
            'post_types' => (object) $model['post_types'],
            'taxonomies' => (object) $model['taxonomies'],
            'meta'       => (object) $model['meta'],
            'warnings'   => Model::warnings( $model ),
        ] );
    }

    private static function upsert( \WP_REST_Request $request, string $kind ): \WP_REST_Response {
        $id    = (string) $request->get_param( 'id' );
        $input = $request->get_json_params();
        $input = is_array( $input ) ? $input : [];

        $result = $kind === 'post_types' ? Model::set_type( $id, $input )
            : ( $kind === 'taxonomies' ? Model::set_taxonomy( $id, $input ) : Model::set_meta( $id, $input ) );

        if ( ! $result['ok'] ) {
            return Response::error( $result['code'], $result['message'], $result['status'] );
        }
        Backups::audit( WP_CONTENT_DIR, 'content_model_set', "{$kind}:{$id}", [ 'created' => $result['created'] ] );

        $body = [ 'id' => $id, 'created' => $result['created'], 'definition' => $result['definition'] ];
        return $result['created'] ? Response::created( $body ) : Response::success( $body );
    }

    private static function remove( \WP_REST_Request $request, string $kind ): \WP_REST_Response {
        $id       = (string) $request->get_param( 'id' );
        $orphaned = $kind === 'post_types' ? Model::orphaned_posts( $id ) : 0;

        $result = Model::delete( $kind, $id );
        if ( ! $result['ok'] ) {
            return Response::error( $result['code'], $result['message'], $result['status'] );
        }
        Backups::audit( WP_CONTENT_DIR, 'content_model_delete', "{$kind}:{$id}", [ 'orphaned_posts' => $orphaned ] );

        $body = [ 'id' => $id, 'deleted' => true ];
        if ( $kind === 'post_types' ) {
            // Only the definition is removed; posts stay in the database (hidden) and return if it is re-added.
            $body['orphaned_posts'] = $orphaned;
        }
        return Response::success( $body );
    }
}
