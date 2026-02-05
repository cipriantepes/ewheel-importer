<?php
/**
 * Image Service.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Service;

/**
 * Handles image import operations.
 *
 * Single Responsibility: Only handles image import/management.
 */
class ImageService {

    /**
     * Meta key for source URL.
     */
    private const SOURCE_URL_META = '_ewheel_source_url';

    /**
     * Import an image from URL.
     *
     * @param string $url Image URL.
     * @return int|null Attachment ID or null on failure.
     */
    public function import_from_url( string $url ): ?int {
        if ( empty( $url ) ) {
            return null;
        }

        // Normalize HTTP to HTTPS to avoid mixed content warnings
        $url = preg_replace( '/^http:\/\//i', 'https://', $url );

        // Check if already imported
        $existing = $this->find_by_source_url( $url );
        if ( $existing ) {
            return $existing;
        }

        return $this->download_and_attach( $url );
    }

    /**
     * Find attachment by source URL.
     *
     * @param string $url Source URL.
     * @return int|null Attachment ID or null.
     */
    public function find_by_source_url( string $url ): ?int {
        global $wpdb;

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::SOURCE_URL_META,
                $url
            )
        );

        return $attachment_id ? (int) $attachment_id : null;
    }

    /**
     * Download image and create attachment.
     *
     * @param string $url Image URL.
     * @return int|null Attachment ID or null on failure.
     */
    private function download_and_attach( string $url ): ?int {
        $this->require_wp_media_functions();

        $temp_file = download_url( $url );

        if ( is_wp_error( $temp_file ) ) {
            $this->log_error( 'Failed to download image: ' . $temp_file->get_error_message(), $url );
            return null;
        }

        $file_array = [
            'name'     => $this->get_filename_from_url( $url ),
            'tmp_name' => $temp_file,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            $this->cleanup_temp_file( $temp_file );
            $this->log_error( 'Failed to create attachment: ' . $attachment_id->get_error_message(), $url );
            return null;
        }

        // Store source URL for future lookups
        update_post_meta( $attachment_id, self::SOURCE_URL_META, $url );

        return $attachment_id;
    }

    /**
     * Get filename from URL.
     *
     * @param string $url Image URL.
     * @return string Filename.
     */
    private function get_filename_from_url( string $url ): string {
        $path     = wp_parse_url( $url, PHP_URL_PATH );
        $filename = basename( $path );

        // Ensure we have a valid filename
        if ( empty( $filename ) || strpos( $filename, '.' ) === false ) {
            $filename = 'image-' . md5( $url ) . '.jpg';
        }

        return sanitize_file_name( $filename );
    }

    /**
     * Require WordPress media functions.
     *
     * @return void
     */
    private function require_wp_media_functions(): void {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    /**
     * Clean up temporary file.
     *
     * @param string $temp_file Path to temp file.
     * @return void
     */
    private function cleanup_temp_file( string $temp_file ): void {
        if ( file_exists( $temp_file ) ) {
            @unlink( $temp_file );
        }
    }

    /**
     * Log an error.
     *
     * @param string $message Error message.
     * @param string $url     Related URL.
     * @return void
     */
    private function log_error( string $message, string $url ): void {
        error_log( sprintf( 'Ewheel Importer - Image Service: %s (URL: %s)', $message, $url ) );
    }

    /**
     * Import multiple images.
     *
     * @param array $urls Array of image URLs.
     * @return array<int> Array of attachment IDs.
     */
    public function import_batch( array $urls ): array {
        $attachment_ids = [];

        foreach ( $urls as $url ) {
            $attachment_id = $this->import_from_url( $url );
            if ( $attachment_id ) {
                $attachment_ids[] = $attachment_id;
            }
        }

        return $attachment_ids;
    }
}
