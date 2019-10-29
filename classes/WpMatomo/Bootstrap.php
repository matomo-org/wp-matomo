<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace WpMatomo;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

class Bootstrap {
	/**
	 * Tests only
	 * @var bool|null
	 */
	private static $assume_not_bootstrapped;

	private static $bootstrapped_by_wordpress = false;

	/**
	 * Tests only
	 * @internal
	 * @ignore
	 */
	public static function set_not_bootstrapped() {
		self::$assume_not_bootstrapped   = true;
		self::$bootstrapped_by_wordpress = false;
	}

	public static function was_bootstrapped_by_wordpress() {
		return self::$bootstrapped_by_wordpress;
	}

	public function bootstrap() {
		if ( self::is_bootstrapped() ) {
			return;
		}

		self::$bootstrapped_by_wordpress = true;
		self::$assume_not_bootstrapped   = false; // we need to unset it again to prevent recursion

		if ( ! defined( 'PIWIK_ENABLE_DISPATCH' ) ) {
			define( 'PIWIK_ENABLE_DISPATCH', false );
		}

		if ( ! defined( 'PIWIK_DOCUMENT_ROOT' ) ) {
			define( 'PIWIK_DOCUMENT_ROOT', plugin_dir_path(MATOMO_ANALYTICS_FILE) . 'app' );
		}

		if ( file_exists( PIWIK_DOCUMENT_ROOT . '/../matomo_bootstrap.php' ) ) {
			require_once PIWIK_DOCUMENT_ROOT . '/../matomo_bootstrap.php';
		}

		if ( ! defined( 'PIWIK_INCLUDE_PATH' ) ) {
			define( 'PIWIK_INCLUDE_PATH', PIWIK_DOCUMENT_ROOT );
		}

		require_once PIWIK_INCLUDE_PATH . '/core/bootstrap.php';
		// we need to install now

		include_once 'Db/Wordpress.php';

		$environment = new \Piwik\Application\Environment( null );
		$environment->init();

		\Piwik\FrontController::unsetInstance();
		$controller = \Piwik\FrontController::getInstance();
		$controller->init();

		add_action( 'set_current_user', function () {
			$access = \Piwik\Access::getInstance();
			if ( $access ) {
				$access->reloadAccess();
			}
		} );
	}

	public static function is_bootstrapped() {
		if ( true === self::$assume_not_bootstrapped ) {
			return false;
		}

		return defined( 'PIWIK_DOCUMENT_ROOT' );
	}

	public static function do_bootstrap() {
		$bootstrap = new Bootstrap();
		$bootstrap->bootstrap();
	}

	/**
	 * Autoload Matomo classes without actually bootstrapping Matomo. This can be useful if you want to avoid
	 * bootstrapping the entire Matomo just to execute one or two classes that actually don't require Matomo to be
	 * bootstrapped. Eg when they only handle some logic around arrays etc.
	 *
	 * Using this method makes WP faster and also more stable not risking Matomo could break or slow down the current
	 * request. Only use it though with care cause if the class is being changed then your plugin may break if it
	 * suddenly requires dependencies to Matomo
	 */
	public static function autoload_matomo() {
		require_once plugin_dir_path(MATOMO_ANALYTICS_FILE) . 'app/vendor/autoload.php';

		if (!empty($GLOBALS['MATOMO_PLUGIN_DIRS'])) {
			$extraDirs = array_map(function ($dir) {
				return rtrim($dir['pluginsPathAbsolute'], '/') . '/';
			}, $GLOBALS['MATOMO_PLUGIN_DIRS']);
			\Piwik\Plugin\Manager::registerPluginDirAutoload($extraDirs);
		}
	}
}
