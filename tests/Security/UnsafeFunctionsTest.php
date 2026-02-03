<?php
/**
 * Security Tests: Unsafe Functions Detection.
 *
 * Scans plugin files for dangerous PHP functions and insecure patterns.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

/**
 * Test case for detecting unsafe functions.
 */
class UnsafeFunctionsTest extends SecurityTestCase {

    /**
     * Dangerous functions that should never be used.
     *
     * @var array<string, string>
     */
    private array $dangerous_functions = [
        'eval'                 => '/\beval\s*\(/i',
        'exec'                 => '/\bexec\s*\(/i',
        'shell_exec'           => '/\bshell_exec\s*\(/i',
        'system'               => '/\bsystem\s*\(/i',
        'passthru'             => '/\bpassthru\s*\(/i',
        'popen'                => '/\bpopen\s*\(/i',
        'proc_open'            => '/\bproc_open\s*\(/i',
        'pcntl_exec'           => '/\bpcntl_exec\s*\(/i',
        'assert'               => '/\bassert\s*\(\s*\$/i', // assert with variable
        'create_function'      => '/\bcreate_function\s*\(/i',
        'call_user_func'       => '/\bcall_user_func\s*\(\s*\$_/i', // with user input
        'call_user_func_array' => '/\bcall_user_func_array\s*\(\s*\$_/i',
        'extract'              => '/\bextract\s*\(\s*\$_/i', // with user input
        'parse_str'            => '/\bparse_str\s*\([^,]+\)/i', // without second param
        'putenv'               => '/\bputenv\s*\(/i',
        'ini_set'              => '/\bini_set\s*\(\s*\$_/i', // with user input
        'preg_replace_e'       => '/preg_replace\s*\([^,]*\/[^\/]*e/i', // /e modifier
        'unserialize'          => '/\bunserialize\s*\(\s*\$_/i', // with user input
        'mail'                 => '/\bmail\s*\(\s*\$_/i', // with user input headers
    ];

    /**
     * Functions that require careful review.
     *
     * @var array<string, string>
     */
    private array $review_functions = [
        'file_get_contents'    => '/\bfile_get_contents\s*\(\s*\$_/i',
        'file_put_contents'    => '/\bfile_put_contents\s*\(/i',
        'fopen'                => '/\bfopen\s*\(\s*\$_/i',
        'fwrite'               => '/\bfwrite\s*\(/i',
        'include_var'          => '/\binclude\s*\(\s*\$/i',
        'require_var'          => '/\brequire\s*\(\s*\$/i',
        'include_once_var'     => '/\binclude_once\s*\(\s*\$/i',
        'require_once_var'     => '/\brequire_once\s*\(\s*\$/i',
    ];

    /**
     * Deprecated/insecure PHP functions.
     *
     * @var array<string, string>
     */
    private array $deprecated_functions = [
        'mysql_query'          => '/\bmysql_query\s*\(/i',
        'mysql_connect'        => '/\bmysql_connect\s*\(/i',
        'mysql_real_escape_string' => '/\bmysql_real_escape_string\s*\(/i',
        'ereg'                 => '/\bereg\s*\(/i',
        'eregi'                => '/\beregi\s*\(/i',
        'split'                => '/\bsplit\s*\(/i',
        'spliti'               => '/\bspliti\s*\(/i',
        'mcrypt_encrypt'       => '/\bmcrypt_encrypt\s*\(/i',
        'mcrypt_decrypt'       => '/\bmcrypt_decrypt\s*\(/i',
    ];

    /**
     * Insecure configuration patterns.
     *
     * @var array<string, string>
     */
    private array $insecure_patterns = [
        'disable_ssl'          => '/CURLOPT_SSL_VERIFYPEER\s*,\s*false/i',
        'disable_ssl_host'     => '/CURLOPT_SSL_VERIFYHOST\s*,\s*0/i',
        'allow_url_fopen'      => '/ini_set\s*\(\s*[\'"]allow_url_fopen[\'"]\s*,\s*[\'"]?1/i',
        'allow_url_include'    => '/ini_set\s*\(\s*[\'"]allow_url_include[\'"]\s*,\s*[\'"]?1/i',
        'display_errors'       => '/ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?1/i',
        'weak_crypto'          => '/md5\s*\(\s*\$_(GET|POST|REQUEST)/i',
        'weak_hash'            => '/sha1\s*\(\s*\$_(GET|POST|REQUEST)/i',
    ];

    /**
     * Test that no dangerous functions are used.
     */
    public function test_no_dangerous_functions(): void {
        $violations = [];

        foreach ( $this->dangerous_functions as $name => $pattern ) {
            $found = $this->scan_for_pattern( $pattern );
            if ( ! empty( $found ) ) {
                $violations[ $name ] = $found;
            }
        }

        $this->assertEmpty(
            $violations,
            'Found dangerous function usage: ' . $this->format_categorized_violations( $violations )
        );
    }

    /**
     * Test that no deprecated functions are used.
     */
    public function test_no_deprecated_functions(): void {
        $violations = [];

        foreach ( $this->deprecated_functions as $name => $pattern ) {
            $found = $this->scan_for_pattern( $pattern );
            if ( ! empty( $found ) ) {
                $violations[ $name ] = $found;
            }
        }

        $this->assertEmpty(
            $violations,
            'Found deprecated function usage: ' . $this->format_categorized_violations( $violations )
        );
    }

    /**
     * Test that no insecure configuration patterns exist.
     */
    public function test_no_insecure_configurations(): void {
        $violations = [];

        foreach ( $this->insecure_patterns as $name => $pattern ) {
            $found = $this->scan_for_pattern( $pattern );
            if ( ! empty( $found ) ) {
                $violations[ $name ] = $found;
            }
        }

        $this->assertEmpty(
            $violations,
            'Found insecure configuration: ' . $this->format_categorized_violations( $violations )
        );
    }

    /**
     * Test that curl operations verify SSL.
     */
    public function test_curl_verifies_ssl(): void {
        $violations = array_merge(
            $this->scan_for_pattern( $this->insecure_patterns['disable_ssl'] ),
            $this->scan_for_pattern( $this->insecure_patterns['disable_ssl_host'] )
        );

        $this->assertEmpty(
            $violations,
            'Found disabled SSL verification: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no eval or similar dynamic code execution exists.
     */
    public function test_no_dynamic_code_execution(): void {
        $violations = [];

        $dynamic_patterns = [
            $this->dangerous_functions['eval'],
            $this->dangerous_functions['create_function'],
            $this->dangerous_functions['assert'],
        ];

        foreach ( $dynamic_patterns as $pattern ) {
            $found      = $this->scan_for_pattern( $pattern );
            $violations = array_merge( $violations, $found );
        }

        $this->assertEmpty(
            $violations,
            'Found dynamic code execution: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that unserialize is not used with user input.
     */
    public function test_no_unsafe_unserialize(): void {
        $violations = $this->scan_for_pattern( $this->dangerous_functions['unserialize'] );

        $this->assertEmpty(
            $violations,
            'Found unsafe unserialize with user input: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that extract is not used with user input.
     */
    public function test_no_unsafe_extract(): void {
        $violations = $this->scan_for_pattern( $this->dangerous_functions['extract'] );

        $this->assertEmpty(
            $violations,
            'Found unsafe extract with user input: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that include/require don't use variables directly.
     */
    public function test_no_variable_includes(): void {
        $violations = array_merge(
            $this->scan_for_pattern( $this->review_functions['include_var'] ),
            $this->scan_for_pattern( $this->review_functions['require_var'] ),
            $this->scan_for_pattern( $this->review_functions['include_once_var'] ),
            $this->scan_for_pattern( $this->review_functions['require_once_var'] )
        );

        // Filter out safe patterns (like requiring files from plugin directory)
        $violations = array_filter(
            $violations,
            function ( $v ) {
                // Allow includes using plugin path constants
                return strpos( $v['match'], 'EWHEEL_IMPORTER_PATH' ) === false &&
                       strpos( $v['match'], 'plugin_dir_path' ) === false &&
                       strpos( $v['match'], '__DIR__' ) === false &&
                       strpos( $v['match'], 'dirname' ) === false;
            }
        );

        $this->assertEmpty(
            $violations,
            'Found variable include/require: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Format categorized violations for error message.
     *
     * @param array $violations Categorized violations.
     * @return string
     */
    private function format_categorized_violations( array $violations ): string {
        if ( empty( $violations ) ) {
            return '';
        }

        $messages = [];
        foreach ( $violations as $category => $items ) {
            $messages[] = "\n[{$category}]";
            foreach ( $items as $v ) {
                $messages[] = "  {$v['file']}:{$v['line']} - {$v['match']}";
            }
        }

        return implode( "\n", $messages );
    }
}
