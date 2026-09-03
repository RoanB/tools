<?php
/**
 * Module Login
 *
 * Handles user authentication.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;
use Skeleton\Core\Http\Session;
use User;

class Login extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'login.twig';


	/**
	 * Login required
	 *
	 * @access protected
	 * @var bool $login_required
	 */
	protected bool $login_required = false;

	/**
	 * Display the login form
	 *
	 * @access public
	 */
	public function display(): void {
	}

	/**
	 * Handle login submission
	 *
	 * Assigns the error to the template so it is shown after a failed attempt,
	 * or logs the user in and redirects on success.
	 *
	 * @access public
	 */
	public function display_login(): void {
		$template = Template::get();

		$email = $_POST['email'] ?? '';
		$password = $_POST['password'] ?? '';

		if (empty($email) || empty($password)) {
			$template->assign('error', 'Please enter both email and password.');
			return;
		}

		$user = User::get_by_email($email);

		if ($user === null || $user->verify_password($password) === false) {
			$template->assign('error', 'Invalid email or password.');
			return;
		}

		$_SESSION['user_id'] = $user->id;

		Session::redirect('/');
	}

	/**
	 * Forgot password
	 *
	 * Shows the e-mail form and, on submit, sends a password reset link when
	 * the e-mail is known. Whether the e-mail exists is never revealed.
	 *
	 * @access public
	 */
	public function display_forgot_password(): void {
		$this->template = 'forgot_password.twig';
		$template = Template::get();
		$errors = [];

		if (isset($_POST['email'])) {
			if (empty($_POST['email']) === true) {
				$errors['email'] = 'mandatory';
				$template->assign('errors', $errors);
				return;
			}

			try {
				$user = User::get_by_email($_POST['email']);
				$user->reset_password();
			} catch (\Exception $e) {
				error_log('Password reset e-mail failed: ' . $e->getMessage());
			}

			Session::set_sticky('message', 'password_reset_email_sent');
			Session::redirect('/login');
		}

		$template->assign('errors', $errors);
	}

	/**
	 * Reset password
	 *
	 * Shows the new password form when a valid token is given and stores the
	 * new password on submit.
	 *
	 * @access public
	 */
	public function display_reset_password(): void {
		$this->template = 'reset_password.twig';
		$template = Template::get();

		$raw_token = $_POST['token'] ?? $_GET['token'] ?? null;
		if (is_string($raw_token) === false || empty($raw_token) === true) {
			Session::set_sticky('message', 'reset_token_invalid');
			Session::redirect('/login');
		}

		if (\Password_Reset_Token::get_by_raw_token($raw_token) === null) {
			Session::set_sticky('message', 'reset_token_invalid');
			Session::redirect('/login');
		}

		$errors = [];
		if (isset($_POST['password'])) {
			if (empty($_POST['password']) === true) {
				$errors['password'] = 'mandatory';
			} else {
				try {
					User::reset_password_from_token($raw_token, $_POST['password']);
					Session::set_sticky('message', 'password_reset_success');
					Session::redirect('/login');
				} catch (\Exception $e) {
					$errors['password'] = 'invalid';
				}
			}
		}

		$template->assign('errors', $errors);
		$template->assign('token', $raw_token);
	}
}
