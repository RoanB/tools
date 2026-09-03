<?php
/**
 * User
 *
 * Admin user model with authentication support.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class User {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Object\Uuid;

	/**
	 * Get user by email address
	 *
	 * @access public
	 * @param string $email
	 * @return User|null $user
	 */
	public static function get_by_email(string $email): ?self {
		$database = Database::get();
		$email = $database->escape($email);
		$id = $database->get_one('SELECT id FROM user WHERE email = \'' . $email . '\' AND archived IS NULL');

		if ($id === null) {
			return null;
		}

		return new self($id);
	}

	/**
	 * Verify a password against the stored hash
	 *
	 * @access public
	 * @param string $password
	 * @return bool $valid
	 */
	public function verify_password(string $password): bool {
		return password_verify($password, $this->password);
	}

	/**
	 * Hash a password for storage
	 *
	 * @access public
	 * @param string $password
	 * @return string $hash
	 */
	public static function hash_password(string $password): string {
		return password_hash($password, PASSWORD_DEFAULT);
	}

	/**
	 * Get the user's full name
	 *
	 * @access public
	 * @return string $full_name
	 */
	public function get_full_name(): string {
		return trim($this->firstname . ' ' . $this->lastname);
	}

	/**
	 * Set a new password for this user
	 *
	 * Validates minimum length and stores the hash.
	 *
	 * @access public
	 * @param string $password
	 * @return void
	 */
	public function set_password(string $password): void {
		if (Validation::password($password) === false) {
			throw new \Exception('Password not strong enough');
		}

		$this->password = password_hash($password, PASSWORD_DEFAULT);
	}

	/**
	 * Reset password
	 *
	 * Creates a new password reset token and sends the reset link to the user.
	 *
	 * @access public
	 * @return void
	 */
	public function reset_password(): void {
		$raw_token = bin2hex(random_bytes(32));
		\Password_Reset_Token::create_for_user($this, $raw_token);
		$this->send_password_reset_email($raw_token);
	}

	/**
	 * Reset password from token
	 *
	 * Sets a new password for the user owning a valid reset token and marks
	 * the token as used.
	 *
	 * @access public
	 * @param string $raw_token
	 * @param string $password
	 * @return \User $user
	 */
	public static function reset_password_from_token(string $raw_token, string $password): \User {
		$token = \Password_Reset_Token::get_by_raw_token($raw_token);
		if ($token === null) {
			throw new \Exception('Invalid or expired reset token');
		}

		$user = $token->get_user();
		$user->set_password($password);
		$user->save(false);
		$token->mark_used();

		return $user;
	}

	/**
	 * Send password reset email
	 *
	 * @access public
	 * @param string $raw_token
	 * @return void
	 */
	public function send_password_reset_email(string $raw_token): void {
		$config = \Skeleton\Core\Application::get()->config;
		$application = \Skeleton\Core\Application::get();

		$email = \Mailer::create('password_reset');
		$email->set_sender($config->email_from['from_email'], $config->email_from['from_name']);
		$email->add_to($this->email, $this->firstname);
		$email->assign('user', $this);
		$email->assign('reset_url', 'https://' . $application->hostname . '/login?action=reset_password&token=' . $raw_token);
		$email->send();
	}
}
