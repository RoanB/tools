<?php
/**
 * Add admin user
 *
 * Inserts the site administrator with a random placeholder password. Set the
 * real password via the password reset flow (Forgot password on the login
 * page), which emails a reset link to the admin's address.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260903_000002_Admin_User extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$database->query('INSERT INTO `user` (`uuid`, `email`, `password`, `firstname`, `lastname`, `admin`, `created`, `updated`) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())', [
			'6eb40e76-d4bc-4d47-9dbd-252c52c76e87',
			'roan@buysse.io',
			'$2y$12$/aVE0I7KDzhc6/lJ1Soy7uAaPPjNWQIJmG0/OLpYizxgiOTjUMZ1K',
			'Roan',
			'Buysse',
		]);
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('DELETE FROM `user` WHERE `email` = ?', ['roan@buysse.io']);
	}
}
