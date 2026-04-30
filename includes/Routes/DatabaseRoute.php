<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Response;
use AgentBridge\Idempotency;

/**
 * Direct database access via the agent API.
 *
 * DISABLED by default for safety. Enable by defining:
 *   define( 'AGENT_BRIDGE_DB_API', true );
 *
 * DDL statements (DROP, TRUNCATE, ALTER, CREATE) are blocked unless:
 *   define( 'AGENT_BRIDGE_DB_ALLOW_DDL', true );
 *
 * All write queries require `X-Idempotency-Key`.
 * All endpoints require `manage_options`.
 */
class DatabaseRoute {

    const NAMESPACE = 'agent/v1';

    /**
     * Statements always blocked regardless of DDL flag.
     * These are catastrophic and should never be automated.
     */
    private const ALWAYS_BLOCKED = [
        'DROP DATABASE',
        'DROP SCHEMA',
    ];

    /**
     * Statements blocked unless AGENT_BRIDGE_DB_ALLOW_DDL is true.
     */
    private const DDL_BLOCKED = [
        'DROP TABLE',
        'TRUNCATE',
        'ALTER TABLE',
        'CREATE TABLE',
        'RENAME TABLE',
    ];

    public static function register(): void {
        if ( ! defined( 'AGENT_BRIDGE_DB_API' ) || ! AGENT_BRIDGE_DB_API ) {
            return;
        }

        // List all tables
        register_rest_route( self::NAMESPACE, '/db/tables', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_tables' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        // Get schema for a specific table
        register_rest_route( self::NAMESPACE, '/db/schema', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_schema' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
                'args'                => [
                    'table' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
        ] );

        // Run a SELECT query (read-only)
        register_rest_route( self::NAMESPACE, '/db/select', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'run_select' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );

        // Run a write query (INSERT / UPDATE / DELETE)
        register_rest_route( self::NAMESPACE, '/db/query', [
            [
                'methods'             => 'POST',
                'callback'            => fn( $r ) => Idempotency::wrap( $r, [ self::class, 'run_query' ] ),
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public static function list_tables( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $tables = $wpdb->get_col( 'SHOW TABLES' );

        if ( $tables === null ) {
            return Response::error( 'db_error', $wpdb->last_error ?: 'Unknown database error.', 500 );
        }

        return Response::success( [ 'tables' => $tables ] );
    }

    public static function get_schema( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $table = $request->get_param( 'table' );

        if ( ! self::table_exists( $table ) ) {
            return Response::not_found( "Table '{$table}' does not exist." );
        }

        // SHOW COLUMNS is safe — table name validated against existence check above
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$wpdb->_real_escape( $table )}`", ARRAY_A );

        if ( $columns === null ) {
            return Response::error( 'db_error', $wpdb->last_error ?: 'Unknown database error.', 500 );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$wpdb->_real_escape( $table )}`", ARRAY_A );

        return Response::success( [
            'table'   => $table,
            'columns' => $columns,
            'indexes' => $indexes ?: [],
        ] );
    }

    public static function run_select( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $data   = $request->get_json_params() ?? [];
        $sql    = trim( $data['sql'] ?? '' );
        $params = $data['params'] ?? [];

        if ( ! $sql ) {
            return Response::validation_error( '`sql` is required.' );
        }

        $upper = strtoupper( ltrim( $sql ) );
        if ( ! str_starts_with( $upper, 'SELECT' ) && ! str_starts_with( $upper, 'SHOW' ) && ! str_starts_with( $upper, 'EXPLAIN' ) ) {
            return Response::error( 'select_only', 'Only SELECT, SHOW, and EXPLAIN are allowed on this endpoint. Use /db/query for writes.', 400 );
        }

        $error = self::check_statement( $sql );
        if ( $error ) {
            return Response::error( 'statement_blocked', $error, 403 );
        }

        $prepared = self::prepare_query( $sql, $params );
        if ( is_wp_error( $prepared ) ) {
            return Response::validation_error( $prepared->get_error_message() );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        if ( $rows === null && $wpdb->last_error ) {
            return Response::error( 'db_error', $wpdb->last_error, 500 );
        }

        return Response::success( [
            'rows'      => $rows ?: [],
            'row_count' => count( $rows ?: [] ),
        ] );
    }

    public static function run_query( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $data   = $request->get_json_params() ?? [];
        $sql    = trim( $data['sql'] ?? '' );
        $params = $data['params'] ?? [];

        if ( ! $sql ) {
            return Response::validation_error( '`sql` is required.' );
        }

        $upper = strtoupper( ltrim( $sql ) );
        if ( str_starts_with( $upper, 'SELECT' ) || str_starts_with( $upper, 'SHOW' ) || str_starts_with( $upper, 'EXPLAIN' ) ) {
            return Response::error( 'use_select_endpoint', 'Use /db/select for read queries.', 400 );
        }

        $error = self::check_statement( $sql );
        if ( $error ) {
            return Response::error( 'statement_blocked', $error, 403 );
        }

        $prepared = self::prepare_query( $sql, $params );
        if ( is_wp_error( $prepared ) ) {
            return Response::validation_error( $prepared->get_error_message() );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query( $prepared );

        if ( $result === false ) {
            return Response::error( 'db_error', $wpdb->last_error ?: 'Query failed.', 500 );
        }

        return Response::success( [
            'rows_affected' => (int) $result,
            'insert_id'     => (int) $wpdb->insert_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate a SQL statement against blocked patterns.
     * Returns an error message string, or null if allowed.
     */
    private static function check_statement( string $sql ): ?string {
        $upper = strtoupper( $sql );

        foreach ( self::ALWAYS_BLOCKED as $blocked ) {
            if ( str_contains( $upper, $blocked ) ) {
                return "Statement contains blocked keyword: {$blocked}";
            }
        }

        $allow_ddl = defined( 'AGENT_BRIDGE_DB_ALLOW_DDL' ) && AGENT_BRIDGE_DB_ALLOW_DDL;
        if ( ! $allow_ddl ) {
            foreach ( self::DDL_BLOCKED as $blocked ) {
                if ( str_contains( $upper, $blocked ) ) {
                    return "DDL statement '{$blocked}' is blocked. Set AGENT_BRIDGE_DB_ALLOW_DDL=true to enable.";
                }
            }
        }

        return null;
    }

    /**
     * Prepare a query with positional `%s` / `%d` / `%f` placeholders.
     * If $params is empty the raw $sql is returned as-is (caller must ensure safety).
     *
     * @return string|\WP_Error
     */
    private static function prepare_query( string $sql, array $params ) {
        global $wpdb;

        if ( empty( $params ) ) {
            return $sql;
        }

        // Validate param count matches placeholder count
        $placeholder_count = substr_count( $sql, '%s' ) + substr_count( $sql, '%d' ) + substr_count( $sql, '%f' );
        if ( $placeholder_count !== count( $params ) ) {
            return new \WP_Error(
                'param_mismatch',
                sprintf( 'SQL has %d placeholder(s) but %d param(s) were provided.', $placeholder_count, count( $params ) )
            );
        }

        return $wpdb->prepare( $sql, ...$params );
    }

    /**
     * Check if a table exists by matching against all current tables.
     * Using get_col avoids any risk of unsafe table name interpolation.
     */
    private static function table_exists( string $table ): bool {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        return in_array( $table, $tables ?: [], true );
    }
}
