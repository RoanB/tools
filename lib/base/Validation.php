<?php
/**
 * Validation
 *
 * Simple input validations used by the models. Rules are deliberately lenient:
 * they reject clearly wrong input without being strict about country-specific
 * formats.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

class Validation {

	/**
	 * Check if a password is strong enough
	 *
	 * @access public
	 * @param string $password
	 * @return bool $valid
	 */
	public static function password(string $password): bool {
		return strlen($password) >= 8;
	}

	/**
	 * Check if a phone number looks valid
	 *
	 * Only digits are considered; an empty number is only invalid when it is
	 * mandatory.
	 *
	 * @access public
	 * @param string $phone
	 * @param mixed $country
	 * @param bool $mandatory
	 * @return bool $valid
	 */
	public static function phone(string $phone, $country, bool $mandatory = false): bool {
		if (empty($phone) === true) {
			return $mandatory === false;
		}

		$digits = preg_replace('/[^0-9]/', '', $phone);
		$length = strlen($digits);

		return $length >= 7 && $length <= 15;
	}

	/**
	 * Check if a zipcode looks valid
	 *
	 * @access public
	 * @param string $zipcode
	 * @param mixed $country
	 * @return bool $valid
	 */
	public static function zipcode(string $zipcode, $country): bool {
		if (empty($zipcode) === true) {
			return true;
		}

		$normalized = preg_replace('/\s+/', '', $zipcode);
		$length = strlen($normalized);

		return $length >= 3 && $length <= 10;
	}
}
