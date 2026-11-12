<?php

namespace AgentBridge\Apps;

use AgentBridge\Files\PathGuard;

/**
 * Validates and extracts an uploaded app bundle (zip). Every entry is checked
 * BEFORE anything is written: zip-slip, absolute paths, symlinks, hidden files,
 * disallowed types (PHP never allowed), size and count limits.
 */
class Archive {

    const MAX_FILES = 5000;
    const MAX_TOTAL = 50 * 1024 * 1024;
    const MAX_FILE  = 10 * 1024 * 1024;

    /**
     * @return array{ok:true,files:int,bytes:int}|array{ok:false,code:string,message:string,status:int}
     */
    public static function extract( string $zip_file, string $dest, string $slug ): array {
        if ( ! class_exists( '\ZipArchive' ) ) {
            return self::fail( 'zip_unavailable', 'The PHP zip extension is not available.', 503 );
        }
        $zip = new \ZipArchive();
        if ( $zip->open( $zip_file ) !== true ) {
            return self::fail( 'archive_invalid', 'The upload is not a valid zip archive.', 422 );
        }

        try {
            return self::run( $zip, $dest, $slug );
        } finally {
            $zip->close();
        }
    }

    private static function run( \ZipArchive $zip, string $dest, string $slug ): array {
        if ( $zip->numFiles > self::MAX_FILES + 500 ) {
            return self::fail( 'archive_too_large', 'Archive has too many entries.', 413 );
        }

        $entries  = [];
        $rejected = [];
        $declared = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $st   = $zip->statIndex( $i );
            $name = (string) $st['name'];

            // OS junk is dropped silently.
            if ( preg_match( '#(^|/)(__MACOSX|\.DS_Store|Thumbs\.db)(/|$)#', $name ) ) {
                continue;
            }

            $isdir = substr( $name, -1 ) === '/';
            $clean = rtrim( $name, '/' );

            if ( $clean === '' || strpos( $name, "\0" ) !== false || strpos( $name, '\\' ) !== false
                || $name[0] === '/' || preg_match( '#^[A-Za-z]:#', $name ) ) {
                $rejected[] = $name . ' (unsafe path)';
                continue;
            }
            $bad = false;
            foreach ( explode( '/', $clean ) as $seg ) {
                if ( $seg === '' || $seg === '.' || $seg === '..' ) {
                    $bad = true;
                }
            }
            if ( $bad ) {
                $rejected[] = $name . ' (traversal)';
                continue;
            }

            $opsys = 0;
            $attr  = 0;
            $zip->getExternalAttributesIndex( $i, $opsys, $attr );
            if ( $opsys === \ZipArchive::OPSYS_UNIX && ( ( $attr >> 16 ) & 0170000 ) === 0120000 ) {
                $rejected[] = $name . ' (symlink)';
                continue;
            }

            if ( ! $isdir ) {
                $declared += (int) $st['size'];
                if ( (int) $st['size'] > self::MAX_FILE ) {
                    $rejected[] = $name . ' (file too large)';
                    continue;
                }
            }
            $entries[] = [ 'i' => $i, 'name' => $clean, 'dir' => $isdir ];
        }

        if ( $rejected ) {
            return self::rejected( $rejected );
        }

        // A bundle zipped as dist/... : strip the single wrapper directory.
        $files  = array_values( array_filter( $entries, fn( $e ) => ! $e['dir'] ) );
        $prefix = '';
        if ( $files ) {
            $firsts = array_unique( array_map( fn( $e ) => explode( '/', $e['name'] )[0], $files ) );
            $root_files = array_filter( $files, fn( $e ) => strpos( $e['name'], '/' ) === false );
            if ( count( $firsts ) === 1 && ! $root_files ) {
                $prefix = $firsts[0] . '/';
            }
        }

        $plan = [];
        foreach ( $files as $e ) {
            $rel = $prefix !== '' ? substr( $e['name'], strlen( $prefix ) ) : $e['name'];
            foreach ( explode( '/', $rel ) as $seg ) {
                if ( $seg[0] === '.' ) {
                    $rejected[] = $e['name'] . ' (hidden file)';
                    continue 2;
                }
            }
            $deny = PathGuard::write_denial( "agent-apps/{$slug}/{$rel}" );
            if ( $deny !== null ) {
                $rejected[] = $e['name'] . ' (' . rtrim( strtok( $deny, '.' ), ' ' ) . ')';
                continue;
            }
            $plan[] = [ 'i' => $e['i'], 'rel' => $rel ];
        }

        if ( $rejected ) {
            return self::rejected( $rejected );
        }
        if ( count( $plan ) > self::MAX_FILES ) {
            return self::fail( 'archive_too_large', 'Archive has more than ' . self::MAX_FILES . ' files.', 413 );
        }
        if ( $declared > self::MAX_TOTAL ) {
            return self::fail( 'archive_too_large', 'Archive expands to more than ' . self::MAX_TOTAL . ' bytes.', 413 );
        }
        if ( ! in_array( 'index.html', array_column( $plan, 'rel' ), true ) ) {
            return self::fail( 'app_index_missing', 'The bundle must contain index.html at its root.', 422 );
        }

        // All checks passed — now extract, counting real bytes (declared sizes can lie).
        $total = 0;
        foreach ( $plan as $f ) {
            $target = $dest . '/' . $f['rel'];
            if ( ! wp_mkdir_p( dirname( $target ) ) ) {
                return self::fail( 'file_write_failed', 'Could not create directories for the app.', 500 );
            }
            $in  = $zip->getStream( $zip->getNameIndex( $f['i'] ) );
            $out = $in ? fopen( $target, 'wb' ) : false;
            if ( ! $in || ! $out ) {
                return self::fail( 'file_write_failed', "Could not extract {$f['rel']}.", 500 );
            }
            while ( ! feof( $in ) ) {
                $chunk = fread( $in, 65536 );
                if ( $chunk === false ) {
                    break;
                }
                $total += strlen( $chunk );
                if ( $total > self::MAX_TOTAL ) {
                    fclose( $in );
                    fclose( $out );
                    return self::fail( 'archive_too_large', 'Archive expands to more than ' . self::MAX_TOTAL . ' bytes.', 413 );
                }
                fwrite( $out, $chunk );
            }
            fclose( $in );
            fclose( $out );
        }

        return [ 'ok' => true, 'files' => count( $plan ), 'bytes' => $total ];
    }

    public static function remove_tree( string $dir ): void {
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) ?: [] as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $full = $dir . '/' . $name;
            if ( is_dir( $full ) && ! is_link( $full ) ) {
                self::remove_tree( $full );
            } else {
                @unlink( $full );
            }
        }
        @rmdir( $dir );
    }

    private static function rejected( array $items ): array {
        $shown = array_slice( $items, 0, 10 );
        $more  = count( $items ) > 10 ? ' (+' . ( count( $items ) - 10 ) . ' more)' : '';
        return self::fail( 'archive_rejected', 'Bundle contains disallowed entries: ' . implode( '; ', $shown ) . $more, 422 );
    }

    private static function fail( string $code, string $message, int $status ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
    }
}
