<?php
/**
 * WordPress function stubs for testing without WordPress.
 */

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return htmlspecialchars( strip_tags( $str ), ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $parsed_args = get_object_vars( $args );
        } elseif ( is_array( $args ) ) {
            $parsed_args = &$args;
        } else {
            wp_parse_str( $args, $parsed_args );
        }

        return array_merge( $defaults, $parsed_args );
    }
}

if ( ! function_exists( 'wp_parse_str' ) ) {
    function wp_parse_str( $input_string, &$result ) {
        parse_str( $input_string, $result );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = 'default' ) {
        echo $text;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) {
        if ( is_array( $args ) ) {
            $query = http_build_query( $args );
            $separator = strpos( $url, '?' ) !== false ? '&' : '?';
            return $url . $separator . $query;
        }
        return $url;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $wp_transients;
        if ( ! isset( $wp_transients ) ) {
            $wp_transients = [];
        }
        return $wp_transients[ $transient ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $wp_transients;
        if ( ! isset( $wp_transients ) ) {
            $wp_transients = [];
        }
        $wp_transients[ $transient ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        global $wp_transients;
        if ( ! isset( $wp_transients ) ) {
            $wp_transients = [];
        }
        unset( $wp_transients[ $transient ] );
        return true;
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
        $title = strip_tags( $title );
        $title = preg_replace( '/[^a-z0-9\s\-_]/i', '', $title );
        $title = strtolower( trim( $title ) );
        $title = preg_replace( '/[\s\-]+/', '-', $title );
        $title = trim( $title, '-' );
        return empty( $title ) ? $fallback_title : $title;
    }
}

if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return $number === 1 ? $single : $plural;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( $min = 0, $max = 0 ) {
        return random_int( $min, $max );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        switch ( $type ) {
            case 'mysql':
                return gmdate( 'Y-m-d H:i:s' );
            case 'timestamp':
                return time();
            case 'H:i:s':
                return gmdate( 'H:i:s' );
            default:
                return gmdate( $type );
        }
    }
}
