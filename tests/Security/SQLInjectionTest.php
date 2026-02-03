<?php
/**
 * Security Tests: SQL Injection Detection.
 *
 * Scans plugin files for SQL injection vulnerabilities.
 *
 * @package Trotibike\EwheelImporter\Tests\Security
 */

namespace Trotibike\EwheelImporter\Tests\Security;

/**
 * Test case for detecting SQL injection vulnerabilities.
 */
class SQLInjectionTest extends SecurityTestCase {

    /**
     * SQL injection vulnerability patterns.
     *
     * @var array<string, string>
     */
    private array $sql_patterns = [
        'direct_variable_in_query'    => '/\$wpdb->(query|get_results|get_row|get_var|get_col)\s*\(\s*["\'][^"\']*\$(?!wpdb)/i',
        'concat_in_query'             => '/\$wpdb->(query|get_results|get_row|get_var|get_col)\s*\([^)]*\.\s*\$/i',
        'raw_sql_get'                 => '/SELECT.*FROM.*WHERE.*\$_GET/is',
        'raw_sql_post'                => '/SELECT.*FROM.*WHERE.*\$_POST/is',
        'raw_sql_request'             => '/SELECT.*FROM.*WHERE.*\$_REQUEST/is',
        'mysql_query'                 => '/mysql_query\s*\(/i',
    ];

    /**
     * Test that no files contain direct variable interpolation in SQL queries.
     */
    public function test_no_direct_variables_in_sql(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['direct_variable_in_query'] );

        $this->assertEmpty(
            $violations,
            'Found direct variable in SQL query (use $wpdb->prepare): ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no files contain string concatenation in SQL queries.
     */
    public function test_no_concatenation_in_sql(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['concat_in_query'] );

        $this->assertEmpty(
            $violations,
            'Found string concatenation in SQL query: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no raw SQL queries use $_GET directly.
     */
    public function test_no_raw_sql_with_get(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['raw_sql_get'] );

        $this->assertEmpty(
            $violations,
            'Found raw SQL with $_GET: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no raw SQL queries use $_POST directly.
     */
    public function test_no_raw_sql_with_post(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['raw_sql_post'] );

        $this->assertEmpty(
            $violations,
            'Found raw SQL with $_POST: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that no raw SQL queries use $_REQUEST directly.
     */
    public function test_no_raw_sql_with_request(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['raw_sql_request'] );

        $this->assertEmpty(
            $violations,
            'Found raw SQL with $_REQUEST: ' . $this->format_violations( $violations )
        );
    }

    /**
     * Test that deprecated mysql_query is not used.
     */
    public function test_no_mysql_query(): void {
        $violations = $this->scan_for_pattern( $this->sql_patterns['mysql_query'] );

        $this->assertEmpty(
            $violations,
            'Found deprecated mysql_query() usage: ' . $this->format_violations( $violations )
        );
    }
}
