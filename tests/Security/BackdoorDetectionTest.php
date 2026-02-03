<?php
/**
 * Security Tests: Backdoor Detection.
 *
 * Scans plugin files for common backdoor patterns and malware signatures.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

/**
 * Test case for detecting backdoors and malware patterns.
 */
class BackdoorDetectionTest extends SecurityTestCase {

    /**
     * Dangerous patterns that indicate potential backdoors.
     *
     * @var array<string, string>
     */
    private array $backdoor_patterns = [
        'eval_execution'           => '/\beval\s*\(/i',
        'base64_decode_execution'  => '/base64_decode\s*\([^)]*\$/',
        'gzinflate_execution'      => '/gzinflate\s*\(\s*base64_decode/i',
        'gzuncompress_execution'   => '/gzuncompress\s*\(\s*base64_decode/i',
        'str_rot13_execution'      => '/str_rot13\s*\(\s*base64_decode/i',
        'preg_replace_eval'        => '/preg_replace\s*\([^,]*[\'"]\/[^\/]*e[\'"]/',
        'create_function'          => '/\bcreate_function\s*\(/i',
        'assert_execution'         => '/\bassert\s*\(\s*\$/i',
        'variable_function_call'   => '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\(\s*\$/',
        'shell_exec'               => '/\bshell_exec\s*\(/i',
        'exec_call'                => '/\bexec\s*\(\s*\$/i',
        'system_call'              => '/\bsystem\s*\(\s*\$/i',
        'passthru_call'            => '/\bpassthru\s*\(/i',
        'popen_call'               => '/\bpopen\s*\(/i',
        'proc_open_call'           => '/\bproc_open\s*\(/i',
        'pcntl_exec_call'          => '/\bpcntl_exec\s*\(/i',
        'curl_exec_file_put'       => '/curl_exec.*file_put_contents/is',
        'file_get_contents_eval'   => '/file_get_contents.*eval/is',
        'include_remote'           => '/include\s*\(\s*[\'"]https?:/',
        'require_remote'           => '/require\s*\(\s*[\'"]https?:/',
        'fwrite_php'               => '/fwrite\s*\([^,]+,\s*[\'"]<\?php/i',
        'file_put_contents_php'    => '/file_put_contents\s*\([^,]+,\s*[\'"]<\?php/i',
        'hex_encoded_eval'         => '/\\\\x[0-9a-fA-F]{2}.*eval/i',
        'chr_obfuscation'          => '/chr\s*\(\s*\d+\s*\).*chr\s*\(\s*\d+\s*\).*chr/i',
        'wordpress_backdoor_1'     => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"][^\'"]+[\'"]\s*\]\s*\(/i',
        'wordpress_backdoor_2'     => '/\$\{[\'"]\\\\x/',
        'hidden_admin_creation'    => '/wp_insert_user.*administrator/is',
        'webshell_pattern'         => '/\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"](?:cmd|exec|shell|c|e|command)[\'"]\s*\]/i',
    ];

    /**
     * Suspicious strings that warrant investigation.
     *
     * @var array<string>
     */
    private array $suspicious_strings = [
        'FilesMan',
        'r57shell',
        'c99shell',
        'WSO ',
        'b374k',
        'Alfa Shell',
        'AnonymousFox',
        'Gh0st',
        'Backdoor',
        'Webshell',
        'phpspy',
        'PwnedBackdoor',
    ];

    /**
     * Test that no files contain eval() with dynamic content.
     */
    public function test_no_eval_with_dynamic_content(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['eval_execution'] );

        $this->assertEmpty(
            $violations,
            'Found eval() usage which could be a backdoor: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain base64_decode with variables.
     */
    public function test_no_dynamic_base64_decode(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['base64_decode_execution'] );

        $this->assertEmpty(
            $violations,
            'Found base64_decode with variable input: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain gzinflate(base64_decode()) pattern.
     */
    public function test_no_gzinflate_base64_decode(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['gzinflate_execution'] );

        $this->assertEmpty(
            $violations,
            'Found gzinflate(base64_decode()) pattern: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain shell_exec().
     */
    public function test_no_shell_exec(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['shell_exec'] );

        $this->assertEmpty(
            $violations,
            'Found shell_exec() usage: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain exec() with dynamic content.
     */
    public function test_no_dynamic_exec(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['exec_call'] );

        $this->assertEmpty(
            $violations,
            'Found exec() with variable input: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain passthru().
     */
    public function test_no_passthru(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['passthru_call'] );

        $this->assertEmpty(
            $violations,
            'Found passthru() usage: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain create_function().
     */
    public function test_no_create_function(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['create_function'] );

        $this->assertEmpty(
            $violations,
            'Found create_function() usage (deprecated and dangerous): ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain remote file inclusion.
     */
    public function test_no_remote_file_inclusion(): void {
        $include_violations = $this->scan_for_pattern( $this->backdoor_patterns['include_remote'] );
        $require_violations = $this->scan_for_pattern( $this->backdoor_patterns['require_remote'] );
        $violations         = array_merge( $include_violations, $require_violations );

        $this->assertEmpty(
            $violations,
            'Found remote file inclusion: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain webshell patterns.
     */
    public function test_no_webshell_patterns(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['webshell_pattern'] );

        $this->assertEmpty(
            $violations,
            'Found webshell pattern: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain superglobal execution patterns.
     */
    public function test_no_superglobal_execution(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['wordpress_backdoor_1'] );

        $this->assertEmpty(
            $violations,
            'Found superglobal execution pattern: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain known webshell signatures.
     */
    public function test_no_known_webshell_signatures(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );
            foreach ( $this->suspicious_strings as $signature ) {
                if ( stripos( $content, $signature ) !== false ) {
                    $violations[] = [
                        'file'    => $this->get_relative_path( $file ),
                        'pattern' => "Known webshell signature: {$signature}",
                    ];
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found known webshell signatures: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain hidden admin user creation.
     */
    public function test_no_hidden_admin_creation(): void {
        $violations = $this->scan_for_pattern( $this->backdoor_patterns['hidden_admin_creation'] );

        $this->assertEmpty(
            $violations,
            'Found hidden admin user creation: ' . $this->format_violations( $violations )
        );
    }
}
