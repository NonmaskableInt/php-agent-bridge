<?php

namespace AgentBridge\Routes;

use AgentBridge\Auth;
use AgentBridge\Response;

class CliRoute {

    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/wp-cli', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'run_command' ],
                'permission_callback' => [ Auth::class, 'require_manage' ],
            ],
        ] );
    }

    public static function run_command( \WP_REST_Request $request ): \WP_REST_Response {
        $data    = $request->get_json_params() ?? [];
        $command = trim( $data['command'] ?? '' );

        if ( ! $command ) {
            return Response::validation_error( 'command is required.' );
        }

        $deny_error = self::is_denied( $command );
        if ( $deny_error ) {
            return Response::error( 'cli_command_denied', $deny_error, 403 );
        }

        if ( ! self::is_open_mode() && ! self::is_allowed( $command ) ) {
            return Response::error(
                'cli_command_not_allowed',
                "Command '{$command}' is not in the allowed WP-CLI command list.",
                403
            );
        }

        // Locate WP-CLI binary via proc_open (safer than exec('which wp'))
        $wp_bin = self::find_wp_binary();
        if ( ! $wp_bin ) {
            return Response::error( 'cli_not_available', 'WP-CLI is not installed or not in PATH.', 500 );
        }

        // Parse the allowlisted command string into discrete arguments.
        // Using proc_open with an array avoids shell interpretation entirely.
        $args = array_merge(
            [ $wp_bin ],
            self::parse_args( $command ),
            [ '--path=' . ABSPATH, '--allow-root' ]
        );

        $descriptors = [
            0 => [ 'pipe', 'r' ],  // stdin
            1 => [ 'pipe', 'w' ],  // stdout
            2 => [ 'pipe', 'w' ],  // stderr
        ];

        $proc = proc_open( $args, $descriptors, $pipes );

        if ( ! is_resource( $proc ) ) {
            return Response::error( 'cli_exec_failed', 'Failed to start WP-CLI process.', 500 );
        }

        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $proc );

        return Response::success( [
            'command'   => $command,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'exit_code' => $exit_code,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Open mode: allows any WP-CLI command (including plugin-registered commands)
     * as long as it is not in the denylist.
     *
     * Enable via: define( 'AGENT_BRIDGE_CLI_OPEN', true );
     */
    private static function is_open_mode(): bool {
        return defined( 'AGENT_BRIDGE_CLI_OPEN' ) && AGENT_BRIDGE_CLI_OPEN;
    }

    /**
     * Commands that are always blocked regardless of open/allowlist mode.
     * These have large blast radius or are irreversible.
     *
     * Returns an error message string if denied, null if allowed.
     */
    private static function is_denied( string $command ): ?string {
        $denylist = [
            'db drop'       => 'Drops the entire database.',
            'db reset'      => 'Drops and recreates the database.',
            'db clean'      => 'Removes all tables from the database.',
            'site delete'   => 'Permanently deletes a multisite instance.',
            'core download' => 'Downloads WordPress core — use admin UI.',
        ];

        foreach ( $denylist as $prefix => $reason ) {
            if ( $command === $prefix || str_starts_with( $command, $prefix . ' ' ) ) {
                return "Command '{$prefix}' is permanently blocked: {$reason}";
            }
        }

        return null;
    }

    private static function is_allowed( string $command ): bool {
        $allowlist = self::get_allowlist();

        foreach ( $allowlist as $allowed ) {
            if ( $command === $allowed || strpos( $command, $allowed . ' ' ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a command string into an argument array using PHP's native tokenizer.
     * This is not a shell — it only handles quoted strings and whitespace.
     */
    private static function parse_args( string $command ): array {
        $args   = [];
        $token  = '';
        $in_dq  = false;
        $in_sq  = false;
        $length = strlen( $command );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $command[ $i ];

            if ( $in_dq ) {
                if ( $char === '"' ) {
                    $in_dq = false;
                } elseif ( $char === '\\' && $i + 1 < $length ) {
                    $token .= $command[ ++$i ];
                } else {
                    $token .= $char;
                }
            } elseif ( $in_sq ) {
                if ( $char === "'" ) {
                    $in_sq = false;
                } else {
                    $token .= $char;
                }
            } elseif ( $char === '"' ) {
                $in_dq = true;
            } elseif ( $char === "'" ) {
                $in_sq = true;
            } elseif ( $char === ' ' || $char === "\t" ) {
                if ( $token !== '' ) {
                    $args[] = $token;
                    $token  = '';
                }
            } else {
                $token .= $char;
            }
        }

        if ( $token !== '' ) {
            $args[] = $token;
        }

        return $args;
    }

    private static function find_wp_binary(): ?string {
        // Check common paths before falling back to PATH search
        $candidates = [ '/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp' ];
        foreach ( $candidates as $path ) {
            if ( is_executable( $path ) ) {
                return $path;
            }
        }

        // Safely search PATH — no shell involvement
        $path_dirs = explode( PATH_SEPARATOR, getenv( 'PATH' ) ?: '' );
        foreach ( $path_dirs as $dir ) {
            $bin = rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wp';
            if ( is_executable( $bin ) ) {
                return $bin;
            }
        }

        return null;
    }

    private static function get_allowlist(): array {
        if ( defined( 'AGENT_BRIDGE_CLI_ALLOWLIST' ) && is_array( AGENT_BRIDGE_CLI_ALLOWLIST ) ) {
            return AGENT_BRIDGE_CLI_ALLOWLIST;
        }

        return [
            'cache flush',
            'cron event run --due-now',
            'rewrite flush',
            'option get',
            'plugin list',
            'plugin get',
            'plugin verify-checksums',
            'theme list',
            'theme get',
            'search-replace --dry-run',
            'media regenerate --yes',
            'post list',
            'language core list',
            'language plugin list',
        ];
    }
}
