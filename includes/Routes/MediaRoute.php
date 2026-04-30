<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Idempotency;
use AgentBridge\Response;

class MediaRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/media', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_media' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
                'args'                => [
                    'mime_type'   => [ 'type' => 'string', 'default' => '' ],
                    'search'      => [ 'type' => 'string', 'default' => '' ],
                    'parent_post' => [ 'type' => 'integer', 'default' => 0 ],
                    'per_page'    => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'page'        => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/upload', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'upload_media' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_media' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'update_media' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'delete_media' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)/replace', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'replace_media' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)/regenerate', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'regenerate_media' ] ),
                'permission_callback' => [ Auth::class, 'require_publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)/sizes', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_sizes' ],
                'permission_callback' => [ Auth::class, 'require_edit' ],
            ],
        ] );
    }

    public static function list_media( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => (int) $request->get_param( 'per_page' ),
            'paged'          => (int) $request->get_param( 'page' ),
        ];

        $mime = $request->get_param( 'mime_type' );
        if ( $mime ) {
            $args['post_mime_type'] = sanitize_text_field( $mime );
        }

        $search = $request->get_param( 'search' );
        if ( $search ) {
            $args['s'] = sanitize_text_field( $search );
        }

        $parent = (int) $request->get_param( 'parent_post' );
        if ( $parent > 0 ) {
            $args['post_parent'] = $parent;
        }

        $query = new \WP_Query( $args );
        $items = array_map( [ self::class, 'format_attachment' ], $query->posts );

        return Response::success( [
            'media'       => $items,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    public static function get_media( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        return Response::success( self::format_attachment( $post, true ) );
    }

    public static function upload_media( \WP_REST_Request $request ): \WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $files = $request->get_file_params();
        $json  = $request->get_json_params();

        if ( ! empty( $files['file'] ) ) {
            return self::handle_multipart_upload( $files['file'], $request );
        }

        if ( ! empty( $json['data'] ) ) {
            return self::handle_base64_upload( $json, $request );
        }

        return Response::validation_error( 'Provide either a multipart file field or a base64 JSON body.' );
    }

    public static function update_media( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        $data      = $request->get_json_params() ?? [];
        $post_data = [ 'ID' => $id ];

        if ( isset( $data['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $data['title'] );
        }
        if ( isset( $data['caption'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $data['caption'] );
        }
        if ( isset( $data['description'] ) ) {
            $post_data['post_content'] = sanitize_text_field( $data['description'] );
        }

        wp_update_post( $post_data );

        if ( isset( $data['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt'] ) );
        }

        return Response::success( self::format_attachment( get_post( $id ), true ) );
    }

    public static function replace_media( \WP_REST_Request $request ): \WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        $files = $request->get_file_params();
        $json  = $request->get_json_params();

        if ( ! empty( $files['file'] ) ) {
            $tmp_file = $files['file']['tmp_name'];
            $filename = $files['file']['name'];
            $mime     = $files['file']['type'];
        } elseif ( ! empty( $json['data'] ) ) {
            $decoded  = base64_decode( $json['data'], true );
            $filename = sanitize_file_name( $json['filename'] ?? 'upload' );
            $mime     = $json['mime_type'] ?? 'application/octet-stream';

            if ( $decoded === false ) {
                return Response::validation_error( 'Invalid base64 data.' );
            }

            $tmp_file = wp_tempnam( $filename );
            file_put_contents( $tmp_file, $decoded );
        } else {
            return Response::validation_error( 'Provide either a multipart file field or a base64 JSON body.' );
        }

        // Get original file path
        $upload_dir   = wp_upload_dir();
        $original_rel = get_post_meta( $id, '_wp_attached_file', true );
        $original_abs = trailingslashit( $upload_dir['basedir'] ) . $original_rel;

        $original_mime = get_post_mime_type( $id );
        if ( $mime !== $original_mime ) {
            // Warn but continue
            do_action( 'agent_bridge_media_replace_mime_mismatch', $id, $original_mime, $mime );
        }

        // Overwrite original file
        if ( ! copy( $tmp_file, $original_abs ) ) {
            return Response::error( 'media_upload_failed', 'Could not overwrite original file.', 500 );
        }

        // Regenerate metadata
        $metadata = wp_generate_attachment_metadata( $id, $original_abs );
        wp_update_attachment_metadata( $id, $metadata );

        // Update the file reference
        update_post_meta( $id, '_wp_attached_file', $original_rel );

        // Clean up temp file if we created one
        if ( ! empty( $json['data'] ) ) {
            @unlink( $tmp_file );
        }

        return Response::success( self::format_attachment( get_post( $id ), true ) );
    }

    public static function regenerate_media( \WP_REST_Request $request ): \WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        $file     = get_attached_file( $id );
        $metadata = wp_generate_attachment_metadata( $id, $file );

        if ( is_wp_error( $metadata ) ) {
            return Response::error( 'media_regenerate_failed', $metadata->get_error_message(), 500 );
        }

        wp_update_attachment_metadata( $id, $metadata );

        return Response::success( self::format_attachment( get_post( $id ), true ) );
    }

    public static function delete_media( \WP_REST_Request $request ): \WP_REST_Response {
        $id    = (int) $request->get_param( 'id' );
        $post  = get_post( $id );
        $force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        if ( ! $force ) {
            return Response::validation_error( 'Media deletion requires ?force=true.' );
        }

        $result = wp_delete_attachment( $id, true );

        if ( ! $result ) {
            return Response::error( 'media_delete_failed', 'Failed to delete the attachment.', 500 );
        }

        return Response::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function get_sizes( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return Response::error( 'media_not_found', 'No attachment with that ID exists.', 404 );
        }

        $metadata  = wp_get_attachment_metadata( $id );
        $upload_dir = wp_upload_dir();
        $base_url  = trailingslashit( $upload_dir['baseurl'] );
        $dir       = '';

        if ( ! empty( $metadata['file'] ) ) {
            $dir = dirname( $metadata['file'] ) . '/';
        }

        $sizes = [
            'full' => [
                'url'    => wp_get_attachment_url( $id ),
                'width'  => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
            ],
        ];

        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $sizes[ $size_name ] = [
                    'url'    => $base_url . $dir . $size_data['file'],
                    'width'  => $size_data['width'],
                    'height' => $size_data['height'],
                ];
            }
        }

        return Response::success( [ 'id' => $id, 'sizes' => $sizes ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function handle_multipart_upload( array $file_data, \WP_REST_Request $request ): \WP_REST_Response {
        $overrides = [ 'test_form' => false ];
        $file      = wp_handle_upload( $file_data, $overrides );

        if ( isset( $file['error'] ) ) {
            return Response::error( 'media_upload_failed', $file['error'], 500 );
        }

        $attachment_id = self::create_attachment_from_file( $file['file'], $file['url'], $file['type'], $request );

        if ( is_wp_error( $attachment_id ) ) {
            return Response::error( 'media_upload_failed', $attachment_id->get_error_message(), 500 );
        }

        return Response::created( self::format_attachment( get_post( $attachment_id ), true ) );
    }

    private static function handle_base64_upload( array $json, \WP_REST_Request $request ): \WP_REST_Response {
        $decoded  = base64_decode( $json['data'], true );
        $filename = sanitize_file_name( $json['filename'] ?? 'upload' );
        $mime     = $json['mime_type'] ?? 'application/octet-stream';

        if ( $decoded === false ) {
            return Response::validation_error( 'Invalid base64 data.' );
        }

        $upload = wp_upload_bits( $filename, null, $decoded );

        if ( ! empty( $upload['error'] ) ) {
            return Response::error( 'media_upload_failed', $upload['error'], 500 );
        }

        $attachment_id = self::create_attachment_from_file( $upload['file'], $upload['url'], $mime, $request );

        if ( is_wp_error( $attachment_id ) ) {
            return Response::error( 'media_upload_failed', $attachment_id->get_error_message(), 500 );
        }

        return Response::created( self::format_attachment( get_post( $attachment_id ), true ) );
    }

    private static function create_attachment_from_file( string $file_path, string $url, string $mime, \WP_REST_Request $request ): int|\WP_Error {
        $params    = $request->get_params();
        $parent_id = (int) ( $params['post_parent'] ?? 0 );

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
            'post_excerpt'   => sanitize_text_field( $params['caption'] ?? '' ),
            'post_status'    => 'inherit',
            'post_parent'    => $parent_id,
        ];

        $attachment_id = wp_insert_attachment( $attachment, $file_path, $parent_id );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $alt = sanitize_text_field( $params['alt'] ?? '' );
        if ( $alt ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }

        return $attachment_id;
    }

    private static function format_attachment( \WP_Post $post, bool $full = false ): array {
        $data = [
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'url'       => wp_get_attachment_url( $post->ID ),
            'mime_type' => $post->post_mime_type,
            'date'      => $post->post_date_gmt,
        ];

        if ( $full ) {
            $data['alt']         = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
            $data['caption']     = $post->post_excerpt;
            $data['description'] = $post->post_content;
            $data['sizes']       = [];

            $metadata = wp_get_attachment_metadata( $post->ID );
            if ( ! empty( $metadata['sizes'] ) ) {
                $upload_dir = wp_upload_dir();
                $dir        = dirname( $metadata['file'] ?? '' ) . '/';
                foreach ( $metadata['sizes'] as $name => $size ) {
                    $data['sizes'][ $name ] = [
                        'url'    => trailingslashit( $upload_dir['baseurl'] ) . $dir . $size['file'],
                        'width'  => $size['width'],
                        'height' => $size['height'],
                    ];
                }
            }

            $data['sizes']['full'] = [
                'url'    => wp_get_attachment_url( $post->ID ),
                'width'  => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
            ];
        }

        return $data;
    }
}
