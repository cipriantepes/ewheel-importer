<?php
/**
 * Security Tests: File Integrity.
 *
 * Checks for unexpected files, suspicious modifications, and integrity issues.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

use Trotibike\EwheelImporter\Tests\TestCase;

/**
 * Test case for file integrity verification.
 */
class FileIntegrityTest extends TestCase {

    /**
     * Plugin root directory.
     *
     * @var string
     */
    private string $plugin_root;

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    private array $allowed_extensions = [
        'php',
        'js',
        'css',
        'json',
        'txt',
        'md',
        'xml',
        'pot',
        'po',
        'mo',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'svg',
        'ico',
        'woff',
        'woff2',
        'ttf',
        'eot',
        'map',
        'lock',
        'yml',
        'yaml',
        'neon',
        'dist',
    ];

    /**
     * Suspicious filenames that might indicate backdoors.
     *
     * @var array<string>
     */
    private array $suspicious_filenames = [
        'shell.php',
        'c99.php',
        'r57.php',
        'wso.php',
        'b374k.php',
        'backdoor.php',
        'hack.php',
        'malware.php',
        'evil.php',
        'upload.php',
        'uploader.php',
        'filemanager.php',
        'fm.php',
        'adminer.php',
        'phpinfo.php',
        'info.php',
        'test.php',
        'tmp.php',
        'temp.php',
        'cache.php',
        '.htaccess.php',
        'wp-config.php',
        '0.php',
        '1.php',
        'x.php',
        'a.php',
    ];

    /**
     * Set up test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->plugin_root = dirname( __DIR__, 2 );
    }

    /**
     * Get all files in the plugin source directories (excluding vendor).
     *
     * @return array<string>
     */
    private function get_source_files(): array {
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
                if ( $file->isFile() ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Get PHP files in the plugin source directories.
     *
     * @return array<string>
     */
    private function get_php_files(): array {
        return array_filter(
            $this->get_source_files(),
            function ( $file ) {
                return pathinfo( $file, PATHINFO_EXTENSION ) === 'php';
            }
        );
    }

    /**
     * Test that no files have suspicious extensions.
     */
    public function test_no_suspicious_file_extensions(): void {
        $violations = [];

        foreach ( $this->get_source_files() as $file ) {
            $extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

            // Skip files without extension or with allowed extension
            if ( empty( $extension ) || in_array( $extension, $this->allowed_extensions, true ) ) {
                continue;
            }

            // Flag suspicious extensions
            $suspicious_extensions = [ 'phtml', 'phar', 'inc', 'bak', 'old', 'tmp', 'exe', 'sh', 'bat' ];
            if ( in_array( $extension, $suspicious_extensions, true ) ) {
                $violations[] = $this->get_relative_path( $file );
            }
        }

        $this->assertEmpty(
            $violations,
            'Found files with suspicious extensions: ' . implode( ', ', $violations )
        );
    }

    /**
     * Test that no files have suspicious names.
     */
    public function test_no_suspicious_filenames(): void {
        $violations = [];

        foreach ( $this->get_source_files() as $file ) {
            $filename = strtolower( basename( $file ) );

            // Check against suspicious filenames
            if ( in_array( $filename, $this->suspicious_filenames, true ) ) {
                $violations[] = $this->get_relative_path( $file );
            }

            // Check for obfuscated filenames
            if ( preg_match( '/^[a-z0-9]{32}\.php$/i', $filename ) || // MD5 hash
                 preg_match( '/^[a-z0-9]{40}\.php$/i', $filename ) || // SHA1 hash
                 preg_match( '/^\.[^\.]+\.php$/i', $filename ) ) {    // Hidden PHP
                $violations[] = $this->get_relative_path( $file ) . ' (obfuscated name)';
            }
        }

        $this->assertEmpty(
            $violations,
            'Found files with suspicious names: ' . implode( ', ', $violations )
        );
    }

    /**
     * Test that no hidden PHP files exist.
     */
    public function test_no_hidden_php_files(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $filename = basename( $file );

            // Check for hidden PHP files (starting with dot)
            if ( str_starts_with( $filename, '.' ) && str_ends_with( strtolower( $filename ), '.php' ) ) {
                $violations[] = $this->get_relative_path( $file );
            }
        }

        $this->assertEmpty(
            $violations,
            'Found hidden PHP files: ' . implode( ', ', $violations )
        );
    }

    /**
     * Test that PHP files have proper opening tags.
     */
    public function test_php_files_have_proper_opening_tags(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );
            $content = ltrim( $content );

            // Check for proper PHP opening tag
            if ( ! str_starts_with( $content, '<?php' ) &&
                 ! str_starts_with( $content, '<?=' ) ) {
                $violations[] = $this->get_relative_path( $file );
            }

            // Check for suspicious multi-opening tags (obfuscation technique)
            // Skip template files as they naturally have many opening tags
            $is_template = strpos( $file, 'template' ) !== false || strpos( $file, 'view' ) !== false;
            $max_tags    = $is_template ? 100 : 10;
            $tag_count   = substr_count( $content, '<?php' );
            if ( $tag_count > $max_tags ) {
                $violations[] = $this->get_relative_path( $file ) . " ({$tag_count} opening tags - suspicious)";
            }
        }

        $this->assertEmpty(
            $violations,
            'Found PHP files with improper opening tags: ' . implode( ', ', $violations )
        );
    }

    /**
     * Test that no files contain encoded/obfuscated content.
     */
    public function test_no_obfuscated_content(): void {
        $violations = [];

        $obfuscation_patterns = [
            'long_base64'         => '/[a-zA-Z0-9+\/]{500,}={0,2}/',
            'hex_sequences'       => '/\\\\x[0-9a-fA-F]{2}(?:\\\\x[0-9a-fA-F]{2}){50,}/',
            'char_codes'          => '/chr\(\d+\)(?:\s*\.\s*chr\(\d+\)){20,}/',
            'gzinflate_pattern'   => '/gzinflate\s*\(\s*(?:str_rot13\s*\(\s*)?base64_decode/i',
            'eval_gzinflate'      => '/eval\s*\(\s*gzinflate/i',
            'ionCube'             => '/ionCube/',
            'Zend_Optimizer'      => '/Zend Optimizer/',
            'SourceGuardian'      => '/SourceGuardian/',
        ];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );

            foreach ( $obfuscation_patterns as $name => $pattern ) {
                if ( preg_match( $pattern, $content ) ) {
                    $violations[] = $this->get_relative_path( $file ) . " ({$name})";
                    break; // One violation per file is enough
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found files with obfuscated content: ' . implode( ', ', $violations )
        );
    }

    /**
     * Test that no files have excessive line length (potential encoded payloads).
     */
    public function test_no_excessive_line_length(): void {
        $violations   = [];
        $max_line_len = 2000; // Reasonable max for non-minified code

        foreach ( $this->get_php_files() as $file ) {
            // Skip minified files
            if ( strpos( $file, '.min.' ) !== false ) {
                continue;
            }

            $lines = file( $file );
            foreach ( $lines as $line_num => $line ) {
                $length = strlen( $line );
                if ( $length > $max_line_len ) {
                    $violations[] = $this->get_relative_path( $file ) .
                        ":$line_num ({$length} chars)";
                    break; // One per file
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found files with excessive line length (possible encoded payload): ' . implode( ', ', $violations )
        );
    }

    /**
     * Test main plugin file has correct headers.
     */
    public function test_main_plugin_file_has_correct_headers(): void {
        $main_file = $this->plugin_root . '/ewheel-importer.php';

        $this->assertFileExists( $main_file, 'Main plugin file not found' );

        $content = file_get_contents( $main_file );

        // Check for required WordPress plugin headers
        $this->assertStringContainsString( 'Plugin Name:', $content, 'Missing Plugin Name header' );
        $this->assertStringContainsString( 'Version:', $content, 'Missing Version header' );
        // Check for ABSPATH check (flexible about spacing)
        $this->assertTrue(
            strpos( $content, "defined('ABSPATH')" ) !== false ||
            strpos( $content, "defined( 'ABSPATH' )" ) !== false,
            'Missing ABSPATH check'
        );
    }

    /**
     * Get relative path from plugin root.
     *
     * @param string $absolute_path Absolute file path.
     * @return string
     */
    private function get_relative_path( string $absolute_path ): string {
        return str_replace( $this->plugin_root . '/', '', $absolute_path );
    }
}
