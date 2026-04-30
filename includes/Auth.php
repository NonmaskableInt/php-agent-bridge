<?php

namespace AgentBridge;

class Auth {

    /**
     * Base permission check — requires edit_posts capability.
     * Use as the `permission_callback` for read and write routes.
     */
    public static function can_edit(): bool {
        return is_user_logged_in() && current_user_can( 'edit_posts' );
    }

    /**
     * Requires publish_posts capability (write operations).
     */
    public static function can_publish(): bool {
        return is_user_logged_in() && current_user_can( 'publish_posts' );
    }

    /**
     * Requires manage_options capability (destructive / privileged operations).
     */
    public static function can_manage(): bool {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    /**
     * Requires both manage_options and switch_themes (theme operations).
     */
    public static function can_switch_themes(): bool {
        return is_user_logged_in()
            && current_user_can( 'manage_options' )
            && current_user_can( 'switch_themes' );
    }

    /**
     * Returns a WP_Error or true for use in permission_callback.
     * WordPress REST API handles WP_Error returns from permission_callback automatically.
     */
    public static function require_edit(): bool|\WP_Error {
        if ( ! self::can_edit() ) {
            return new \WP_Error(
                'auth_required',
                'Missing or invalid credentials.',
                [ 'status' => 401 ]
            );
        }
        return true;
    }

    public static function require_publish(): bool|\WP_Error {
        if ( ! self::can_publish() ) {
            return new \WP_Error(
                'insufficient_capability',
                'You need publish_posts capability for this operation.',
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    public static function require_manage(): bool|\WP_Error {
        if ( ! self::can_manage() ) {
            return new \WP_Error(
                'insufficient_capability',
                'You need manage_options capability for this operation.',
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    public static function require_theme_management(): bool|\WP_Error {
        if ( ! self::can_switch_themes() ) {
            return new \WP_Error(
                'insufficient_capability',
                'You need manage_options and switch_themes capabilities for this operation.',
                [ 'status' => 403 ]
            );
        }
        return true;
    }
}
