<?php
/**
 * Field-level validation error collection.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * An ordered map of field name to human-readable error message.
 *
 * This is the PHP counterpart of the LeadFieldErrors type in the React
 * application, and it serialises to exactly the fieldErrors object that client
 * already consumes:
 *
 *     { "email": "Please enter a valid email address.", ... }
 *
 * Keys are field names as the form knows them, so the client can attach each
 * message to the right control without a translation table. One message per
 * field: the first one set wins, matching the React behaviour where a field's
 * first failing rule is the one shown.
 *
 * Messages are written for the person filling in the form. Nothing internal —
 * no column name, no SQL state, no exception text — is ever put in one.
 */
final class Field_Errors implements \Countable, \JsonSerializable {

	/**
	 * Field name => message.
	 *
	 * @var array<string,string>
	 */
	private array $errors = array();

	/**
	 * Builds a collection from an existing map.
	 *
	 * @param array<string,string> $errors Field name => message.
	 */
	public static function from( array $errors = array() ): self {
		$instance = new self();

		foreach ( $errors as $field => $message ) {
			$instance->add( (string) $field, (string) $message );
		}

		return $instance;
	}

	/**
	 * Records a message for a field, keeping the first one set.
	 *
	 * @param string $field   Field name.
	 * @param string $message Message for the person filling in the form.
	 */
	public function add( string $field, string $message ): self {
		$field = Sanitizer::identifier( $field, 64 );

		if ( '' === $field || '' === $message || isset( $this->errors[ $field ] ) ) {
			return $this;
		}

		$this->errors[ $field ] = $message;

		return $this;
	}

	/**
	 * Whether a field already has a message.
	 *
	 * @param string $field Field name.
	 */
	public function has( string $field ): bool {
		return isset( $this->errors[ Sanitizer::identifier( $field, 64 ) ] );
	}

	/**
	 * Whether anything failed.
	 */
	public function any(): bool {
		return array() !== $this->errors;
	}

	/**
	 * The field names that failed.
	 *
	 * @return string[]
	 */
	public function fields(): array {
		return array_keys( $this->errors );
	}

	/**
	 * The full map.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return $this->errors;
	}

	/**
	 * Serialises as a JSON object, never as an empty array.
	 *
	 * PHP encodes an empty array as [], which would change the response shape
	 * the client sees. Casting to an object keeps fieldErrors an object in
	 * every case.
	 *
	 * @return object
	 */
	public function jsonSerialize(): object {
		return (object) $this->errors;
	}

	/**
	 * Number of failing fields.
	 */
	public function count(): int {
		return count( $this->errors );
	}
}
