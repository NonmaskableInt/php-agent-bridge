<?php

namespace AgentBridge;

/**
 * Fallback for networks that strip the Authorization header (some egress proxies, CGI setups).
 *
 * A request may carry the same HTTP Basic credentials in `X-Agent-Authorization`. When — and only when —
 * the request has no Authorization of its own, they are handed to WordPress as PHP_AUTH_USER/PW, which is
 * exactly where core's application-password check reads them. Nothing is verified here: WordPress still
 * requires a valid application password for a real user (and HTTPS), so this adds a way to *deliver*
 * credentials, never a way around checking them.
 *
 * Applies to REST requests only. Switch off with AGENT_BRIDGE_DISABLE_ALT_AUTH.
 */
class AltAuth {

    const HEADER_KEY = 'HTTP_X_AGENT_AUTHORIZATION';

    /** Promotes the fallback header in $server when appropriate. Pure on its input so it can be reasoned about. */
    public static function promote( array &$server ): bool {
        if ( ! empty( $server['PHP_AUTH_USER'] )
            || ! empty( $server['HTTP_AUTHORIZATION'] )
            || ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            return false; // A real Authorization header always wins.
        }
        if ( ! self::is_rest_request( $server ) ) {
            return false;
        }
        $value = $server[ self::HEADER_KEY ] ?? '';
        if ( ! is_string( $value ) || stripos( $value, 'Basic ' ) !== 0 ) {
            return false;
        }
        $decoded = base64_decode( trim( substr( $value, 6 ) ), true );
        if ( $decoded === false || strpos( $decoded, ':' ) === false ) {
            return false;
        }
        [ $user, $pass ] = explode( ':', $decoded, 2 );
        if ( $user === '' ) {
            return false;
        }
        $server['PHP_AUTH_USER'] = $user;
        $server['PHP_AUTH_PW']   = $pass;
        return true;
    }

    public static function enabled(): bool {
        return ! ( defined( 'AGENT_BRIDGE_DISABLE_ALT_AUTH' ) && AGENT_BRIDGE_DISABLE_ALT_AUTH );
    }

    public static function boot(): void {
        if ( self::enabled() ) {
            self::promote( $_SERVER );
        }
    }

    private static function is_rest_request( array $server ): bool {
        $uri = (string) ( $server['REQUEST_URI'] ?? '' );
        return strpos( $uri, '/wp-json/' ) !== false
            || preg_match( '#/wp-json$#', strtok( $uri, '?' ) ?: '' ) === 1
            || strpos( $uri, 'rest_route=' ) !== false;
    }
}
