<?php
/**
 * Bootstrap Class
 *
 * Initializes the Skeleton framework
 *
 * @author Roan Buysse <roan@buysse.be>
 */

class Bootstrap {

	/**
	 * Bootstrap
	 *
	 * @access public
	 */
	public static function boot() {
		/**
		 * Set the root path
		 */
		$root_path = realpath(dirname(__FILE__) . '/../..');

		/**
		 * Register the autoloader from Composer
		 */
		require_once $root_path . '/lib/external/packages/autoload.php';

		/**
		 * Get the config
		 */
		\Skeleton\Core\Config::include_directory($root_path . '/config');
		$config = \Skeleton\Core\Config::get();

		/**
		 * Register the autoloader
		 */
		$autoloader = new \Skeleton\Core\Autoloader();
		$autoloader->add_include_path($root_path . '/lib/model/');
		$autoloader->add_include_path($root_path . '/lib/base/');
		$autoloader->add_include_path($root_path . '/lib/component/');
		$autoloader->register();

		/**
		 * Initialize the database
		 */
		\Skeleton\Database\Config::$auto_null = false;
		\Skeleton\Database\Config::$auto_trim = false;
		\Skeleton\Database\Config::$auto_discard = false;
		\Skeleton\Database\Config::$charset = 'utf8mb4';
		$database = \Skeleton\Database\Database::get($config->database, true);

		/**
		 * Initialize the file store
		 */
		\Skeleton\File\Config::$file_dir = $root_path . '/store/file';
		\Skeleton\File\Config::$file_interface = '\File';

		/**
		 * Initialize migration
		 */
		\Skeleton\Database\Migration\Config::$migration_directory = $root_path . '/migration/';
		\Skeleton\Database\Migration\Config::$version_storage  = 'database';
		\Skeleton\Database\Migration\Config::$database_table  = 'db_version';

		/**
		 * Transaction daemon
		 */
		\Skeleton\Transaction\Config::$monitor_file = $root_path . '/tmp/skeleton.status';
		\Skeleton\Transaction\Config::$monitor_authentication = 'thie7Eej7poh7Aic';

		/**
		 * Initialize the error handler
		 */
		\Skeleton\Error\Config::$debug = $config->debug;
		\Skeleton\Error\Config::$environment = $config->environment;
		\Skeleton\Error\Config::$sentry_dsn = $config->sentry_dsn;
		\Skeleton\Error\Handler::enable();

		/**
		 * Initialize the template
		 */
		\Skeleton\Template\Twig\Config::$cache_directory = $config->tmp_path . 'twig/';
		\Skeleton\Template\Twig\Config::$debug = $config->debug;

		/**
		 * Initialize the translations
		 */
		\Skeleton\I18n\Config::$language_interface = '\Language';
		\Skeleton\I18n\Translator\Storage\Po::set_default_configuration(['storage_path' => $root_path . '/po/']);
		\Skeleton\I18n\Config::$cache_path = $root_path . '/tmp/languages/';

		// Email Translator
		$storage = new \Skeleton\I18n\Translator\Storage\Po();
		$translator = new \Skeleton\I18n\Translator('email');
		$translator->set_translator_storage($storage);
		$translator_extractor_twig = new \Skeleton\I18n\Translator\Extractor\Twig();
		$translator_extractor_twig->set_template_path($root_path . '/store/email');
		$translator->set_translator_extractor($translator_extractor_twig);
		$translator->save();

		/**
		 * Emails
		 */
		\Skeleton\Email\Config::$email_directory = $root_path . '/store/email/';

		/**
		 * Skeleton-File
		 */
		\Skeleton\File\Config::$file_path = $config->file_path;
		\Skeleton\File\Config::$file_interface = 'File';

		/**
		 * Skeleton-File-picture
		 */
		\Skeleton\File\Picture\Config::$tmp_path = $config->tmp_path . '/picture/';
		\Skeleton\File\Picture\Config::add_resize_configuration('960x360', 960, 360);
		\Skeleton\File\Picture\Config::add_resize_configuration('160x160', 320, 320);
		\Skeleton\File\Picture\Config::add_resize_configuration('160x160', 160, 160);
		\Skeleton\File\Picture\Config::add_resize_configuration('88x88', 88, 88);
		\Skeleton\File\Picture\Config::add_resize_configuration('50x50', 88, 88);
		\Skeleton\File\Picture\Config::add_resize_configuration('25x25', 25, 25);

		/**
		 * Initialize the pager
		 */
		\Skeleton\Pager\Config::$sticky_pager = true;
		\Skeleton\Pager\Config::$jump_to = false;
		\Skeleton\Pager\Config::$links_template = '@skeleton-pager\bootstrap5-tabler\links.twig';
		\Skeleton\Pager\Config::$header_template = '@skeleton-pager\bootstrap5-tabler\header.twig';
		\Skeleton\Pager\Config::$per_page_template = '@skeleton-pager\bootstrap5-tabler\per_page.twig';
	}
}
