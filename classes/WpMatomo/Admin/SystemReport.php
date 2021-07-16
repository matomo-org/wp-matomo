<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

namespace WpMatomo\Admin;

use Piwik\CliMulti;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DeviceDetector\DeviceDetectorFactory;
use Piwik\Filesystem;
use Piwik\Plugin;
use Piwik\Plugins\CoreAdminHome\API;
use Piwik\Plugins\Diagnostics\Diagnostic\DiagnosticResult;
use Piwik\Plugins\Diagnostics\DiagnosticService;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\SettingsPiwik;
use Piwik\Tracker\Failures;
use Piwik\Version;
use WpMatomo\Bootstrap;
use WpMatomo\Capabilities;
use WpMatomo\Installer;
use WpMatomo\Logger;
use WpMatomo\Paths;
use WpMatomo\ScheduledTasks;
use WpMatomo\Settings;
use WpMatomo\Site;
use WpMatomo\Site\Sync as SiteSync;
use WpMatomo\Updater;
use WpMatomo\User\Sync as UserSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

class SystemReport {
	const NONCE_NAME                      = 'matomo_troubleshooting';
	const TROUBLESHOOT_SYNC_USERS         = 'matomo_troubleshooting_action_site_users';
	const TROUBLESHOOT_SYNC_ALL_USERS     = 'matomo_troubleshooting_action_all_users';
	const TROUBLESHOOT_SYNC_SITE          = 'matomo_troubleshooting_action_site';
	const TROUBLESHOOT_SYNC_ALL_SITES     = 'matomo_troubleshooting_action_all_sites';
	const TROUBLESHOOT_CLEAR_MATOMO_CACHE = 'matomo_troubleshooting_action_clear_matomo_cache';
	const TROUBLESHOOT_ARCHIVE_NOW        = 'matomo_troubleshooting_action_archive_now';
	const TROUBLESHOOT_UPDATE_GEOIP_DB    = 'matomo_troubleshooting_action_update_geoipdb';
	const TROUBLESHOOT_CLEAR_LOGS         = 'matomo_troubleshooting_action_clear_logs';
	const TROUBLESHOOT_RUN_UPDATER        = 'matomo_troubleshooting_action_run_updater';

	private $not_compatible_plugins = array(
		'background-manager', // Uses an old version of Twig and plugin is no longer maintained.
		'all-in-one-event-calendar', // Uses an old version of Twig
		'data-tables-generator-by-supsystic', // uses an old version of twig causing some styles to go funny in the reporting and admin
		'tweet-old-post-pro', // uses a newer version of monolog
		'wp-rss-aggregator', // see https://wordpress.org/support/topic/critical-error-after-upgrade/ conflict re php-di version
		'wp-defender', // see https://wordpress.org/support/topic/critical-error-after-upgrade/ conflict re php-di version
		'age-verification-for-woocommerce', // see https://github.com/matomo-org/wp-matomo/issues/428
		'minify-html-markup', // see https://wordpress.org/support/topic/graphs-are-not-displayed-in-the-visits-overview-widget/#post-14298068
		'bigbuy-wc-dropshipping-connector', // see https://wordpress.org/support/topic/20-total-errors-during-this-script-execution/
		'google-listings-and-ads', // see https://wordpress.org/support/topic/20-total-errors-during-this-script-execution/
		'accelerated-mobile-pages' // see https://wordpress.org/support/topic/receiving-errors-from-my-plesk-server/
	);

	private $valid_tabs = array( 'troubleshooting' );

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Logger
	 */
	private $logger;

	private $initial_error_reporting = null;
	/**
	 * @var \WpMatomo\Db\Settings
	 */
	public $dbSettings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->logger = new Logger();
		$this->dbSettings = new \WpMatomo\Db\Settings();
	}

	public function get_not_compatible_plugins() {
		return $this->not_compatible_plugins;
	}

	private function execute_troubleshoot_if_needed() {
		if ( ! empty( $_POST )
			 && is_admin()
			 && check_admin_referer( self::NONCE_NAME )
			 && current_user_can( Capabilities::KEY_SUPERUSER ) ) {
			if ( ! empty( $_POST[ self::TROUBLESHOOT_ARCHIVE_NOW ] ) ) {
				Bootstrap::do_bootstrap();
				$scheduled_tasks = new ScheduledTasks( $this->settings );

				if (!defined('PIWIK_ARCHIVE_NO_TRUNCATE')) {
					define('PIWIK_ARCHIVE_NO_TRUNCATE', 1); // when triggering it manually, we prefer the full error message
				}

				try {
					// force invalidation of archive to ensure it actually will rearchive the data
					$site = new Site();
					$idsite = $site->get_current_matomo_site_id();
					if ($idsite) {
						$timezone = \Piwik\Site::getTimezoneFor($idsite);
						$now_string = \Piwik\Date::factory('now', $timezone)->toString();
						foreach (array('day') as $period) {
							API::getInstance()->invalidateArchivedReports($idsite, $now_string, $period, false, false);
						}
					}
				} catch (\Exception $e) {
					$this->logger->log_exception('archive_invalidate', $e);
				}

				try {
					$errors = $scheduled_tasks->archive( $force = true, $throw_exception = false );
				} catch (\Exception $e) {
					echo '<div class="error"><p>' . esc_html__('Matomo Archive Error', 'matomo') . ': '. esc_html(matomo_anonymize_value($e->getMessage() . ' =>' . $this->logger->get_readable_trace($e))) . '</p></div>';
					throw $e;
				}

				if ( ! empty( $errors ) ) {
					echo '<div class="notice notice-warning"><p>Matomo Archive Warnings: ';
					foreach ($errors as $error) {
						echo nl2br(esc_html(matomo_anonymize_value(var_export($error, 1))));
						echo '<br/>';
					}
					echo '</p></div>';
				}
			}

			if ( ! empty( $_POST[ self::TROUBLESHOOT_CLEAR_MATOMO_CACHE ] ) ) {
				$paths = new Paths();
				$paths->clear_cache_dir();
				// we first delete the cache dir manually just in case there's something
				// going wrong with matomo and bootstrapping would not even be possible.
				Bootstrap::do_bootstrap();
				Filesystem::deleteAllCacheOnUpdate();
				Updater::unlock();
			}

			if ( ! empty( $_POST[ self::TROUBLESHOOT_UPDATE_GEOIP_DB ] ) ) {
				$scheduled_tasks = new ScheduledTasks( $this->settings );
				$scheduled_tasks->update_geo_ip2_db();
			}

			if ( ! empty( $_POST[ self::TROUBLESHOOT_CLEAR_LOGS ] ) ) {
				$this->logger->clear_logged_exceptions();
			}

			if ( ! $this->settings->is_network_enabled() || ! is_network_admin() ) {
				if ( ! empty( $_POST[ self::TROUBLESHOOT_SYNC_USERS ] ) ) {
					$sync = new UserSync();
					$sync->sync_current_users();
				}
				if ( ! empty( $_POST[ self::TROUBLESHOOT_SYNC_SITE ] ) ) {
					$sync = new SiteSync( $this->settings );
					$sync->sync_current_site();
				}
				if ( ! empty( $_POST[ self::TROUBLESHOOT_RUN_UPDATER ] ) ) {
					Updater::unlock();
					$sync = new Updater( $this->settings );
					$sync->update();
				}
			}
			if ( $this->settings->is_network_enabled() ) {
				if ( ! empty( $_POST[ self::TROUBLESHOOT_SYNC_ALL_SITES ] ) ) {
					$sync = new SiteSync( $this->settings );
					$sync->sync_all();
				}
				if ( ! empty( $_POST[ self::TROUBLESHOOT_SYNC_ALL_USERS ] ) ) {
					$sync = new UserSync();
					$sync->sync_all();
				}
			}
		}
	}

	public function show() {
		$this->execute_troubleshoot_if_needed();

		$settings = $this->settings;

		$matomo_active_tab = '';
		if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], $this->valid_tabs, true ) ) {
			$matomo_active_tab = $_GET['tab'];
		}

		$matomo_tables = array();
		if ( empty( $matomo_active_tab ) ) {
			$this->initial_error_reporting = @error_reporting();
			$matomo_tables = array(
				array(
					'title'        => 'Matomo',
					'rows'         => $this->get_matomo_info(),
					'has_comments' => true,
				),
				array(
					'title' => 'WordPress',
					'rows'  => $this->get_wordpress_info(),
					'has_comments' => true,
				),
				array(
					'title'        => 'WordPress Plugins',
					'rows'         => $this->get_plugins_info(),
					'has_comments' => true,
				),
				array(
					'title'        => 'Server',
					'rows'         => $this->get_server_info(),
					'has_comments' => true,
				),
				array(
					'title'        => 'Database',
					'rows'         => $this->get_db_info(),
					'has_comments' => true,
				),
				array(
					'title'        => 'Browser',
					'rows'         => $this->get_browser_info(),
					'has_comments' => true,
				),
			);
		}
		$matomo_tables                    = apply_filters('matomo_systemreport_tables', $matomo_tables);
		$matomo_tables                    = $this->add_errors_first( $matomo_tables );
		$matomo_has_warning_and_no_errors = $this->has_only_warnings_no_error( $matomo_tables );

		$matomo_has_exception_logs = $this->logger->get_last_logged_entries();

		include dirname( __FILE__ ) . '/views/systemreport.php';
	}

	private function has_only_warnings_no_error( $report_tables ) {
		$has_warning = false;
		$has_error   = false;
		foreach ( $report_tables as $report_table ) {
			foreach ( $report_table['rows'] as $row ) {
				if ( ! empty( $row['is_error'] ) ) {
					$has_error = true;
				}
				if ( ! empty( $row['is_warning'] ) ) {
					$has_warning = true;
				}
			}
		}

		return $has_warning && ! $has_error;
	}

	private function add_errors_first( $report_tables ) {
		$errors = array(
			'title'        => 'Errors',
			'rows'         => array(),
			'has_comments' => true,
		);
		foreach ( $report_tables as $report_table ) {
			foreach ( $report_table['rows'] as $row ) {
				if ( ! empty( $row['is_error'] ) ) {
					$errors['rows'][] = $row;
				}
			}
		}

		if ( ! empty( $errors['rows'] ) ) {
			array_unshift( $report_tables, $errors );
		}

		return $report_tables;
	}

	private function check_file_exists_and_writable( $rows, $path_to_check, $title, $required ) {
		$file_exists   = file_exists( $path_to_check );
		$file_readable = is_readable( $path_to_check );
		$file_writable = is_writable( $path_to_check );
		$comment       = '"' . $path_to_check . '" ';
		if ( ! $file_exists ) {
			$comment .= sprintf( esc_html__( '%s does not exist. ', 'matomo' ), $title );
		}
		if ( ! $file_readable ) {
			$comment .= sprintf( esc_html__( '%s is not readable. ', 'matomo' ), $title );
		}
		if ( ! $file_writable ) {
			$comment .= sprintf( esc_html__( '%s is not writable. ', 'matomo' ), $title );
		}

		$rows[] = array(
			'name'       => sprintf( esc_html__( '%s exists and is writable.', 'matomo' ), $title ),
			'value'      => $file_exists && $file_readable && $file_writable ? esc_html__( 'Yes', 'matomo' ) : esc_html__( 'No', 'matomo' ),
			'comment'    => $comment,
			'is_error'   => $required && ( ! $file_exists || ! $file_readable ),
			'is_warning' => ! $required && ( ! $file_exists || ! $file_readable ),
		);

		return $rows;
	}

	private function get_matomo_info() {
		$rows = array();

		$plugin_data  = get_plugin_data( MATOMO_ANALYTICS_FILE, $markup = false, $translate = false );
		$install_time = get_option(Installer::OPTION_NAME_INSTALL_DATE);

		$rows[] = array(
			'name'    => esc_html__( 'Matomo Plugin Version', 'matomo' ),
			'value'   => $plugin_data['Version'],
			'comment' => '',
		);

		$paths            = new Paths();
		$path_config_file = $paths->get_config_ini_path();
		$rows             = $this->check_file_exists_and_writable( $rows, $path_config_file, 'Config', true );

		$path_tracker_file = $paths->get_matomo_js_upload_path();
		$rows              = $this->check_file_exists_and_writable( $rows, $path_tracker_file, 'JS Tracker', false );

		$rows[] = array(
			'name'    => esc_html__( 'Plugin directories', 'matomo' ),
			'value'   => ! empty( $GLOBALS['MATOMO_PLUGIN_DIRS'] ) ? 'Yes' : 'No',
			'comment' => ! empty( $GLOBALS['MATOMO_PLUGIN_DIRS'] ) ? wp_json_encode( $GLOBALS['MATOMO_PLUGIN_DIRS'] ) : '',
		);

		$tmp_dir = $paths->get_tmp_dir();

		$rows[] = array(
			'name'    => esc_html__( 'Tmp directory writable', 'matomo' ),
			'value'   => is_writable( $tmp_dir ),
			'comment' => $tmp_dir,
		);

		if ( ! empty( $_SERVER['MATOMO_WP_ROOT_PATH'] ) ) {
			$custom_path = rtrim( $_SERVER['MATOMO_WP_ROOT_PATH'], '/' ) . '/wp-load.php';
			$path_exists = file_exists( $custom_path );
			$comment     = '';
			if ( ! $path_exists ) {
				$comment = 'It seems the path does not point to the WP root directory.';
			}

			$rows[] = array(
				'name'     => 'Custom MATOMO_WP_ROOT_PATH',
				'value'    => $path_exists,
				'is_error' => ! $path_exists,
				'comment'  => $comment,
			);
		}

		$report = null;

		if ( ! \WpMatomo::is_safe_mode() ) {
			try {
				Bootstrap::do_bootstrap();
				/** @var DiagnosticService $service */
				$service = StaticContainer::get( DiagnosticService::class );
				$report  = $service->runDiagnostics();

				$rows[] = array(
					'name'    => esc_html__( 'Matomo Version', 'matomo' ),
					'value'   => \Piwik\Version::VERSION,
					'comment' => '',
				);
			} catch ( \Exception $e ) {
				$rows[] = array(
					'name'    => esc_html__( 'Matomo System Check', 'matomo' ),
					'value'   => 'Failed to run Matomo system check.',
					'comment' => $e->getMessage(),
				);
			}
		}

		$site   = new Site();
		$idsite = $site->get_current_matomo_site_id();

		$rows[] = array(
			'name'    => esc_html__( 'Matomo Blog idSite', 'matomo' ),
			'value'   => $idsite,
			'comment' => '',
		);

		$install_date = '';
		if (!empty($install_time)) {
			$install_date = 'Install date: '.  $this->convert_time_to_date($install_time, true, false);
		}

		$rows[] = array(
			'name'    => esc_html__( 'Matomo Install Version', 'matomo' ),
			'value'   => get_option(Installer::OPTION_NAME_INSTALL_VERSION),
			'comment' => $install_date,
		);

		$wpmatomo_updater = new \WpMatomo\Updater($this->settings);
		if (!\WpMatomo::is_safe_mode()) {

			$outstanding_updates = $wpmatomo_updater->get_plugins_requiring_update();
			$upgrade_in_progress = $wpmatomo_updater->is_upgrade_in_progress();
			$rows[] = array(
				'name'     => 'Upgrades outstanding',
				'value'    => !empty($outstanding_updates),
				'comment'  => !empty($outstanding_updates) ? json_encode($outstanding_updates) : '',
			);
			$rows[] = array(
				'name'     => 'Upgrade in progress',
				'value'    => $upgrade_in_progress,
				'comment'  => '',
			);
		}

		if (!$wpmatomo_updater->load_plugin_functions()) {
			// this should actually never happen...
			$rows[] = array(
				'name'     => 'Matomo Upgrade Plugin Functions',
				'is_warning'  => true,
				'value'    => false,
				'comment'  => 'Function "get_plugin_data" not available. There may be an issue with upgrades not being executed. Please reach out to us.',
			);
		}

		$rows[] = array(
			'section' => 'Endpoints',
		);

		$rows[] = array(
			'name'    => 'Matomo JavaScript Tracker URL',
			'value'   => '',
			'comment' => $paths->get_js_tracker_url_in_matomo_dir(),
		);

		$rows[] = array(
			'name'    => 'Matomo JavaScript Tracker - WP Rest API',
			'value'   => '',
			'comment' => $paths->get_js_tracker_rest_api_endpoint(),
		);

		$rows[] = array(
			'name'    => 'Matomo HTTP Tracking API',
			'value'   => '',
			'comment' => $paths->get_tracker_api_url_in_matomo_dir(),
		);

		$rows[] = array(
			'name'    => 'Matomo HTTP Tracking API - WP Rest API',
			'value'   => '',
			'comment' => $paths->get_tracker_api_rest_api_endpoint(),
		);

		$matomo_plugin_dir_name = basename(dirname(MATOMO_ANALYTICS_FILE));
		if ($matomo_plugin_dir_name !== 'matomo') {
			$rows[] = array(
				'name'    => 'Matomo Plugin Name is correct',
				'value'   => false,
				'is_error' => true,
				'comment' => 'The plugin name should be "matomo" but seems to be "' . $matomo_plugin_dir_name . '". As a result, admin pages and other features might not work. You might need to rename the directory name of this plugin and reactive the plugin.',
			);
		} elseif (!is_plugin_active('matomo/matomo.php')) {
			$rows[] = array(
				'name'    => 'Matomo Plugin not active',
				'value'   => false,
				'is_error' => true,
				'comment' => 'It seems WordPress thinks that `matomo/matomo.php` is not active. As a result Matomo reporting and admin pages may not work. You may be able to fix this by deactivating and activating the Matomo Analytics plugin. One of the reasons this could happen is that you used to have Matomo installed in the wrong folder.',
			);
		}

		$rows[] = array(
			'section' => 'Crons',
		);

		$scheduled_tasks = new ScheduledTasks( $this->settings );
		$all_events      = $scheduled_tasks->get_all_events();

		$rows[] = array(
			'name'    => esc_html__( 'Server time', 'matomo' ),
			'value'   => $this->convert_time_to_date( time(), false ),
			'comment' => '',
		);

		$rows[] = array(
			'name'    => esc_html__( 'Blog time', 'matomo' ),
			'value'   => $this->convert_time_to_date( time(), true ),
			'comment' => esc_html__( 'Below dates are shown in blog timezone', 'matomo' ),
		);

		foreach ( $all_events as $event_name => $event_config ) {
			$last_run_before = $scheduled_tasks->get_last_time_before_cron( $event_name );
			$last_run_after  = $scheduled_tasks->get_last_time_after_cron( $event_name );

			$next_scheduled = wp_next_scheduled( $event_name );

			$comment  = ' Last started: ' . $this->convert_time_to_date( $last_run_before, true, true ) . '.';
			$comment .= ' Last ended: ' . $this->convert_time_to_date( $last_run_after, true, true ) . '.';
			$comment .= ' Interval: ' . $event_config['interval'];

			$rows[] = array(
				'name'    => $event_config['name'],
				'value'   => 'Next run: ' . $this->convert_time_to_date( $next_scheduled, true, true ),
				'comment' => $comment,
			);
		}

		$suports_async = false;
		if ( ! \WpMatomo::is_safe_mode() && $report ) {
			$rows[] = array(
				'section' => esc_html__( 'Mandatory checks', 'matomo' ),
			);

			$rows = $this->add_diagnostic_results( $rows, $report->getMandatoryDiagnosticResults() );

			$rows[] = array(
				'section' => esc_html__( 'Optional checks', 'matomo' ),
			);
			$rows   = $this->add_diagnostic_results( $rows, $report->getOptionalDiagnosticResults() );

			$cli_multi = new CliMulti();
			$suports_async = $cli_multi->supportsAsync();

			$rows[] = array(
				'name'    => 'Supports Async Archiving',
				'value'   => $suports_async,
				'comment' => '',
			);

			$location_provider = LocationProvider::getCurrentProvider();
			if ($location_provider) {
				$rows[] = array(
					'name'    => 'Location provider ID',
					'value'   => $location_provider->getId(),
					'comment' => '',
				);
				$rows[] = array(
					'name'    => 'Location provider available',
					'value'   => $location_provider->isAvailable(),
					'comment' => '',
				);
				$rows[] = array(
					'name'    => 'Location provider working',
					'value'   => $location_provider->isWorking(),
					'comment' => '',
				);
			}

			if ( ! \WpMatomo::is_safe_mode() ) {
				Bootstrap::do_bootstrap();
				$general = Config::getInstance()->General;
				
				if (empty($general['proxy_client_headers'])) {
					foreach (AdvancedSettings::$valid_host_headers as $header) {
						if (!empty($_SERVER[$header])) {
							$rows[] = array(
								'name'    => 'Proxy header',
								'value'   => $header,
								'is_warning' => true,
								'comment' => 'A proxy header is set which means you maybe need to configure a proxy header in the Advanced settings to make location reporting work. If the location in your reports is detected correctly, you can ignore this warning. Learn more: https://matomo.org/faq/wordpress/how-do-i-fix-the-proxy-header-warning-in-the-matomo-for-wordpress-system-report/',
							);
						}
					}
				}
                $incompatible_plugins = Plugin\Manager::getInstance()->getIncompatiblePlugins(Version::VERSION);
				if (!empty($incompatible_plugins)) {
                    $rows[] = array(
                        'section' => esc_html__( 'Incompatible Matomo plugins', 'matomo' ),
                    );
                    foreach ($incompatible_plugins as $plugin) {
                        $rows[] = array(
                            'name'    => 'Plugin has missing dependencies',
                            'value'   => $plugin->getPluginName(),
                            'is_error' => true,
                            'comment' => $plugin->getMissingDependenciesAsString(Version::VERSION) . ' If the plugin requires a different Matomo version you may need to update it. If you no longer use it consider uninstalling it.',
                        );
                    }

                }
			}

			$num_days_check_visits = 5;
			$had_visits = $this->had_visits_in_last_days($num_days_check_visits);
			if ($had_visits === false || $had_visits === true) {
				// do not show info if we could not detect it (had_visits === null)
				$comment = '';
				if (!$had_visits) {
					$comment = 'It looks like there were no visits in the last ' . $num_days_check_visits . ' days. This may be expected if tracking is disabled, you have not added the tracking code, or your website does not have many visitors in general and you exclude your own visits.';
				}

				$rows[] = array(
					'name'    => 'Had visit in last ' . $num_days_check_visits . ' days',
					'value'   => $had_visits,
					'is_warning' => !$had_visits && $this->settings->is_tracking_enabled(),
					'comment' => $comment,
				);
			}

			if ( ! \WpMatomo::is_safe_mode() ) {
				Bootstrap::do_bootstrap();
				$matomo_url = SettingsPiwik::getPiwikUrl();
				$rows[]     = array(
					'name'    => 'Matomo URL',
					'comment' => $matomo_url,
					'value'   => ! empty( $matomo_url ),
				);
			}

		}

		$rows[] = array(
			'section' => 'Matomo Settings',
		);

		// always show these settings
		$global_settings_always_show = array(
			'track_mode',
			'track_codeposition',
			'track_api_endpoint',
			'track_js_endpoint',
		);
		foreach ( $global_settings_always_show as $key ) {
			$rows[] = array(
				'name'    => ucfirst( str_replace( '_', ' ', $key ) ),
				'value'   => $this->settings->get_global_option( $key ),
				'comment' => '',
			);
		}

		// otherwise show only few customised settings
		// mostly only numeric values and booleans to not eg accidentally show anything that would store a token etc
		// like we don't want to show license key etc
		foreach ( $this->settings->get_customised_global_settings() as $key => $val ) {
			if ( is_numeric( $val ) || is_bool( $val ) || 'track_content' === $key || 'track_user_id' === $key || 'core_version' === $key || 'version_history' === $key || 'mail_history' === $key ) {
				if ( is_array( $val ) ) {
					$val = implode( ', ', $val );
				}

				$rows[] = array(
					'name'    => ucfirst( str_replace( '_', ' ', $key ) ),
					'value'   => $val,
					'comment' => '',
				);
			}
		}

		$rows[] = array(
			'section' => 'Logs',
		);

		$error_log_entries = $this->logger->get_last_logged_entries();
		
		if ( ! empty( $error_log_entries ) ) {

			foreach ( $error_log_entries as $error ) {
				if (!empty($install_time)
				    && is_numeric($install_time)
				    && !empty($error['name'])
				    && !empty($error['value'])
				    && is_numeric($error['value'])
				    && $error['name'] === 'cron_sync'
					&& $error['value'] < ($install_time + 300)) {
					// the first sync might right after the installation
					continue;
				}

				// we only consider plugin_updates as errors only if there are still outstanding updates
				$is_plugin_update_error = !empty($error['name']) && $error['name'] === 'plugin_update'
				                          && !empty($outstanding_updates);

				$skip_plugin_update = !empty($error['name']) && $error['name'] === 'plugin_update'
				                          && empty($outstanding_updates);

				if (empty($error['comment']) && $error['comment'] !== '0') {
					$error['comment'] = '';
				}

				$error['value'] = $this->convert_time_to_date( $error['value'], true, false );
				$error['is_warning'] = !empty($error['name']) && stripos($error['name'], 'archiv') !== false && $error['name'] !== 'archive_boot';
				$error['is_error'] = $is_plugin_update_error;
				if ($is_plugin_update_error) {
					$error['comment'] = 'Please reach out to us and include the copied system report (see https://matomo.org/faq/wordpress/how-do-i-troubleshoot-a-failed-database-upgrade-in-matomo-for-wordpress/ for more info)<br><br>You can also retry the update manually by clicking in the top on the "Troubleshooting" tab and then clicking on the "Run updater" button.' . $error['comment'];
				} elseif ($skip_plugin_update) {
					$error['comment'] = 'As there are no outstanding plugin updates it looks like this log can be ignored.<br><br>' . $error['comment'];
				}
				$error['comment'] = matomo_anonymize_value($error['comment']);
				$rows[] = $error;
			}

			foreach ( $error_log_entries as $error ) {
				if ($suports_async
				    && !empty($error['value']) && is_string($error['value'])
					&& strpos($error['value'], __( 'Your PHP installation appears to be missing the MySQL extension which is required by WordPress.' )) > 0) {

					$rows[] = array(
						'name'    => 'Cli has no MySQL',
						'value'   => true,
						'comment' => 'It looks like MySQL is not available on CLI. Please read our FAQ on how to fix this issue: https://matomo.org/faq/wordpress/how-do-i-fix-the-error-your-php-installation-appears-to-be-missing-the-mysql-extension-which-is-required-by-wordpress-in-matomo-system-report/ ',
						'is_error' => true
					);
				}
			}
		} else {
			$rows[] = array(
				'name'    => __('None', 'matomo'),
				'value'   => '',
				'comment' => '',
			);
		}


		if ( ! \WpMatomo::is_safe_mode() ) {
			Bootstrap::do_bootstrap();
			$trackfailures = [];
			try {
				$tracking_failures = new Failures();
				$trackfailures = $tracking_failures->getAllFailures();
			} catch (\Exception $e) {
				// ignored in case not set up yet etc.
			}
			if (!empty($trackfailures)) {
				$rows[] = array(
					'section' => 'Tracking failures',
				);
				foreach ($trackfailures as $failure) {
					$comment = sprintf('Solution: %s<br>More info: %s<br>Date: %s<br>Request URL: %s',
										$failure['solution'], $failure['solution_url'],
										$failure['pretty_date_first_occurred'], $failure['request_url']);
					$rows[] = array(
						'name'    => $failure['problem'],
						'is_warning'   => true,
						'value'   => '',
						'comment' => $comment,
					);
				}

			}

		}


		return $rows;
	}

	private function had_visits_in_last_days($numDays)
	{
		global $wpdb;

		if (\WpMatomo::is_safe_mode()) {
			return null;
		}

		$days_in_seconds = $numDays * 86400;

		$prefix_table = $this->dbSettings->prefix_table_name('log_visit');

		$suppress_errors = $wpdb->suppress_errors;
		$wpdb->suppress_errors( true );// prevent any of this showing in logs just in case

		try {
			$time = gmdate( 'Y-m-d H:i:s', time() - $days_in_seconds );
			$sql = $wpdb->prepare('SELECT idsite from ' . $prefix_table . ' where visit_last_action_time > %s LIMIT 1', $time );
			$row = $wpdb->get_var( $sql );
		} catch ( \Exception $e ) {
			$row = null;
		}

		$wpdb->suppress_errors( $suppress_errors );
		// we need to differentiate between
		// 0 === had no visit
		// 1 === had visit
		// null === sum error... eg table was not correctly installed
		if ($row !== null) {
			$row = !empty($row);
		}

		return $row;
	}

	private function convert_time_to_date( $time, $in_blog_timezone, $print_diff = false ) {
		if ( empty( $time ) ) {
			return esc_html__( 'Unknown', 'matomo' );
		}

		$date = gmdate( 'Y-m-d H:i:s', (int)$time );

		if ( $in_blog_timezone ) {
			$date = get_date_from_gmt( $date, 'Y-m-d H:i:s' );
		}

		if ( $print_diff && class_exists( '\Piwik\Metrics\Formatter' ) ) {
			$formatter = new \Piwik\Metrics\Formatter();
			$date .= ' (' . $formatter->getPrettyTimeFromSeconds( $time - time(), true, false ) . ')';
		}

		return $date;
	}

	private function add_diagnostic_results( $rows, $results ) {
		foreach ( $results as $result ) {
			$comment = '';
			/** @var DiagnosticResult $result */
			if ( $result->getStatus() !== DiagnosticResult::STATUS_OK ) {
				foreach ( $result->getItems() as $item ) {
					$item_comment = $item->getComment();
					if ( ! empty( $item_comment ) && is_string( $item_comment ) ) {
						if ( stripos( $item_comment, 'core:archive' ) > 0 ) {
							// we only want to keep the first sentence like "	Archiving last ran successfully on Wednesday, January 2, 2019 00:00:00 which is 335 days 20:08:11 ago"
							// but not anything that asks user to set up a cronjob
							$item_comment = substr( $item_comment, 0, stripos( $item_comment, 'core:archive' ) );
							if ( strpos( $item_comment, '.' ) > 0 ) {
								$item_comment = substr( $item_comment, 0, strripos( $item_comment, '.' ) );
							} else {
								$item_comment = 'Archiving hasn\'t run in a while.';
							}
						}
						$comment .= $item_comment . '<br/>';
					}
				}
			}

			$rows[] = array(
				'name'       => $result->getLabel(),
				'value'      => $result->getStatus() . ' ' . $result->getLongErrorMessage(),
				'comment'    => $comment,
				'is_warning' => $result->getStatus() === DiagnosticResult::STATUS_WARNING,
				'is_error'   => $result->getStatus() === DiagnosticResult::STATUS_ERROR,
			);
		}

		return $rows;
	}

	private function get_wordpress_info() {
		$is_multi_site      = is_multisite();
		$num_blogs          = 1;
		$is_network_enabled = false;
		if ( $is_multi_site ) {
			if ( function_exists( 'get_blog_count' ) ) {
				$num_blogs = get_blog_count();
			}
			$settings           = new Settings();
			$is_network_enabled = $settings->is_network_enabled();
		}

		$rows   = array();
		$rows[] = array(
			'name'  => 'Home URL',
			'value' => home_url(),
		);
		$rows[] = array(
			'name'  => 'Site URL',
			'value' => site_url(),
		);
		$rows[] = array(
			'name'  => 'WordPress Version',
			'value' => get_bloginfo( 'version' ),
		);
		$rows[] = array(
			'name'  => 'Number of blogs',
			'value' => $num_blogs,
		);
		$rows[] = array(
			'name'  => 'Multisite Enabled',
			'value' => $is_multi_site,
		);
		$rows[] = array(
			'name'  => 'Network Enabled',
			'value' => $is_network_enabled,
		);
		$consts = array('WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'DISABLE_WP_CRON', 'FORCE_SSL_ADMIN', 'WP_CACHE',
						'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS', 'COMPRESS_CSS', 'ENFORCE_GZIP', 'WP_LOCAL_DEV',
						'WP_CONTENT_URL', 'WP_CONTENT_DIR', 'UPLOADS', 'BLOGUPLOADDIR',
						'DIEONDBERROR', 'WPLANG', 'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT', 'WP_DISABLE_FATAL_ERROR_HANDLER',
			'MATOMO_SUPPORT_ASYNC_ARCHIVING', 'MATOMO_TRIGGER_BROWSER_ARCHIVING', 'MATOMO_ENABLE_TAG_MANAGER', 'MATOMO_SUPPRESS_DB_ERRORS', 'MATOMO_ENABLE_AUTO_UPGRADE',
			'MATOMO_DEBUG', 'MATOMO_SAFE_MODE', 'MATOMO_GLOBAL_UPLOAD_DIR', 'MATOMO_LOGIN_REDIRECT');
		foreach ($consts as $const) {
			$rows[] = array(
				'name'  => $const,
				'value' => defined( $const ) ? constant( $const) : '-',
			);
		}

		$rows[] = array(
			'name'  => 'Permalink Structure',
			'value' => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'Default',
		);

		$rows[] = array(
			'name'  => 'Possibly uses symlink',
			'value' => strpos( __DIR__, ABSPATH ) === false && strpos( __DIR__, WP_CONTENT_DIR ) === false,
		);

		$upload_dir = wp_upload_dir();
		$rows[] = array(
			'name'  => 'Upload base url',
			'value' => $upload_dir['baseurl'],
		);

		$rows[] = array(
			'name'  => 'Upload base dir',
			'value' => $upload_dir['basedir'],
		);

		$rows[] = array(
			'name'  => 'Upload url',
			'value' => $upload_dir['url'],
		);

		foreach (['upload_path', 'upload_url_path'] as $option_read) {
			$rows[] = array(
				'name'  => 'Custom ' . $option_read,
				'value' => get_option( $option_read ),
			);
		}

		if (is_plugin_active('wp-piwik/wp-piwik.php')) {
			$rows[] = array(
				'name'  => 'WP-Matomo (WP-Piwik) activated',
				'value' => true,
				'is_warning' => true,
				'comment' => 'It is usually not recommended or needed to run Matomo for WordPress and WP-Matomo at the same time. To learn more about the differences between the two plugins view this URL: https://matomo.org/faq/wordpress/why-are-there-two-different-matomo-for-wordpress-plugins-what-is-the-difference-to-wp-matomo-integration-plugin/'
			);

			$mode = get_option ( 'wp-piwik_global-piwik_mode' );
			if (function_exists('get_site_option') && is_plugin_active_for_network ( 'wp-piwik/wp-piwik.php' )) {
				$mode = get_site_option ( 'wp-piwik_global-piwik_mode');
			}
			if (!empty($mode)) {
				$rows[] = array(
					'name'  => 'WP-Matomo mode',
					'value' => $mode,
					'is_warning' => $mode === 'php' || $mode === 'PHP',
					'comment' => 'WP-Matomo is configured in "PHP mode". This is known to cause issues with Matomo for WordPress. We recommend you either deactivate WP-Matomo or you go "Settings => WP-Matomo" and change the "Matomo Mode" in the "Connect to Matomo" section to "Self-hosted HTTP API".'
				);
			}
		}

		$compatible_content_dir = matomo_has_compatible_content_dir();
		if ($compatible_content_dir === true) {
			$rows[] = array(
				'name'  => 'Compatible content directory',
				'value' => true,
			);
		} else {
			$rows[] = array(
				'name'  => 'Compatible content directory',
				'value' => $compatible_content_dir,
				'is_warning' => true,
				'comment' =>  __( 'It looks like you are maybe using a custom WordPress content directory. The Matomo reporting/admin pages might not work. You may be able to workaround this.', 'matomo' ) . ' ' . __( 'Learn more', 'matomo' ) . ': https://matomo.org/faq/wordpress/how-do-i-make-matomo-for-wordpress-work-when-i-have-a-custom-content-directory/'
			);
		}

		return $rows;
	}

	private function get_server_info() {
		$rows = array();

		if ( ! empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$rows[] = array(
				'name'  => 'Server Info',
				'value' => $_SERVER['SERVER_SOFTWARE'],
			);
		}
		if ( PHP_OS ) {
			$rows[] = array(
				'name'  => 'PHP OS',
				'value' => PHP_OS,
			);
		}
		$rows[] = array(
			'name'  => 'PHP Version',
			'value' => phpversion(),
		);
		$rows[] = array(
			'name'  => 'PHP SAPI',
			'value' => php_sapi_name(),
		);
		if (defined('PHP_BINARY') && PHP_BINARY) {
			$rows[] = array(
				'name'  => 'PHP Binary Name',
				'value' => @basename(PHP_BINARY),
			);
		}
		// we report error reporting before matomo bootstraped and after to see if Matomo changed it successfully etc
		$rows[] = array(
			'name'  => 'PHP Error Reporting',
			'value' => $this->initial_error_reporting . ' After bootstrap: ' . @error_reporting()
		);
		if (!\WpMatomo::is_safe_mode()) {
			Bootstrap::do_bootstrap();
			$cliPhp = new CliMulti\CliPhp();
			$binary = $cliPhp->findPhpBinary();
			if (!empty($binary)) {
				$binary = basename($binary);
				$rows[] = array(
					'name'  => 'PHP Found Binary',
					'value' => $binary,
				);
			}
		}
		$rows[] = array(
			'name'  => 'Timezone',
			'value' => date_default_timezone_get(),
		);
		if (function_exists('wp_timezone_string')) {
			$rows[] = array(
				'name'  => 'WP timezone',
				'value' => wp_timezone_string(),
			);
		}
		$rows[] = array(
			'name'  => 'Locale',
			'value' => get_locale(),
		);
		if (function_exists('get_user_locale')) {
			$rows[] = array(
				'name'  => 'User Locale',
				'value' => get_user_locale(),
			);
		}

		$rows[] = array(
			'name'    => 'Memory Limit',
			'value'   => @ini_get( 'memory_limit' ),
			'comment' => 'At least 128MB recommended. Depending on your traffic 256MB or more may be needed.',
		);

		$rows[] = array(
			'name'    => 'WP Memory Limit',
			'value'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'comment' => '',
		);

		$rows[] = array(
			'name'    => 'WP Max Memory Limit',
			'value'   => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '',
			'comment' => '',
		);
		
		if (function_exists('timezone_version_get')) {
			$rows[] = array(
				'name'  => 'Timezone version',
				'value' => timezone_version_get(),
			);
		}
		
		$rows[] = array(
			'name'  => 'Time',
			'value' => time(),
		);

		$rows[] = array(
			'name'  => 'Max Execution Time',
			'value' => ini_get( 'max_execution_time' ),
		);
		$rows[] = array(
			'name'  => 'Max Post Size',
			'value' => ini_get( 'post_max_size' ),
		);
		$rows[] = array(
			'name'  => 'Max Upload Size',
			'value' => wp_max_upload_size(),
		);
		$rows[] = array(
			'name'  => 'Max Input Vars',
			'value' => ini_get( 'max_input_vars' ),
		);

		$disabled_functions = ini_get('disable_functions');
		$rows[] = array(
			'name'  => 'Disabled PHP functions',
			'value' => !empty($disabled_functions),
			'comment' => !empty($disabled_functions) ? $disabled_functions : ''
		);

		$zlib_compression = ini_get( 'zlib.output_compression' );
		$row              = array(
			'name'  => 'zlib.output_compression is off',
			'value' => $zlib_compression !== '1',
		);

		if ( $zlib_compression === '1' ) {
			$row['is_error'] = true;
			$row['comment']  = 'You need to set "zlib.output_compression" in your php.ini to "Off".';
		}
		$rows[] = $row;

		if ( function_exists( 'curl_version' ) ) {
			$curl_version = curl_version();
			$curl_version = $curl_version['version'] . ', ' . $curl_version['ssl_version'];
			$rows[]       = array(
				'name'  => 'Curl Version',
				'value' => $curl_version,
			);
		}

		$suhosin_installed = ( extension_loaded( 'suhosin' ) || ( defined( 'SUHOSIN_PATCH' ) && constant( 'SUHOSIN_PATCH' ) ) );
		$rows[] = array(
			'name'  => 'Suhosin installed',
			'value' => !empty($suhosin_installed),
			'comment' => ''
		);

		return $rows;
	}

	private function get_browser_info() {
		$rows = array();

		if (!empty($_SERVER['HTTP_USER_AGENT'])) {
			$rows[] = array(
				'name'    => 'Browser',
				'value'   => '',
				'comment' => $_SERVER['HTTP_USER_AGENT']
			);
		}
		if (!\WpMatomo::is_safe_mode()) {
			Bootstrap::do_bootstrap();
			try {
				if (!empty($_SERVER['HTTP_USER_AGENT'])) {
					$detector = StaticContainer::get(DeviceDetectorFactory::class)->makeInstance($_SERVER['HTTP_USER_AGENT']);
					$client = $detector->getClient();
					if (!empty($client['name']) && $client['name'] === 'Microsoft Edge' && (int) $client['version'] >= 85) {
						$rows[] = array(
							'name' => 'Browser Compatibility',
							'is_warning' => true,
							'value'   => 'Yes',
							'comment' => 'Because you are using MS Edge browser, you may see a warning like "This site has been reported as unsafe" from "Microsoft Defender SmartScreen" when you view the Matomo Reporting, Admin or Tag Manager page. This is a false alert and you can safely ignore this warning by clicking on the icon next to the URL (in the address bar) and choosing either "Report as safe" (preferred) or "Show unsafe content". We are hoping to get this false warning removed in the future.'
						);
					}
				}

			} catch (\Exception $e) {

			}

			$rows[] = array(
				'name'    => 'Language',
				'value'   => Common::getBrowserLanguage(),
				'comment' => ''
			);
		}


		return $rows;
	}

	private function get_db_info() {
		global $wpdb;
		$rows = array();

		$rows[] = array(
			'name'    => 'MySQL Version',
			'value'   => ! empty( $wpdb->is_mysql ) ? $wpdb->db_version() : '',
			'comment' => '',
		);

		$rows[] = array(
			'name'    => 'Mysqli Connect',
			'value'   => function_exists( 'mysqli_connect' ),
			'comment' => '',
		);
		$rows[] = array(
			'name'    => 'Force MySQL over Mysqli',
			'value'   => defined( 'WP_USE_EXT_MYSQL' ) && WP_USE_EXT_MYSQL,
			'comment' => '',
		);

		$rows[] = array(
			'name'  => 'DB Prefix',
			'value' => $wpdb->prefix,
		);

		$rows[] = array(
			'name'  => 'DB CHARSET',
			'value' => defined('DB_CHARSET') ? DB_CHARSET : '',
		);

		$rows[] = array(
			'name'  => 'DB COLLATE',
			'value' => defined('DB_COLLATE') ? DB_COLLATE : '',
		);

		$rows[] = array(
			'name'  => 'SHOW ERRORS',
			'value' => !empty($wpdb->show_errors),
		);

		$rows[] = array(
			'name'  => 'SUPPRESS ERRORS',
			'value' => !empty($wpdb->suppress_errors),
		);

		if ( method_exists( $wpdb, 'parse_db_host' ) ) {
			$host_data = $wpdb->parse_db_host( DB_HOST );
			if ( $host_data ) {
				list( $host, $port, $socket, $is_ipv6 ) = $host_data;
			}

			$rows[] = array(
				'name'  => 'Uses Socket',
				'value' => ! empty( $socket ),
			);
			$rows[] = array(
				'name'  => 'Uses IPv6',
				'value' => ! empty( $is_ipv6 ),
			);
		}

		$rows[] = array(
			'name'  => 'Matomo tables found',
			'value' => $this->get_num_matomo_tables(),
		);

		$missing_tables = $this->get_missing_tables();
		$has_missing_tables = ( count($missing_tables) > 0 );
		$rows[] = array(
			'name'          => 'DB tables exist',
			'value'         => ( ! $has_missing_tables ) ,
			'comment'       => $has_missing_tables ? sprintf( __('Some tables may be missing: %s', 'matomo'), implode(', ', $missing_tables ) ) : '',
			'is_error'      => $has_missing_tables
		);

		foreach (['user', 'site'] as $table) {
			$rows[] = array(
				'name'  => 'Matomo '.$table.'s found',
				'value' => $this->get_num_entries_in_table($table),
			);
		}

		$grants = $this->get_db_grants();

		// we only show these grants for security reasons as only they are needed and we don't need to know any other ones
		$needed_grants = array( 'SELECT', 'INSERT', 'UPDATE', 'INDEX', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'CREATE TEMPORARY TABLES', 'LOCK TABLES' );
		if ( in_array( 'ALL PRIVILEGES', $grants, true ) ) {
			// ALL PRIVILEGES may be used pre MySQL 8.0
			$grants = $needed_grants;
		}

		$grants_missing = array_diff( $needed_grants, $grants );

		if ( empty( $grants )
			 || ! is_array( $grants )
			 || count( $grants_missing ) === count( $needed_grants ) ) {
			$rows[] = array(
				'name'       => esc_html__( 'Required permissions', 'matomo' ),
				'value'      => esc_html__( 'Failed to detect granted permissions', 'matomo' ),
				'comment'    => esc_html__( 'Please check your MySQL user has these permissions (grants):', 'matomo' ) . '<br />' . implode( ', ', $needed_grants ),
				'is_warning' => false,
			);
		} else {
			if ( ! empty( $grants_missing ) ) {
				$rows[] = array(
					'name'       => esc_html__( 'Required permissions', 'matomo' ),
					'value'      => esc_html__( 'Error', 'matomo' ),
					'comment'    => esc_html__( 'Missing permissions', 'matomo' ) . ': ' . implode( ', ', $grants_missing ) . '. ' . __( 'Please check if any of these MySQL permission (grants) are missing and add them if needed.', 'matomo' ) . ' ' . __( 'Learn more', 'matomo' ) . ': https://matomo.org/faq/troubleshooting/how-do-i-check-if-my-mysql-user-has-all-required-grants/',
					'is_warning' => true,
				);
			} else {
				$rows[] = array(
					'name'       => esc_html__( 'Required permissions', 'matomo' ),
					'value'      => esc_html__( 'OK', 'matomo' ),
					'comment'    => '',
					'is_warning' => false,
				);
			}
		}

		return $rows;
	}

	/**
	 * @return string[]
	 */
	public function get_missing_tables() {
		global $wpdb;

		$required_matomo_tables = $this->dbSettings->get_matomo_tables();
		$required_matomo_tables = array_map( array( $this->dbSettings, 'prefix_table_name' ), $required_matomo_tables );

		$existing_tables = array();
		try {
			$prefix = $this->dbSettings->prefix_table_name('');
			$existing_tables = $wpdb->get_col( 'SHOW TABLES LIKE "' . $prefix . '%"' );
		} catch (\Exception $e) {
			$this->logger->log( 'no show tables: ' . $e->getMessage() );
		}
		return array_diff( $required_matomo_tables, $existing_tables );
	}

	private function get_num_entries_in_table($table) {
		global $wpdb;

		$prefix = $this->dbSettings->prefix_table_name($table);

		$results = null;
		try {
			$results = $wpdb->get_var('select count(*) from '.$prefix);
		} catch (\Exception $e) {
		}

		if (isset($results) && is_numeric($results)) {
			return $results;
		}

		return 'table not exists';
	}

	private function get_num_matomo_tables() {
		global $wpdb;

		$prefix = $this->dbSettings->prefix_table_name('');

		$results = null;
		try {
			$results = $wpdb->get_results('show tables like "'.$prefix.'%"');
		} catch (\Exception $e) {
			$this->logger->log('no show tables: ' . $e->getMessage());
		}

		if (is_array($results)) {
			return count($results);
		}

		return 'show tables not working';
	}

	private function get_db_grants() {
		global $wpdb;

		$suppress_errors = $wpdb->suppress_errors;
		$wpdb->suppress_errors( true );// prevent any of this showing in logs just in case

		try {
			$values = $wpdb->get_results( 'SHOW GRANTS', ARRAY_N );
		} catch ( \Exception $e ) {
			// We ignore any possible error in case of permission or not supported etc.
			$values = array();
		}

		$wpdb->suppress_errors( $suppress_errors );

		$grants = array();
		foreach ( $values as $index => $value ) {
			if ( empty( $value[0] ) || ! is_string( $value[0] ) ) {
				continue;
			}

			if ( stripos( $value[0], 'ALL PRIVILEGES' ) !== false ) {
				return array( 'ALL PRIVILEGES' ); // the split on empty string wouldn't work otherwise
			}

			foreach ( array( ' ON ', ' TO ', ' IDENTIFIED ', ' BY ' ) as $keyword ) {
				if ( stripos( $values[ $index ][0], $keyword ) !== false ) {
					// make sure to never show by any accident a db user or password by cutting anything after on/to
					$values[ $index ][0] = substr( $value[0], 0, stripos( $value[0], $keyword ) );
				}
				if ( stripos( $values[ $index ][0], 'GRANT' ) !== false ) {
					// otherwise we end up having "grant select"... instead of just "select"
					$values[ $index ][0] = substr( $value[0], stripos( $values[ $index ][0], 'GRANT' ) + 5 );
				}
			}
			// make sure to never show by any accident a db user or password
			$values[ $index ][0] = str_replace( array( DB_USER, DB_PASSWORD ), array( 'DB_USER', 'DB_PASS' ), $values[ $index ][0] );

			$grants = array_merge( $grants, explode( ',', $values[ $index ][0] ) );
		}
		$grants = array_map( 'trim', $grants );
		$grants = array_map( 'strtoupper', $grants );
		$grants = array_unique( $grants );
		return $grants;
	}

	private function get_plugins_info() {
		$rows       = array();
		$mu_plugins = get_mu_plugins();

		if ( ! empty( $mu_plugins ) ) {
			$rows[] = array(
				'section' => 'MU Plugins',
			);

			foreach ( $mu_plugins as $mu_pin ) {
				$comment = '';
				if ( ! empty( $plugin['Network'] ) ) {
					$comment = 'Network enabled';
				}
				$rows[] = array(
					'name'    => $mu_pin['Name'],
					'value'   => $mu_pin['Version'],
					'comment' => $comment,
				);
			}

			$rows[] = array(
				'section' => 'Plugins',
			);
		}

		$plugins = get_plugins();

		foreach ( $plugins as $plugin ) {
			$comment = '';
			if ( ! empty( $plugin['Network'] ) ) {
				$comment = 'Network enabled';
			}
			$rows[] = array(
				'name'    => $plugin['Name'],
				'value'   => $plugin['Version'],
				'comment' => $comment,
			);
		}

		$active_plugins = get_option( 'active_plugins', array() );

		if ( ! empty( $active_plugins ) && is_array( $active_plugins ) ) {
			$active_plugins = array_map(
				function ( $active_plugin ) {
					$parts = explode( '/', trim( $active_plugin ) );
					return trim( $parts[0] );
				},
				$active_plugins
			);

			$rows[] = array(
				'name'    => 'Active Plugins',
				'value'   => count( $active_plugins ),
				'comment' => implode( ' ', $active_plugins ),
			);

			$used_not_compatible = array_intersect( $active_plugins, $this->not_compatible_plugins );
			if ( ! empty( $used_not_compatible ) ) {

				$additional_comment = '';
				if (in_array('tweet-old-post-pro', $used_not_compatible)) {
					$additional_comment .= '<br><br>A workaround for Revive Old Posts Pro may be to add the following line to your "wp-config.php". <br><code>define( \'MATOMO_SUPPORT_ASYNC_ARCHIVING\', false );</code>.';
				}
				if (in_array('secupress', $used_not_compatible)) {
					$additional_comment .= '<br><br>If reports aren\'t being generated then you may need to disable the feature "Firewall -> Block Bad Request Methods" in SecuPress (if it is enabled) or add the following line to your "wp-config.php": <br><code>define( \'MATOMO_SUPPORT_ASYNC_ARCHIVING\', false );</code>.';
				}

				$is_warning = true;
				$is_error = false;
				if (in_array('cookiebot', $used_not_compatible)) {
					$is_warning = false;
					$is_error = true;
				}

				$rows[] = array(
					'name'     => __( 'Not compatible plugins', 'matomo' ),
					'value'    => count( $used_not_compatible ),
					'comment'  => implode( ', ', $used_not_compatible ) . '<br><br> Matomo may work fine when using these plugins but there may be some issues. For more information see<br>https://matomo.org/faq/wordpress/which-plugins-is-matomo-for-wordpress-known-to-be-not-compatible-with/ ' . $additional_comment,
					'is_warning' => $is_warning,
					'is_error' => $is_error,
				);
			}
		}

		$rows[] = array(
			'name' => 'Theme',
			'value' => function_exists('get_template') ? get_template() : '',
			'comment' => get_option('stylesheet')
		);


		if ( is_plugin_active('better-wp-security/better-wp-security.php')) {
			if (method_exists('\ITSEC_Modules', 'get_setting')
			    && \ITSEC_Modules::get_setting( 'system-tweaks', 'long_url_strings' ) ) {
				$rows[] = array(
					'name'     => 'iThemes Security Long URLs Enabled',
					'value'    => true,
					'comment'  => 'Tracking might not work because it looks like you have Long URLs disabled in iThemes Security. To fix this please go to "Security -> Settings -> System Tweaks" and disable the setting "Long URL Strings".',
					'is_error' => true,
				);
			}
		}

		return $rows;
	}


}
