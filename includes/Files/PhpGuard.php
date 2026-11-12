<?php

namespace AgentBridge\Files;

/**
 * Keeps the PHP backend alive: lint before a write lands, and prove WordPress
 * still boots afterwards. Fails closed when it cannot verify.
 */
class PhpGuard {

    public static function php_binary(): ?string {
        $candidates = [];
        if ( defined( 'AGENT_BRIDGE_PHP_BINARY' ) ) {
            $candidates[] = AGENT_BRIDGE_PHP_BINARY;
        }
        if ( defined( 'PHP_BINARY' ) && PHP_BINARY !== '' && basename( PHP_BINARY ) === 'php' ) {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = rtrim( PHP_BINDIR, '/' ) . '/php';
        array_push( $candidates, '/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php' );
        foreach ( $candidates as $bin ) {
            if ( is_string( $bin ) && is_file( $bin ) && is_executable( $bin ) ) {
                return $bin;
            }
        }
        return null;
    }

    /** @return array{ok:bool,output:string} */
    public static function lint( string $file ): array {
        $bin = self::php_binary();
        if ( $bin === null || ! function_exists( 'proc_open' ) ) {
            return [ 'ok' => false, 'output' => 'php binary or proc_open unavailable; refusing unverified PHP.', 'unavailable' => true ];
        }
        $res = self::run( [ $bin, '-n', '-l', $file ], 10 );
        return [ 'ok' => $res['code'] === 0, 'output' => trim( $res['out'] . $res['err'] ) ];
    }

    /** Boots WordPress in a separate PHP process, exactly as a request would. */
    public static function health_check(): array {
        $bin = self::php_binary();
        if ( $bin === null || ! function_exists( 'proc_open' ) ) {
            return [ 'ok' => false, 'output' => 'php binary or proc_open unavailable; cannot verify site health.', 'unavailable' => true ];
        }
        $code = 'define("WP_USE_THEMES", false); require $argv[1]; echo "AGENT_BRIDGE_HEALTH_OK";';
        $res  = self::run( [ $bin, '-d', 'display_errors=1', '-r', $code, '--', ABSPATH . 'wp-load.php' ], 30 );
        $ok   = $res['code'] === 0 && strpos( $res['out'], 'AGENT_BRIDGE_HEALTH_OK' ) !== false;
        return [ 'ok' => $ok, 'output' => $ok ? '' : substr( trim( strip_tags( $res['out'] . "\n" . $res['err'] ) ), -1500 ) ];
    }

    /** @return array{code:int,out:string,err:string} */
    public static function run( array $argv, int $timeout ): array {
        $proc = proc_open( $argv, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
        if ( ! is_resource( $proc ) ) {
            return [ 'code' => -1, 'out' => '', 'err' => 'proc_open failed' ];
        }
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        $out = $err = '';
        $end = microtime( true ) + $timeout;
        while ( true ) {
            $out .= (string) stream_get_contents( $pipes[1] );
            $err .= (string) stream_get_contents( $pipes[2] );
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) {
                break;
            }
            if ( microtime( true ) > $end ) {
                proc_terminate( $proc, 9 );
                $err .= "\ntimed out after {$timeout}s";
                break;
            }
            usleep( 20000 );
        }
        $out .= (string) stream_get_contents( $pipes[1] );
        $err .= (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $proc );
        // proc_close returns -1 if proc_get_status already reaped the child.
        if ( isset( $status['exitcode'] ) && $status['exitcode'] !== -1 ) {
            $code = $status['exitcode'];
        }
        return [ 'code' => (int) $code, 'out' => $out, 'err' => $err ];
    }
}
