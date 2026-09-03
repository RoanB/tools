<?php
/**
 * Module Logout
 *
 * Destroys the session and redirects to the login page.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;

class Logout extends Module {

	/**
	 * Login required
	 *
	 * @access protected
	 * @var bool $login_required
	 */
	protected bool $login_required = false;

	/**
	 * Handle request
	 *
	 * @access public
	 */
	public function handle_request(): void {
		session_destroy();

		Session::redirect('/login');
	}
}
