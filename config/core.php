<?php
/**
 * Skeleton configuration
 */

return [
	// Where skeleton can find various parts of your application
	'application_path' => dirname(__FILE__) . '/../app/',
	'asset_paths' => [
		dirname(__FILE__) . '/../lib/external/packages/bower-asset/',
		dirname(__FILE__) . '/../lib/external/packages/npm-asset/',
		dirname(__FILE__) . '/../lib/external/packages',
	],
	'tmp_path' => dirname(__FILE__) . '/../tmp/',
	'tmp_dir' => dirname(__FILE__) . '/../tmp/',
	'lib_dir' => dirname(__FILE__) . '/../lib/',
	'file_path' => dirname(__FILE__) . '/../store/file/',
];
