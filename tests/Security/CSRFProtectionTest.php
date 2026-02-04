<?php
/**
 * Security Tests: CSRF Protection.
 *
 * Verifies that AJAX handlers and form submissions have proper CSRF protection.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

/**
 * Test case for CSRF protection verification.
 */
class CSRFProtectionTest extends SecurityTestCase {

    /**
     * Test that all AJAX handlers verify nonces.
     */
    public function test_ajax_handlers_verify_nonces(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );

            // Find AJAX action registrations
            if ( preg_match_all( '/add_action\s*\(\s*[\'"]wp_ajax_([^\'"]+)[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/i', $content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $action_name = $match[1];
                    $method_name = $match[2];

                    // Extract method body using brace counting for proper nesting
                    $method_body = $this->extract_method_body( $content, $method_name );

                    if ( $method_body !== null ) {
                        $has_nonce_check = preg_match( '/check_ajax_referer|wp_verify_nonce/', $method_body );

                        if ( ! $has_nonce_check ) {
                            $violations[] = [
                                'file'   => $this->get_relative_path( $file ),
                                'action' => $action_name,
                                'method' => $method_name,
                                'issue'  => 'Missing nonce verification',
                            ];
                        }
                    }
                }
            }

            // Also check for wp_ajax_nopriv_ actions (public AJAX)
            if ( preg_match_all( '/add_action\s*\(\s*[\'"]wp_ajax_nopriv_([^\'"]+)[\'"]/i', $content, $matches ) ) {
                foreach ( $matches[1] as $action ) {
                    // Check if there's a corresponding nonce check somewhere in the file
                    if ( strpos( $content, 'check_ajax_referer' ) === false &&
                         strpos( $content, 'wp_verify_nonce' ) === false ) {
                        $violations[] = [
                            'file'   => $this->get_relative_path( $file ),
                            'action' => 'wp_ajax_nopriv_' . $action,
                            'method' => '',
                            'issue'  => 'Public AJAX action without nonce verification',
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found AJAX handlers without nonce verification: ' . $this->format_ajax_violations( $violations )
        );
    }

    /**
     * Extract the body of a method using brace counting.
     *
     * @param string $content     File content.
     * @param string $method_name Method name to find.
     * @return string|null Method body or null if not found.
     */
    private function extract_method_body( string $content, string $method_name ): ?string {
        // Find the function declaration
        $pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/s';
        if ( ! preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        $start = $match[0][1] + strlen( $match[0][0] ) - 1; // Position of opening brace
        $brace_count = 1;
        $pos         = $start + 1;
        $len         = strlen( $content );

        while ( $pos < $len && $brace_count > 0 ) {
            $char = $content[ $pos ];
            if ( $char === '{' ) {
                $brace_count++;
            } elseif ( $char === '}' ) {
                $brace_count--;
            }
            $pos++;
        }

        return substr( $content, $start + 1, $pos - $start - 2 );
    }

    /**
     * Test that forms include nonce fields.
     */
    public function test_forms_include_nonce_fields(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );

            // Find form tags
            if ( preg_match_all( '/<form[^>]*method\s*=\s*["\']post["\'][^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $match ) {
                    $form_start = $match[1];
                    $form_end   = strpos( $content, '</form>', $form_start );

                    if ( $form_end === false ) {
                        continue;
                    }

                    $form_content = substr( $content, $form_start, $form_end - $form_start );

                    // Check for nonce field or settings_fields (which includes nonce)
                    $has_nonce = preg_match( '/wp_nonce_field|settings_fields/', $form_content );

                    // Skip if it's a search form or external form
                    if ( strpos( $form_content, 'action="options.php"' ) !== false ||
                         strpos( $form_content, 'settings_fields' ) !== false ) {
                        continue;
                    }

                    if ( ! $has_nonce && strpos( $form_content, 'action=' ) !== false ) {
                        $line_num     = substr_count( substr( $content, 0, $form_start ), "\n" ) + 1;
                        $violations[] = [
                            'file'  => $this->get_relative_path( $file ),
                            'line'  => $line_num,
                            'issue' => 'Form without wp_nonce_field()',
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found forms without nonce fields: ' . $this->format_form_violations( $violations )
        );
    }

    /**
     * Test that capability checks accompany nonce checks.
     */
    public function test_capability_checks_with_nonces(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );

            // Find nonce verification
            if ( preg_match_all( '/(check_ajax_referer|wp_verify_nonce|check_admin_referer)\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $match ) {
                    $position = $match[1];

                    // Get surrounding context (function body)
                    $context_start = max( 0, $position - 500 );
                    $context_end   = min( strlen( $content ), $position + 500 );
                    $context       = substr( $content, $context_start, $context_end - $context_start );

                    // Check if capability check is present
                    $has_capability_check = preg_match( '/current_user_can|is_admin|wp_get_current_user/', $context );

                    if ( ! $has_capability_check ) {
                        $line_num     = substr_count( substr( $content, 0, $position ), "\n" ) + 1;
                        $violations[] = [
                            'file'  => $this->get_relative_path( $file ),
                            'line'  => $line_num,
                            'issue' => 'Nonce check without capability check',
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found nonce checks without capability checks: ' . $this->format_form_violations( $violations )
        );
    }

    /**
     * Test that nonces are created with wp_create_nonce.
     */
    public function test_nonces_are_properly_created(): void {
        $violations = [];

        foreach ( $this->get_php_files() as $file ) {
            $content = file_get_contents( $file );

            // Check if file has AJAX localization
            if ( preg_match( '/wp_localize_script.*nonce/i', $content ) ) {
                // Verify wp_create_nonce is used
                if ( strpos( $content, 'wp_create_nonce' ) === false ) {
                    $violations[] = [
                        'file'  => $this->get_relative_path( $file ),
                        'issue' => 'Nonce localization without wp_create_nonce()',
                    ];
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found nonce localization issues: ' . print_r( $violations, true )
        );
    }

    /**
     * Format AJAX violations for error message.
     *
     * @param array $violations List of violations.
     * @return string
     */
    private function format_ajax_violations( array $violations ): string {
        if ( empty( $violations ) ) {
            return '';
        }

        $messages = [];
        foreach ( $violations as $v ) {
            $messages[] = "{$v['file']}: wp_ajax_{$v['action']} ({$v['method']}) - {$v['issue']}";
        }

        return "\n" . implode( "\n", $messages );
    }

    /**
     * Format form violations for error message.
     *
     * @param array $violations List of violations.
     * @return string
     */
    private function format_form_violations( array $violations ): string {
        if ( empty( $violations ) ) {
            return '';
        }

        $messages = [];
        foreach ( $violations as $v ) {
            $line       = isset( $v['line'] ) ? ":{$v['line']}" : '';
            $messages[] = "{$v['file']}{$line} - {$v['issue']}";
        }

        return "\n" . implode( "\n", $messages );
    }
}
