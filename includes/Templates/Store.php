<?php

namespace AgentBridge\Templates;

use AgentBridge\Files\Backups;

/**
 * Block templates and template parts. Reads use WordPress's own resolver; writes go through
 * WordPress's REST controllers (rest_do_request), so term assignment, sanitising and capability
 * checks stay core's job and cannot drift from what the Site Editor does.
 */
class Store {

    const MAX_CONTENT = 500000;
    const SLUG_RE     = '/^[a-z0-9][a-z0-9_-]{0,60}$/';

    const TYPES = [
        'template' => [ 'post_type' => 'wp_template', 'rest' => 'templates', 'suffix' => '' ],
        'part'     => [ 'post_type' => 'wp_template_part', 'rest' => 'template-parts', 'suffix' => '.part' ],
    ];

    /** @return array{0:?array,1:?array} [type info, error] */
    public static function type( $type ): array {
        $type = $type ?? 'template';
        if ( ! is_string( $type ) || ! isset( self::TYPES[ $type ] ) ) {
            return [ null, self::fail( 'validation_error', "'type' must be 'template' or 'part'.", 422 ) ];
        }
        return [ [ 'name' => $type ] + self::TYPES[ $type ], null ];
    }

    /** Active theme's templates only; returns [theme, slug, full id] or an error. */
    public static function parse_id( $id ): array {
        if ( ! is_string( $id ) || trim( $id ) === '' ) {
            return [ null, self::fail( 'validation_error', "'id' is required: a slug like 'single' or 'theme//single'.", 422 ) ];
        }
        $id    = trim( $id );
        $theme = get_stylesheet();
        $slug  = $id;
        if ( strpos( $id, '//' ) !== false ) {
            $parts = explode( '//', $id );
            if ( count( $parts ) !== 2 ) {
                return [ null, self::fail( 'validation_error', "'id' must be 'slug' or 'theme//slug'.", 422 ) ];
            }
            [ $given, $slug ] = $parts;
            if ( $given !== $theme ) {
                return [ null, self::fail( 'wrong_theme', "Only the active theme's templates can be used ('{$theme}'), not '{$given}'.", 422 ) ];
            }
        }
        if ( ! preg_match( self::SLUG_RE, $slug ) ) {
            return [ null, self::fail( 'validation_error', "Template slug must match " . self::SLUG_RE . '.', 422 ) ];
        }
        return [ [ $theme, $slug, $theme . '//' . $slug ], null ];
    }

    public static function require_block_theme(): ?array {
        return wp_is_block_theme() ? null : self::fail( 'not_block_theme', 'The active theme is a classic theme: it has no block templates. Edit its PHP template files with the files API; template_list shows which files and template_resolve which one renders a URL.', 409 );
    }

    // ------------------------------------------------------------- reading

    public static function list( array $type ): array {
        $out = [];
        foreach ( get_block_templates( [], $type['post_type'] ) as $t ) {
            $out[] = self::describe( $t, $type['name'] );
        }
        usort( $out, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );
        return $out;
    }

    public static function find( string $full_id, array $type ): ?\WP_Block_Template {
        $t = get_block_template( $full_id, $type['post_type'] );
        return $t instanceof \WP_Block_Template ? $t : null;
    }

    public static function describe( \WP_Block_Template $t, string $type, bool $with_content = false ): array {
        $custom = $t->source === 'custom';
        $d      = [
            'id'               => $t->id,
            'slug'             => $t->slug,
            'theme'            => $t->theme,
            'type'             => $type,
            'title'            => is_string( $t->title ) ? $t->title : '',
            'description'      => (string) $t->description,
            'source'           => $t->source,
            'origin'           => $t->origin ?: null,
            'is_custom'        => (bool) $t->is_custom,
            'has_theme_file'   => (bool) $t->has_theme_file,
            'masks_theme_file' => $custom && $t->has_theme_file,
            'wp_id'            => $t->wp_id ? (int) $t->wp_id : null,
            'modified'         => ! empty( $t->modified ) ? ( is_string( $t->modified ) ? $t->modified : null ) : null,
        ];
        if ( $type === 'part' ) {
            $d['area'] = $t->area;
        }
        if ( $with_content ) {
            $d['content'] = (string) $t->content;
            // What an override is hiding: the theme file's own content.
            $d['theme_content'] = null;
            if ( $custom && $t->has_theme_file ) {
                $file = _get_block_template_file( $type === 'part' ? 'wp_template_part' : 'wp_template', $t->slug );
                if ( is_array( $file ) && ! empty( $file['path'] ) && is_readable( $file['path'] ) ) {
                    $d['theme_content'] = (string) file_get_contents( $file['path'] );
                }
            }
        }
        return $d;
    }

    // ------------------------------------------------------------- writing

    /**
     * Create or update a custom template/part (an "override" when the theme ships one).
     *
     * @return array{ok:true,template:array,created:bool}|array{ok:false,code:string,message:string,status:int}
     */
    public static function upsert( array $input, string $audit_action = 'template_set' ): array {
        $allowed = [ 'id', 'type', 'content', 'title', 'description', 'area' ];
        if ( $unknown = array_diff( array_keys( $input ), $allowed ) ) {
            return self::fail( 'validation_error', 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', $allowed ) . '.', 422 );
        }
        [ $type, $err ] = self::type( $input['type'] ?? null );
        if ( $err ) {
            return $err;
        }
        if ( $err = self::require_block_theme() ) {
            return $err;
        }
        [ $parsed, $err ] = self::parse_id( $input['id'] ?? null );
        if ( $err ) {
            return $err;
        }
        [ $theme, $slug, $full ] = $parsed;

        $content = $input['content'] ?? null;
        if ( ! is_string( $content ) ) {
            return self::fail( 'validation_error', "'content' (block markup, a string) is required.", 422 );
        }
        if ( strlen( $content ) > self::MAX_CONTENT ) {
            return self::fail( 'validation_error', "'content' is larger than " . self::MAX_CONTENT . ' bytes.', 422 );
        }
        foreach ( [ 'title' => 100, 'description' => 300 ] as $k => $max ) {
            if ( isset( $input[ $k ] ) && ( ! is_string( $input[ $k ] ) || strlen( $input[ $k ] ) > $max ) ) {
                return self::fail( 'validation_error', "'{$k}' must be a string of at most {$max} characters.", 422 );
            }
        }
        $area = $input['area'] ?? null;
        if ( $area !== null ) {
            if ( $type['name'] !== 'part' ) {
                return self::fail( 'validation_error', "'area' only applies to template parts.", 422 );
            }
            $areas = array_column( get_allowed_block_template_part_areas(), 'area' );
            if ( ! is_string( $area ) || ! in_array( $area, $areas, true ) ) {
                return self::fail( 'validation_error', "'area' must be one of: " . implode( ', ', $areas ) . '.', 422 );
            }
        }

        $existing = self::find( $full, $type );
        $params   = [ 'content' => $content, 'status' => 'publish' ];
        foreach ( [ 'title', 'description' ] as $k ) {
            if ( isset( $input[ $k ] ) ) {
                $params[ $k ] = $input[ $k ];
            }
        }
        if ( $area !== null ) {
            $params['area'] = $area;
        }
        if ( $existing === null ) {
            $params += [ 'slug' => $slug, 'theme' => $theme, 'title' => $input['title'] ?? $slug ];
            $req = new \WP_REST_Request( 'POST', '/wp/v2/' . $type['rest'] );
        } else {
            $req = new \WP_REST_Request( 'POST', '/wp/v2/' . $type['rest'] . '/' . $full );
        }
        $req->set_body_params( $params );

        $res = rest_do_request( $req );
        if ( $res->is_error() ) {
            $e = $res->as_error();
            return self::fail( (string) $e->get_error_code(), $e->get_error_message(), $res->get_status() ?: 500 );
        }

        $saved = self::find( $full, $type );
        if ( $saved === null ) {
            return self::fail( 'template_write_failed', 'WordPress accepted the write but the template cannot be read back.', 500 );
        }
        Backups::audit( WP_CONTENT_DIR, $audit_action, "{$type['name']}:{$full}", [ 'bytes' => strlen( $content ), 'created' => $existing === null ] );
        // Saved regardless (like the Site Editor), but the problems are reported straight away.
        $described               = self::describe( $saved, $type['name'] );
        $described['validation'] = \AgentBridge\Themes\BlockMarkup::check( $content );
        return [ 'ok' => true, 'template' => $described, 'created' => $existing === null ];
    }

    /**
     * Remove a custom template/part. Theme-provided templates fall back to their file; custom-only ones disappear.
     * The content is saved first so it can be restored.
     */
    public static function reset( $id, $type_in ): array {
        [ $type, $err ] = self::type( $type_in );
        if ( $err ) {
            return $err;
        }
        if ( $err = self::require_block_theme() ) {
            return $err;
        }
        [ $parsed, $err ] = self::parse_id( $id );
        if ( $err ) {
            return $err;
        }
        $full = $parsed[2];

        $t = self::find( $full, $type );
        if ( $t === null ) {
            return self::fail( 'template_not_found', "No {$type['name']} '{$full}'.", 404 );
        }
        if ( $t->source !== 'custom' || ! $t->wp_id ) {
            return self::fail( 'no_override', "'{$full}' comes from the theme and has no override to reset.", 409 );
        }

        $backup = self::save_backup( $t, $type );

        $req = new \WP_REST_Request( 'DELETE', '/wp/v2/' . $type['rest'] . '/' . $full );
        $req->set_param( 'force', true );
        $res = rest_do_request( $req );
        if ( $res->is_error() ) {
            $e = $res->as_error();
            return self::fail( (string) $e->get_error_code(), $e->get_error_message(), $res->get_status() ?: 500 );
        }
        Backups::audit( WP_CONTENT_DIR, 'template_reset', "{$type['name']}:{$full}", [ 'backup' => $backup ] );

        return [ 'ok' => true, 'result' => [
            'id'          => $full,
            'reset'       => true,
            'reverted_to' => $t->has_theme_file ? 'theme' : 'removed',
            'backup'      => $backup,
        ] ];
    }

    // ------------------------------------------------------------- backups

    private static function backup_dir(): string {
        $dir = Backups::dir( WP_CONTENT_DIR ) . '/template-backups';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    private static function save_backup( \WP_Block_Template $t, array $type ): string {
        $name = gmdate( 'Ymd-His' ) . '-' . $t->slug . $type['suffix'] . '.json';
        file_put_contents( self::backup_dir() . '/' . $name, wp_json_encode( [
            'id' => $t->id, 'type' => $type['name'], 'slug' => $t->slug, 'theme' => $t->theme,
            'title' => is_string( $t->title ) ? $t->title : '', 'description' => (string) $t->description,
            'area' => $type['name'] === 'part' ? $t->area : null, 'content' => (string) $t->content,
            'removed_at' => gmdate( 'c' ), 'user' => get_current_user_id(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
        return $name;
    }

    public static function backups(): array {
        $out = [];
        foreach ( glob( self::backup_dir() . '/*.json' ) ?: [] as $file ) {
            $d = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $d ) ) {
                $out[] = [ 'name' => basename( $file ), 'id' => $d['id'] ?? null, 'type' => $d['type'] ?? null, 'title' => $d['title'] ?? '', 'removed_at' => $d['removed_at'] ?? null ];
            }
        }
        usort( $out, fn( $a, $b ) => strcmp( $b['name'], $a['name'] ) );
        return array_slice( $out, 0, 50 );
    }

    public static function restore( $name ): array {
        if ( ! is_string( $name ) || ! preg_match( '/^[0-9]{8}-[0-9]{6}-[a-z0-9_-]+(\.part)?\.json$/', $name ) ) {
            return self::fail( 'validation_error', "'name' must be a backup name from template_backups.", 422 );
        }
        $file = self::backup_dir() . '/' . $name;
        if ( ! is_file( $file ) ) {
            return self::fail( 'backup_not_found', "No backup '{$name}'.", 404 );
        }
        $d = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $d ) || ! isset( $d['id'], $d['content'], $d['type'] ) ) {
            return self::fail( 'backup_invalid', "Backup '{$name}' is unreadable.", 422 );
        }
        $input = [ 'id' => $d['id'], 'type' => $d['type'], 'content' => $d['content'] ];
        foreach ( [ 'title', 'description' ] as $k ) {
            if ( ! empty( $d[ $k ] ) ) {
                $input[ $k ] = $d[ $k ];
            }
        }
        if ( $d['type'] === 'part' && ! empty( $d['area'] ) ) {
            $input['area'] = $d['area'];
        }
        return self::upsert( $input, 'template_restore' );
    }

    private static function fail( string $code, string $message, int $status ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
