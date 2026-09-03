<?php
/**
 * Password_Reset_Token
 *
 * Represents a single password reset token issued for a user. The token value
 * itself is never stored in plain text; only its SHA-256 hash is persisted.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Password_Reset_Token {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;

	/**
	 * Class configuration
	 *
	 * The table is named user_password_reset_token, while the autoloader
	 * derives password_reset_token from the class name.
	 *
	 * @access protected
	 * @var array $class_configuration
	 */
	protected static $class_configuration = [
		'database_table' => 'user_password_reset_token',
	];

	/**
	 * Number of seconds a token stays valid
	 *
	 * @var int $validity_seconds
	 */
	const VALIDITY_SECONDS = 86400;

	/**
	 * Create a new reset token for a user
	 *
	 * The raw token is handed back in the reset e-mail, only its hash is stored.
	 *
	 * @access public
	 * @param \User $user
	 * @param string $raw_token
	 * @return Password_Reset_Token $token
	 */
	public static function create_for_user(\User $user, string $raw_token): Password_Reset_Token {
		$token = new self();
		$token->user_id = $user->id;
		$token->token = self::hash_token($raw_token);
		$token->expires_at = date('Y-m-d H:i:s', time() + self::VALIDITY_SECONDS);
		$token->save();

		return $token;
	}

	/**
	 * Get a still-valid token by its raw value
	 *
	 * Returns null when the token does not exist, is used, expired or archived.
	 *
	 * @access public
	 * @param string $raw_token
	 * @return ?Password_Reset_Token $token
	 */
	public static function get_by_raw_token(string $raw_token): ?Password_Reset_Token {
		$database = Database::get();
		$id = $database->get_one(
			'SELECT `id` FROM `user_password_reset_token`
			WHERE `token` = ?
			AND `used` = 0
			AND `archived` IS NULL
			AND `expires_at` > NOW()',
			[ self::hash_token($raw_token) ]
		);

		if ($id === null) {
			return null;
		}

		return self::get_by_id($id);
	}

	/**
	 * Get the user this token belongs to
	 *
	 * @access public
	 * @return \User $user
	 */
	public function get_user(): \User {
		return \User::get_by_id($this->user_id);
	}

	/**
	 * Mark the token as used
	 *
	 * @access public
	 */
	public function mark_used(): void {
		$this->used = true;
		$this->save();
	}

	/**
	 * Hash a raw token value
	 *
	 * @access private
	 * @param string $raw_token
	 * @return string $hash
	 */
	private static function hash_token(string $raw_token): string {
		return hash('sha256', $raw_token);
	}
}
