<?php

namespace AgentBridge\Templates;

use AgentBridge\Files\PhpGuard;
use AgentBridge\Headless\Config;

/** Answers "which template renders this URL?" by asking WordPress in a separate process. */
class Resolver {

    const MAX_URL = 2000;

    /**
     * @return array{ok:true,result:array}|array{ok:false,code:string,message:string,status:int}
     */
    public static function resolve( $url ): array {
        $uri = self::request_uri( $url );
        if ( $uri === null ) {
            return self::fail( 'validation_error', "'url' must be a path such as /blog/hello/ or ?p=12, or a full URL on this site (max " . self::MAX_URL . ' characters, no control characters).', 422 );
        }

        $bin = PhpGuard::php_binary();
        if ( $bin === null || ! function_exists( 'proc_open' ) ) {
            return self::fail( 'verification_unavailable', 'The PHP binary or proc_open is unavailable, so the template cannot be resolved.', 503 );
        }

        $res = PhpGuard::run( [ $bin, '-d', 'display_errors=0', __DIR__ . '/resolve-worker.php', $uri, ABSPATH ], 30 );
        if ( ! preg_match( '/AGENT_BRIDGE_RESOLVED:(\{.*\})\s*$/s', $res['out'], $m ) ) {
            $detail = substr( trim( strip_tags( $res['out'] . "\n" . $res['err'] ) ), -400 );
            return self::fail( 'resolve_failed', 'WordPress could not resolve that URL. ' . $detail, 500 );
        }
        $result = json_decode( $m[1], true );
        if ( ! is_array( $result ) ) {
            return self::fail( 'resolve_failed', 'The resolver returned unreadable output.', 500 );
        }
        $result['url'] = $uri;
        return [ 'ok' => true, 'result' => $result ];
    }

    /** Path + query for a relative path or a same-site absolute URL; null for anything else. */
    public static function request_uri( $url ): ?string {
        if ( ! is_string( $url ) || $url === '' || strlen( $url ) > self::MAX_URL || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return null;
        }
        if ( $url[0] === '?' ) {
            $url = '/' . $url;
        }
        if ( $url[0] === '/' ) {
            return strpos( $url, '//' ) === 0 ? null : $url;
        }
        if ( ! preg_match( '#^https?://#i', $url ) || ! Config::same_origin( $url ) ) {
            return null;
        }
        $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $home  = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home !== '' && strpos( $path, $home ) === 0 ) {
            $path = substr( $path, strlen( $home ) );
        }
        $path = '/' . ltrim( $path, '/' );
        return $path . ( $query !== '' ? '?' . $query : '' );
    }

    private static function fail( string $code, string $message, int $status ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
