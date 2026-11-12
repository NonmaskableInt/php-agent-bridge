<?php

namespace AgentBridge\Options;

/**
 * Which site options the bridge may read or write, grouped, with a typed spec per key.
 *
 * Groups are opt-in via AGENT_BRIDGE_OPTION_GROUPS. Some keys are never exposed because changing
 * them can hand over or lock out the site. A site owner can still list any key explicitly with the
 * legacy AGENT_BRIDGE_ALLOWED_OPTIONS constant (their wp-config, their decision).
 */
class Registry {

    const DEFAULT_GROUPS = [ 'general', 'reading', 'discussion', 'media' ];

    /** key => why it is never settable through the API. */
    const NEVER = [
        'default_role'       => 'The role given to new users: setting it to administrator is a privilege escalation.',
        'users_can_register' => 'Opens public registration; combined with default_role this creates admin accounts.',
        'admin_email'        => 'Receives password-reset mail: changing it enables account takeover.',
        'blog_public'        => 'Controls search-engine visibility; a silent way to de-index the site.',
        'active_plugins'     => 'Use the plugins API (activation runs plugin code safely).',
        'template'           => 'Use the themes API to switch themes.',
        'stylesheet'         => 'Use the themes API to switch themes.',
    ];

    const GROUP_INFO = [
        'general'    => 'Site title, tagline, date/time formats, timezone, feed and archive sizes. On by default.',
        'reading'    => 'Front page and posts page. On by default.',
        'discussion' => 'Comment defaults and moderation switches. On by default.',
        'media'      => 'Image sizes. On by default.',
        'permalinks' => 'Permalink structure and category/tag bases. Off by default: changes every public URL and needs a rewrite flush.',
        'site_url'   => 'siteurl and home. Off by default: a wrong value takes the whole site offline.',
    ];

    public static function groups(): array {
        $int = static fn( int $min, int $max, string $desc = '' ) => [ 'type' => 'integer', 'min' => $min, 'max' => $max, 'description' => $desc ];
        $bool = static fn( string $desc = '' ) => [ 'type' => 'boolean', 'description' => $desc ];
        $enum = static fn( array $values, string $desc = '' ) => [ 'type' => 'enum', 'values' => $values, 'description' => $desc ];
        $str = static fn( int $min, int $max, string $desc = '' ) => [ 'type' => 'string', 'min_length' => $min, 'max_length' => $max, 'description' => $desc ];
        $page = static fn( string $desc ) => [ 'type' => 'integer', 'format' => 'page_id', 'description' => $desc . ' 0 = none; otherwise a published page.' ];
        $size = static fn( string $d ) => $int( 0, 10000, $d . ' (pixels, 0 = unconstrained)' );

        return [
            'general' => [
                'blogname'        => $str( 1, 200, 'Site title' ),
                'blogdescription' => $str( 0, 300, 'Tagline' ),
                'timezone_string' => [ 'type' => 'string', 'format' => 'timezone', 'description' => 'IANA timezone, e.g. Europe/Paris or UTC' ],
                'date_format'     => $str( 1, 40, 'PHP date format, e.g. F j, Y' ),
                'time_format'     => $str( 1, 40, 'PHP time format, e.g. g:i a' ),
                'start_of_week'   => $int( 0, 6, '0 = Sunday … 6 = Saturday' ),
                'posts_per_page'  => $int( 1, 100, 'Blog pages show at most' ),
                'posts_per_rss'   => $int( 1, 100, 'Feeds show the most recent' ),
            ],
            'reading' => [
                'show_on_front'  => $enum( [ 'posts', 'page' ], 'What the front page shows' ),
                'page_on_front'  => $page( 'Static front page.' ),
                'page_for_posts' => $page( 'Page that lists blog posts.' ),
            ],
            'discussion' => [
                'default_comment_status'       => $enum( [ 'open', 'closed' ], 'Comments on new posts' ),
                'default_ping_status'          => $enum( [ 'open', 'closed' ], 'Pingbacks on new posts' ),
                'require_name_email'           => $bool( 'Commenters must give name and email' ),
                'comment_registration'         => $bool( 'Only logged-in users may comment' ),
                'close_comments_for_old_posts' => $bool( 'Close comments on old posts' ),
                'close_comments_days_old'      => $int( 1, 3650, 'Age in days after which comments close' ),
                'thread_comments'              => $bool( 'Threaded comments' ),
                'comments_per_page'            => $int( 1, 200, 'Comments per page' ),
            ],
            'media' => [
                'thumbnail_size_w' => $size( 'Thumbnail width' ),
                'thumbnail_size_h' => $size( 'Thumbnail height' ),
                'medium_size_w'    => $size( 'Medium width' ),
                'medium_size_h'    => $size( 'Medium height' ),
                'large_size_w'     => $size( 'Large width' ),
                'large_size_h'     => $size( 'Large height' ),
            ],
            'permalinks' => [
                'permalink_structure' => [ 'type' => 'string', 'format' => 'permalink', 'description' => 'e.g. /%postname%/ or /blog/%year%/%postname%/. Must contain %postname% or %post_id%. Plain permalinks are refused (they break the REST API).' ],
                'category_base'       => [ 'type' => 'string', 'format' => 'slug_base', 'description' => 'Category URL prefix, or empty' ],
                'tag_base'            => [ 'type' => 'string', 'format' => 'slug_base', 'description' => 'Tag URL prefix, or empty' ],
            ],
            'site_url' => [
                'siteurl' => [ 'type' => 'string', 'format' => 'url', 'description' => 'WordPress address' ],
                'home'    => [ 'type' => 'string', 'format' => 'url', 'description' => 'Site address' ],
            ],
        ];
    }

    /** @return list<string> */
    public static function enabled_groups(): array {
        $groups = self::DEFAULT_GROUPS;
        if ( defined( 'AGENT_BRIDGE_OPTION_GROUPS' ) && is_array( AGENT_BRIDGE_OPTION_GROUPS ) ) {
            $groups = array_values( array_intersect( array_map( 'strval', AGENT_BRIDGE_OPTION_GROUPS ), array_keys( self::GROUP_INFO ) ) );
        }
        return $groups;
    }

    public static function legacy_allowlist(): ?array {
        return defined( 'AGENT_BRIDGE_ALLOWED_OPTIONS' ) && is_array( AGENT_BRIDGE_ALLOWED_OPTIONS ) ? array_values( AGENT_BRIDGE_ALLOWED_OPTIONS ) : null;
    }

    /** Keys the API accepts right now. */
    public static function allowed_keys(): array {
        if ( ( $legacy = self::legacy_allowlist() ) !== null ) {
            return $legacy;
        }
        $keys   = [];
        $groups = self::groups();
        foreach ( self::enabled_groups() as $g ) {
            $keys = array_merge( $keys, array_keys( $groups[ $g ] ?? [] ) );
        }
        return $keys;
    }

    /** @return array{group:string,spec:array}|null */
    public static function find( string $key ): ?array {
        foreach ( self::groups() as $group => $keys ) {
            if ( isset( $keys[ $key ] ) ) {
                return [ 'group' => $group, 'spec' => $keys[ $key ] ];
            }
        }
        return null;
    }

    /** A message explaining why $key is not allowed, tailored to the reason. */
    public static function refusal( string $key ): string {
        if ( isset( self::NEVER[ $key ] ) ) {
            return "'{$key}' can never be changed through the bridge. " . self::NEVER[ $key ];
        }
        if ( self::legacy_allowlist() === null && ( $found = self::find( $key ) ) !== null ) {
            return "'{$key}' belongs to the '{$found['group']}' option group, which is off. Enable it in wp-config.php with define( 'AGENT_BRIDGE_OPTION_GROUPS', [ ...'{$found['group']}' ] ). " . self::GROUP_INFO[ $found['group'] ];
        }
        return "Option key '{$key}' is not in the allowlist.";
    }

    /** Typed view of a stored value. */
    public static function typed( string $key, $raw ) {
        $found = self::find( $key );
        if ( $found === null ) {
            return $raw;
        }
        switch ( $found['spec']['type'] ) {
            case 'integer':
                return (int) $raw;
            case 'boolean':
                return (bool) (int) $raw;
            default:
                return is_scalar( $raw ) ? (string) $raw : $raw;
        }
    }
}
