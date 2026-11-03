<?php
/**
 * A value's canonical JSON, for hashing.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * JSON with object keys sorted at every level, no escaped slashes or
 * Unicode, so the provider and the plugin hash a value the same way (D9).
 * Option values are PHP arrays, as WordPress stores them: an array with
 * string keys is an object, a list is an array, and an empty array is `[]`.
 */
final class Canonical_Json {

	/**
	 * Encodes a value.
	 *
	 * @param mixed $value The value.
	 */
	public static function encode( $value ): string {
		return (string) wp_json_encode( self::sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The value's SHA-256, of its canonical JSON.
	 *
	 * @param mixed $value The value.
	 */
	public static function sha256( $value ): string {
		return hash( 'sha256', self::encode( $value ) );
	}

	/**
	 * Sorts objects' keys, recursively.
	 *
	 * @param mixed $value The value.
	 * @return mixed
	 */
	private static function sort( $value ) {
		if ( is_object( $value ) ) {
			$properties = array_map( array( self::class, 'sort' ), get_object_vars( $value ) );
			ksort( $properties, SORT_STRING );
			return (object) $properties;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'sort' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
