<?php
/**
 * Minimal JSON Schema validator for tool inputs.
 *
 * Supports the subset our tools use: type, properties, required,
 * additionalProperties:false, enum, minimum, maximum, minLength, maxLength,
 * items, maxItems, default. Every tool call — from Claude, MCP or the
 * Abilities API — is validated before it executes.
 *
 * @package AICA
 */

namespace AICA\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Schema validator.
 */
class Schema_Validator {

	/**
	 * Validate and normalize input against a schema.
	 *
	 * @param mixed  $value  Input.
	 * @param array  $schema Schema.
	 * @param string $path   Path for error messages.
	 * @return array{valid:bool,errors:string[],value:mixed}
	 */
	public static function validate( $value, array $schema, $path = 'input' ) {
		$errors = array();
		$value  = self::check( $value, $schema, $path, $errors );
		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'value'  => $value,
		);
	}

	/**
	 * Recursive check.
	 *
	 * @param mixed    $value  Value.
	 * @param array    $schema Schema.
	 * @param string   $path   Path.
	 * @param string[] $errors Errors (by ref).
	 * @return mixed Normalized value.
	 */
	private static function check( $value, array $schema, $path, array &$errors ) {
		$type = isset( $schema['type'] ) ? $schema['type'] : null;

		switch ( $type ) {
			case 'object':
				if ( null === $value || ( is_array( $value ) && array() === $value ) ) {
					$value = array();
				} elseif ( is_object( $value ) ) {
					$value = (array) $value;
				}
				if ( ! is_array( $value ) || ( array_keys( $value ) === range( 0, count( $value ) - 1 ) && count( $value ) > 0 ) ) {
					$errors[] = "{$path} must be an object.";
					return $value;
				}
				$props = isset( $schema['properties'] ) ? $schema['properties'] : array();
				foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $req ) {
					if ( ! array_key_exists( $req, $value ) ) {
						$errors[] = "{$path}.{$req} is required.";
					}
				}
				if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
					foreach ( array_keys( $value ) as $k ) {
						if ( ! isset( $props[ $k ] ) ) {
							$errors[] = "{$path}.{$k} is not an allowed property.";
							unset( $value[ $k ] );
						}
					}
				}
				foreach ( $props as $k => $sub ) {
					if ( array_key_exists( $k, $value ) ) {
						$value[ $k ] = self::check( $value[ $k ], $sub, "{$path}.{$k}", $errors );
					} elseif ( array_key_exists( 'default', $sub ) ) {
						$value[ $k ] = $sub['default'];
					}
				}
				return $value;

			case 'array':
				if ( ! is_array( $value ) || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
					$errors[] = "{$path} must be an array.";
					return $value;
				}
				if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
					$errors[] = "{$path} must have at most {$schema['maxItems']} items.";
				}
				if ( isset( $schema['items'] ) ) {
					foreach ( $value as $i => $item ) {
						$value[ $i ] = self::check( $item, $schema['items'], "{$path}[{$i}]", $errors );
					}
				}
				break;

			case 'integer':
				if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
					$value = (int) $value; // Lenient coercion for MCP clients that stringify numbers.
				}
				if ( is_float( $value ) && floor( $value ) === $value ) {
					$value = (int) $value;
				}
				if ( ! is_int( $value ) ) {
					$errors[] = "{$path} must be an integer.";
					return $value;
				}
				break;

			case 'number':
				if ( is_string( $value ) && is_numeric( $value ) ) {
					$value = $value + 0;
				}
				if ( ! is_int( $value ) && ! is_float( $value ) ) {
					$errors[] = "{$path} must be a number.";
					return $value;
				}
				break;

			case 'boolean':
				if ( ! is_bool( $value ) ) {
					$errors[] = "{$path} must be a boolean.";
					return $value;
				}
				break;

			case 'string':
				if ( ! is_string( $value ) ) {
					$errors[] = "{$path} must be a string.";
					return $value;
				}
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
				if ( isset( $schema['maxLength'] ) && $len > $schema['maxLength'] ) {
					$errors[] = "{$path} must be at most {$schema['maxLength']} characters.";
				}
				if ( isset( $schema['minLength'] ) && $len < $schema['minLength'] ) {
					$errors[] = "{$path} must be at least {$schema['minLength']} characters.";
				}
				break;
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = "{$path} must be one of: " . implode( ', ', array_map( 'strval', $schema['enum'] ) ) . '.';
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$errors[] = "{$path} must be >= {$schema['minimum']}.";
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$errors[] = "{$path} must be <= {$schema['maximum']}.";
		}
		return $value;
	}
}
