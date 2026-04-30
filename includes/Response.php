<?php

namespace AgentBridge;

class Response {

    public static function success( $data, string $idempotency_key = null ): \WP_REST_Response {
        $body = [
            'ok'   => true,
            'data' => $data,
        ];

        if ( $idempotency_key !== null ) {
            $body['idempotency_key'] = $idempotency_key;
        }

        return new \WP_REST_Response( $body, 200 );
    }

    public static function created( $data, string $idempotency_key = null ): \WP_REST_Response {
        $body = [
            'ok'   => true,
            'data' => $data,
        ];

        if ( $idempotency_key !== null ) {
            $body['idempotency_key'] = $idempotency_key;
        }

        return new \WP_REST_Response( $body, 201 );
    }

    public static function error( string $code, string $message, int $status = 400 ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'ok'      => false,
                'code'    => $code,
                'message' => $message,
                'data'    => [ 'status' => $status ],
            ],
            $status
        );
    }

    public static function not_found( string $message = 'Resource not found.' ): \WP_REST_Response {
        return self::error( 'not_found', $message, 404 );
    }

    public static function forbidden( string $code = 'insufficient_capability', string $message = 'You do not have permission to perform this action.' ): \WP_REST_Response {
        return self::error( $code, $message, 403 );
    }

    public static function unauthorized(): \WP_REST_Response {
        return self::error( 'auth_required', 'Missing or invalid credentials.', 401 );
    }

    public static function validation_error( string $message ): \WP_REST_Response {
        return self::error( 'validation_error', $message, 422 );
    }
}
