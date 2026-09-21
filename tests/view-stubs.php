<?php
/**
 * Stand-ins for the WordPress template functions the admin views call. Loaded by
 * the view tests only; every function is guarded so the file is harmless under WordPress.
 *
 * @package Sitelemetry_Audit
 */

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Echo escaped.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Echo escaped.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Escaped translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Nonce field.
	 *
	 * @return void
	 */
	function wp_nonce_field() {
		echo '<input type="hidden" name="_wpnonce" value="test-nonce">';
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Nonce URL.
	 *
	 * @param string $url    URL.
	 * @param string $action Action.
	 * @return string
	 */
	function wp_nonce_url( $url, $action = -1 ) {
		return $url . '&_wpnonce=test-nonce';
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Nonce.
	 *
	 * @return string
	 */
	function wp_create_nonce() {
		return 'test-nonce';
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	/**
	 * Submit button.
	 *
	 * @param string $text             Text.
	 * @param string $type             Type.
	 * @param string $name             Name.
	 * @param bool   $wrap             Wrap.
	 * @param array  $other_attributes Attributes.
	 * @return void
	 */
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
		$attributes = '';
		if ( is_array( $other_attributes ) ) {
			foreach ( $other_attributes as $key => $value ) {
				$attributes .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
			}
		}
		echo ( $wrap ? '<p class="submit">' : '' ) . '<input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '"' . $attributes . '>' . ( $wrap ? '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
if ( ! function_exists( 'selected' ) ) {
	/**
	 * Selected attribute.
	 *
	 * @param mixed $selected Value.
	 * @param mixed $current  Current.
	 * @return void
	 */
	function selected( $selected, $current = true ) {
		echo (string) $selected === (string) $current ? ' selected="selected"' : '';
	}
}
if ( ! function_exists( 'checked' ) ) {
	/**
	 * Checked attribute.
	 *
	 * @param mixed $checked Value.
	 * @param mixed $current Current.
	 * @return void
	 */
	function checked( $checked, $current = true ) {
		echo (string) $checked === (string) $current ? ' checked="checked"' : '';
	}
}
if ( ! function_exists( 'settings_fields' ) ) {
	/**
	 * Settings fields.
	 *
	 * @param string $option_group Group.
	 * @return void
	 */
	function settings_fields( $option_group ) {
		echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '"><input type="hidden" name="action" value="update">';
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Date.
	 *
	 * @param string $format    Format.
	 * @param int    $timestamp Timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( 'Y-m-d H:i', (int) $timestamp );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Admin URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Query args (array form and key/value form).
	 *
	 * @return string
	 */
	function add_query_arg() {
		$args = func_get_args();
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = isset( $args[1] ) ? $args[1] : '';
		} else {
			$params = array( $args[0] => $args[1] );
			$url    = isset( $args[2] ) ? $args[2] : '';
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $params );
	}
}
