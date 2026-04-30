<?php

namespace AgentBridge;

class Idempotency {

    const TABLE = 'agent_idempotency';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Check for a cached response for this idempotency key.
     * Returns the decoded response array or null if not found / expired.
     */
    public static function check( string $key, string $method, string $route ): ?array {
        global $wpdb;

        $table = self::table_name();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT response FROM {$table} WHERE idem_key = %s AND method = %s AND route = %s AND expires_at > UTC_TIMESTAMP()",
                $key,
                strtoupper( $method ),
                $route
            )
        );

        if ( ! $row ) {
            return null;
        }

        return json_decode( $row->response, true );
    }

    /**
     * Store a response for an idempotency key.
     */
    public static function store( string $key, string $method, string $route, array $response ): void {
        global $wpdb;

        $ttl     = defined( 'AGENT_BRIDGE_IDEMPOTENCY_TTL' ) ? (int) AGENT_BRIDGE_IDEMPOTENCY_TTL : 86400;
        $expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
        $table   = self::table_name();

        $wpdb->replace(
            $table,
            [
                'idem_key'   => $key,
                'method'     => strtoupper( $method ),
                'route'      => $route,
                'response'   => wp_json_encode( $response ),
                'expires_at' => $expires,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Wrap a REST route callback with idempotency handling.
     * Reads X-Idempotency-Key header; replays cached response if key exists.
     */
    public static function wrap( \WP_REST_Request $request, callable $handler ): \WP_REST_Response {
        $key = $request->get_header( 'X-Idempotency-Key' );

        if ( $key ) {
            $route  = $request->get_route();
            $method = $request->get_method();
            $cached = self::check( $key, $method, $route );

            if ( $cached !== null ) {
                $response = new \WP_REST_Response( $cached['body'], $cached['status'] );
                $response->header( 'X-Idempotency-Replay', 'true' );
                return $response;
            }
        }

        $response = $handler( $request );

        if ( $key && $response instanceof \WP_REST_Response ) {
            self::store(
                $key,
                $request->get_method(),
                $request->get_route(),
                [
                    'body'   => $response->get_data(),
                    'status' => $response->get_status(),
                ]
            );
        }

        return $response;
    }

    /**
     * Delete expired idempotency keys. Called by WP cron.
     */
    public static function cleanup(): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "DELETE FROM {$table} WHERE expires_at <= UTC_TIMESTAMP()" );
    }

    /**
     * Create the idempotency table on plugin activation.
     */
    public static function create_table(): void {
        global $wpdb;

        $table      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            idem_key    VARCHAR(128) NOT NULL UNIQUE,
            method      VARCHAR(10)  NOT NULL,
            route       VARCHAR(255) NOT NULL,
            response    LONGTEXT     NOT NULL,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME     NOT NULL,
            INDEX idx_expires (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
