<?php

namespace AgentBridge\Files;

use AgentBridge\Response;

/**
 * The one place that decides whether this site allows the bridge to write code or files.
 * Shared by /files and /apps so their rules cannot drift apart.
 */
class WriteGate {

    /** Site-level switches: the opt-in constant, and WordPress's own hardening constants. */
    public static function check_site(): ?\WP_REST_Response {
        if ( ! ( defined( 'AGENT_BRIDGE_ALLOW_FILE_WRITE' ) && AGENT_BRIDGE_ALLOW_FILE_WRITE ) ) {
            return Response::error( 'file_write_disabled', 'File writes and app deploys are disabled. Set AGENT_BRIDGE_ALLOW_FILE_WRITE to true.', 403 );
        }
        // No exemptions: JavaScript in an app is code too. Sites set these precisely so that a
        // compromised admin account cannot add code.
        if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
            return Response::error( 'file_edit_disabled', 'DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS is set on this site.', 403 );
        }
        return null;
    }

    /**
     * Capability needed to write under a given root.
     * - themes / plugins / mu-plugins: edit_themes / edit_plugins (super admin on multisite).
     * - agent-apps: scripts served from the site's own origin, so unfiltered_html as well. WordPress
     *   removes it for multisite site admins and when DISALLOW_UNFILTERED_HTML is set.
     */
    public static function check_capability( string $root ): ?\WP_REST_Response {
        if ( in_array( $root, [ 'plugins', 'mu-plugins' ], true ) ) {
            $needed = [ 'edit_plugins' ];
        } elseif ( $root === 'themes' ) {
            $needed = [ 'edit_themes' ];
        } else {
            $needed = [ 'manage_options', 'unfiltered_html' ];
        }
        foreach ( $needed as $cap ) {
            if ( ! current_user_can( $cap ) ) {
                return Response::error( 'insufficient_capability', "Writing under '{$root}' requires the {$cap} capability.", 403 );
            }
        }
        return null;
    }
}
