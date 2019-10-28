<?php

if (!defined( 'ABSPATH')) {
	exit; // if accessed directly
}

use WpMatomo\Capabilities;
use WpMatomo\Paths;
use WpMatomo\Settings;

return array(
	'path.tmp' => function () {
		$paths = new \WpMatomo\Paths();
		return $paths->get_tmp_dir();
	},
	'path.misc.user' => function () {
		$paths = new \WpMatomo\Paths();
		return $paths->get_relative_dir_to_matomo($paths->get_upload_base_dir()) . '/';
	},
	'path.geoip2' => function () {
		$paths = new \WpMatomo\Paths();
		return $paths->get_gloal_upload_dir_if_possible('GeoLite2-City.mmdb') . '/';
	},
	// we want to avoid the regular monolog logger as it could interfere with other plugins maybe. for now lets use a
	// custom logger
	'Psr\Log\LoggerInterface' => DI\get('\Piwik\Plugins\WordPress\Logger'),
	'TagManagerContainerStorageDir' => function () {
		// the location where we store the generated javascript or json container files
		$paths = new \WpMatomo\Paths();
		return '/'. $paths->get_relative_dir_to_matomo($paths->get_upload_base_dir().'/');
	},
	'TagManagerContainerWebDir' => function () {
		// the location where we store the generated javascript or json container files
		$paths = new \WpMatomo\Paths();
		return '/'. $paths->get_relative_dir_to_matomo($paths->get_upload_base_dir().'/');
	},
	'Piwik\Auth' => DI\object('Piwik\Plugins\WordPress\Auth'),
	'Piwik\Translation\Translator' => DI\object('Piwik\Plugins\WordPress\Translator'),
	\Piwik\Config::class => DI\decorate(function ($previous) {
		/**
		 * @param \Piwik\Config $previous
		 */
		global $wpdb;

		// in case DB credentials change in wordpress, we need to apply these changes here as well on demand
		$hostParts = explode(':', DB_HOST);
		$host = $hostParts[0];
		if (count($hostParts) === 2 && is_numeric($hostParts[1])) {
			$port = $hostParts[1];
		} else {
			$port = 3306;
		}

		if (defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN) {
			$general = $previous->General;
			$general['force_ssl'] = 1;
			$general['assume_secure_protocol'] = 1;
			$previous->General = $general;
		}

		$paths = new Paths();
		if (file_exists( $paths->get_config_ini_path())) {
			// we overwrite DB on demand only once installed... otherwise Matomo may think it is installed already
			$database = $previous->database;
			$database['host'] = $host;
			$database['port'] = $port;
			$database['username'] = DB_USER;
			$database['password'] = DB_PASSWORD;
			$database['dbname'] = DB_NAME;
			$database['tables_prefix'] = $wpdb->prefix . MATOMO_DATABASE_PREFIX;
			$database['adapter'] = 'Wordpress';
			$previous->database = $database;

			$general = $previous->General;

			if (defined('MATOMO_TRIGGER_BROWSER_ARCHIVING')) {
				$general['enable_browser_archiving_triggering'] = (int) MATOMO_TRIGGER_BROWSER_ARCHIVING;
			}

			$matomo_salt_key = Settings::OPTION_PREFIX . 'matomo_salt';
			$matomo_salt = get_option($matomo_salt_key); // needs to per site!
			if (!$matomo_salt) {
				$matomo_salt = \Piwik\Common::getRandomString(32);
				update_option($matomo_salt_key, $matomo_salt);
			}

			$general['salt'] = $matomo_salt;

			if (empty($general['trusted_hosts'])) {
				$general['trusted_hosts'] = array();
			}
			if (!in_array(site_url(), $general['trusted_hosts'])) {
				$general['trusted_hosts'][] = site_url();
			}
			$previous->General = $general;

			add_action('switch_blog', function ($new_blog) {
				global $wpdb;
				$config = \Piwik\Config::getInstance();
				$database = $config->database;
				$database['tables_prefix'] = $wpdb->prefix . MATOMO_DATABASE_PREFIX;
				$config->database = $database;
			});
		}

		return $previous;
	}),
	'Zend_Mail_Transport_Abstract' => DI\object('WpMatomo\Email'),
	'Piwik\Plugins\CustomPiwikJs\TrackerUpdater' => DI\decorate(function ($previous) {
		/** @var \Piwik\Plugins\CustomPiwikJs\TrackerUpdater $previous */

		$paths = new Paths();
		$dir = $paths->get_matomo_js_upload_path();

		$previous->setToFile($dir);

		return $previous;
	}),
	'diagnostics.optional' => DI\decorate(function ($checks) {
		foreach ($checks as $index => $check) {
			if ($check && is_object($check)) {
				$class_name = get_class($check);
				if ($class_name === 'Piwik\Plugins\Diagnostics\Diagnostic\ForceSSLCheck'
					|| $class_name === 'Piwik\Plugins\Diagnostics\Diagnostic\LoadDataInfileCheck'
					|| $class_name === 'Piwik\Plugins\Diagnostics\Diagnostic\FileIntegrityCheck') {
					$checks[$index] = null;
				}
			}
		}
		return array_values(array_filter($checks));
	}),
	'observers.global' => DI\add(array(
		array('FrontController.modifyErrorPage', function (&$result, $ex) {
			if (!empty($ex) && is_object($ex) && $ex instanceof \Piwik\Exception\NoWebsiteFoundException) {
				// try to repair itself in case for some reason the site was not yet synced... on next reload it would
				// then work
				$sync = new \WpMatomo\Site\Sync(new Settings());
				$sync->sync_current_site();
			}
			if (!empty($ex)
			    && is_object($ex)
			    && $ex instanceof \Piwik\Exception\NoPrivilegesException
			    && is_user_logged_in()) {
				if (current_user_can(Capabilities::KEY_VIEW)) {
					// some error... it looks like user should by synced but isn't yet
					// could happen eg when in network activated mode the super admin changes permission and another
					// user from a blog wants to access the UI while not all users are synced just yet
					// try to repair itself in case for some reason the user was not yet synced... on next reload it would
					// then work
					$sync = new \WpMatomo\User\Sync();
					$sync->sync_current_users();
				}
			}
		}),
		array('Db.getDatabaseConfig', function (&$config) {
			// we don't want to save these and instead detect them on demand.
			// for security reasons etc we don't want to duplicate these values
			include_once plugin_dir_path(MATOMO_ANALYTICS_FILE ) . 'classes/WpMatomo/Db/Wordpress.php';
		}),
		array('Tracker.getDatabaseConfig', function (&$configDb) {
			// we don't want to save these and instead detect them on demand.
			// for security reasons etc we don't want to duplicate these values
			include_once plugin_dir_path(MATOMO_ANALYTICS_FILE ) . 'classes/WpMatomo/Db/Wordpress.php';
		}),
		array('Config.beforeSave', function (&$values) {
			// we don't want to save these and instead detect them on demand.
			// for security reasons etc we don't want to duplicate these values
			unset($values['database']['host']);
			unset($values['database']['username']);
			unset($values['database']['password']);
			unset($values['database']['dbname']);
			unset($values['database']['tables_prefix']);
			unset($values['Plugins']);
			unset($values['General']['enable_users_admin']);
			unset($values['General']['enable_sites_admin']);
			unset($values['General']['salt']);
		}),
	)),

);
