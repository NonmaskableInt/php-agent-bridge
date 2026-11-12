<?php
/**
 * CLI worker: boots WordPress for one URL and prints which template WordPress would render.
 * Run by Templates\Resolver in a separate PHP process (like the health check), so it can never
 * affect the request that asked. Not executable through the web server.
 *
 * argv: 1 = request URI (path + query), 2 = ABSPATH
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

$uri = (string) ( $argv[1] ?? '/' );
$abs = (string) ( $argv[2] ?? '' );

// Make this CLI process look like the web request WordPress expects: it reads $_GET and the
// script/query server variables, none of which exist on the command line.
$query_string = (string) parse_url( 'http://x' . $uri, PHP_URL_QUERY );
parse_str( $query_string, $_GET );
$_REQUEST                   = $_GET;
$_SERVER['REQUEST_URI']     = $uri;
$_SERVER['QUERY_STRING']    = $query_string;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $abs . 'index.php';
define( 'WP_USE_THEMES', true );
define( 'AGENT_BRIDGE_RESOLVING', true );

require $abs . 'wp-load.php';

$captured = [ 'type' => null, 'hierarchy' => [] ];
// Every {$type}_template_hierarchy filter that fires; the last one is the one that produced the result.
add_filter( 'all', static function () use ( &$captured ) {
    $args = func_get_args();
    if ( isset( $args[0] ) && is_string( $args[0] ) && preg_match( '/^(.+)_template_hierarchy$/', $args[0], $m ) ) {
        $captured['type']      = $m[1];
        $captured['hierarchy'] = is_array( $args[1] ?? null ) ? array_values( $args[1] ) : [];
    }
} );

// Report the template instead of rendering it.
add_filter( 'template_include', static function ( $template ) use ( &$captured ) {
    global $_wp_current_template_id;

    $out = [
        'is_block'         => false,
        'type'             => $captured['type'],
        'hierarchy'        => $captured['hierarchy'],
        'template_id'      => null,
        'slug'             => null,
        'source'           => null,
        'masks_theme_file' => false,
        'file'             => null,
    ];

    if ( ! empty( $_wp_current_template_id ) ) {
        $out['is_block']    = true;
        $out['template_id'] = $_wp_current_template_id;
        $block              = get_block_template( $_wp_current_template_id, 'wp_template' );
        if ( $block ) {
            $out['slug']             = $block->slug;
            $out['source']           = $block->source;
            $out['masks_theme_file'] = $block->source === 'custom' && $block->has_theme_file;
        }
    } elseif ( is_string( $template ) && $template !== '' ) {
        $out['file'] = ltrim( str_replace( WP_CONTENT_DIR, '', $template ), '/' );
        $out['slug'] = basename( $template, '.php' );
    }

    echo "\nAGENT_BRIDGE_RESOLVED:" . wp_json_encode( $out ) . "\n";
    exit( 0 );
}, PHP_INT_MAX );

// Parse the request exactly as WordPress would, without this plugin's own redirects/apps getting in the way.
wp();
remove_all_actions( 'template_redirect' );
require ABSPATH . WPINC . '/template-loader.php';
