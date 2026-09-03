<?php
/**
 * Module Index
 *
 * Authenticated dashboard.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;
use User;

class Index extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'index.twig';

	/**
	 * Secure access
	 *
	 * Require an authenticated user.
	 *
	 * @access public
	 * @return bool $allowed
	 */
	public function secure(): bool {
		return isset($_SESSION['user_id']);
	}

	/**
	 * Display method
	 *
	 * @access public
	 */
	public function display(): void {
		$user = new User($_SESSION['user_id']);
		$template = Template::get();
		$template->assign('user', $user);
	}
}
