<?php
/**
 * Base Security Test Case.
 *
 * Provides common functionality for security tests.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

use Trotibike\EwheelImporter\Tests\TestCase;

/**
 * Base class for security tests with shared utilities.
 */
abstract class SecurityTestCase extends TestCase {

    /**
     * Plugin root directory.
     *
     * @var string
     */
    protected string $plugin_root;

    /**
     * Cached PHP files list.
     *
     * @var array<string>|null
     */
    private static ?array $cached_php_files = null;

    /**
     * Set up test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->plugin_root = dirname( __DIR__, 2 );
    }

    /**
     * Get all PHP files in the plugin source (excluding vendor and tests).
     *
     * Uses caching to avoid repeated filesystem scans.
     *
     * @return array<string>
     */
    protected function get_php_files(): array {
        if ( self::$cached_php_files !== null ) {
            return self::$cached_php_files;
        }

        $files = [];

        // Only scan specific source directories, not vendor
        $source_dirs = [
            $this->plugin_root . '/includes',
        ];

        // Also include main plugin file
        $main_file = $this->plugin_root . '/ewheel-importer.php';
        if ( file_exists( $main_file ) ) {
            $files[] = $main_file;
        }

        foreach ( $source_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && $file->getExtension() === 'php' ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::$cached_php_files = $files;
        return $files;
    }

    /**
     * Get relative path from plugin root.
     *
     * @param string $absolute_path Absolute file path.
     * @return string
     */
    protected function get_relative_path( string $absolute_path ): string {
        return str_replace( $this->plugin_root . '/', '', $absolute_path );
    }

    /**
     * Scan files for a regex pattern.
     *
     * @param string $pattern Regex pattern.
     * @return array<array{file: string, line: int, match: string}>
     */
    protected function scan_for_pattern( string $pattern ): array {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );
            $lines   = explode( "\n", $content );

            foreach ( $lines as $line_num => $line ) {
                // Skip comments
                if ( preg_match( '/^\s*(?:\/\/|\/\*|\*)/', $line ) ) {
                    continue;
                }

                if ( preg_match( $pattern, $line, $matches ) ) {
                    $violations[] = [
                        'file'  => $this->get_relative_path( $file ),
                        'line'  => $line_num + 1,
                        'match' => trim( substr( $matches[0], 0, 80 ) ),
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Format violations for error message.
     *
     * @param array $violations List of violations.
     * @return string
     */
    protected function format_violations( array $violations ): string {
        if ( empty( $violations ) ) {
            return '';
        }

        $messages = [];
        foreach ( $violations as $v ) {
            if ( isset( $v['line'] ) ) {
                $messages[] = "{$v['file']}:{$v['line']} - {$v['match']}";
            } else {
                $messages[] = "{$v['file']} - " . ( $v['pattern'] ?? $v['issue'] ?? '' );
            }
        }

        return "\n" . implode( "\n", $messages );
    }
}
