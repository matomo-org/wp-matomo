<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace WpMatomo\Admin;

use Piwik\Plugins\UsersManager\UserPreferences;
use WpMatomo\Bootstrap;
use WpMatomo\Capabilities;
use WpMatomo\Marketplace\Api as MarketplaceApi;
use WpMatomo\Report\Dates;
use WpMatomo\Settings;
use WpMatomo\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

class Menu {
	/**
	 * @var Settings
	 */
	private $settings;

	private $parentSlug = 'matomo';

	const REPORTING_GOTO_ADMIN = 'matomo-admin';
	const REPORTING_GOTO_GDPR_TOOLS = 'matomo-gdpr-tools';
	const REPORTING_GOTO_GDPR_OVERVIEW = 'matomo-gdpr-overview';
	const REPORTING_GOTO_ASK_CONSENT = 'matomo-gdpr-consent';
	const REPORTING_GOTO_OPTOUT = 'matomo-privacy-optout';
	const REPORTING_GOTO_ANONYMIZE_DATA = 'matomo-anonymize-date';
	const REPORTING_GOTO_DATA_RETENTION = 'matomo-data-retention';
	const SLUG_SYSTEM_REPORT = 'matomo-systemreport';
	const SLUG_REPORT_SUMMARY = 'matomo-summary';
	const SLUG_TAGMANAGER = 'matomo-tagmanager';
	const SLUG_REPORTING = 'matomo-reporting';
	const SLUG_SETTINGS = 'matomo-settings';
	const SLUG_GET_STARTED = 'matomo-get-started';
	const SLUG_ABOUT = 'matomo-about';
	const SLUG_MARKETPLACE = 'matomo-plugins';

	const CAP_NOT_EXISTS = 'unknownfoobar';

	/**
	 * @param Settings $settings
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
		// Hook for adding admin menus
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_head', array( $this, 'menu_external_icons' ) );

		// as we are redirecting we need to perform the redirect as soon as possible before WP has eg echoed the header
		add_action( 'load-matomo-analytics_page_' . self::SLUG_REPORTING, array( $this, 'reporting' ) );
		add_action( 'load-' . $this->parentSlug . '_page_' . self::SLUG_REPORTING, array( $this, 'reporting' ) );
		add_action( 'load-matomo-analytics_page_' . self::SLUG_TAGMANAGER, array( $this, 'tagmanager' ) );
		add_action( 'load-' . $this->parentSlug . '_page_' . self::SLUG_TAGMANAGER, array( $this, 'tagmanager' ) );
	}

	public function add_menu() {
		$info          = new Info();
		$get_started   = new GetStarted( $this->settings );
		$api           = new MarketplaceApi( $this->settings );
		$marketplace   = new Marketplace( $this->settings, $api );
		$system_report = new SystemReport( $this->settings );
		$summary       = new Summary( $this->settings );

		$admin_settings = new AdminSettings( $this->settings );

		// managing matomo only works when
		// * Network mode is enabled and then it works only in the network mode
		// * Network mode is not enabled then it works only for individual blogs as they manage it themselves
		$can_matomo_be_managed = $this->settings->is_network_enabled() || ! is_network_admin();

		add_menu_page( 'Matomo Analytics', 'Matomo Analytics', self::CAP_NOT_EXISTS, 'matomo', null, 'dashicons-analytics' );

		if ( $this->settings->get_global_option( Settings::SHOW_GET_STARTED_PAGE ) && $get_started->can_user_manage() ) {
			add_submenu_page( $this->parentSlug, __( 'Get Started', 'matomo' ), __( 'Get Started', 'matomo' ), Capabilities::KEY_SUPERUSER, self::SLUG_GET_STARTED, array(
				$get_started,
				'show'
			) );
		}

		if ( is_network_admin() ) {
			add_submenu_page( $this->parentSlug, __( 'Multi Site', 'matomo' ), __( 'Multi Site', 'matomo' ), Capabilities::KEY_SUPERUSER, 'matomo-multisite', array(
				$info,
				'show_multisite'
			) );
		} else {
			add_submenu_page( $this->parentSlug, __( 'Summary', 'matomo' ), __( 'Summary', 'matomo' ), Capabilities::KEY_VIEW, self::SLUG_REPORT_SUMMARY, array(
				$summary,
				'show'
			) );

			// the network itself is not a blog
			add_submenu_page( $this->parentSlug, __( 'Reporting', 'matomo' ), __( 'Reporting', 'matomo' ), Capabilities::KEY_VIEW, self::SLUG_REPORTING, array(
				$this,
				'reporting'
			) );
			// the network itself is not a blog
			add_submenu_page( $this->parentSlug, __( 'Tag Manager', 'matomo' ), __( 'Tag Manager', 'matomo' ), Capabilities::KEY_WRITE, self::SLUG_TAGMANAGER, array(
				$this,
				'tagmanager'
			) );
		}

		if ( $can_matomo_be_managed ) {
			add_submenu_page( $this->parentSlug, __( 'Settings', 'matomo' ), __( 'Settings', 'matomo' ), Capabilities::KEY_SUPERUSER, self::SLUG_SETTINGS, array(
				$admin_settings,
				'show'
			) );
		}

		/*
		add_submenu_page( $this->parentSlug, __( 'Marketplace', 'matomo' ), __( 'Marketplace', 'matomo' ), Capabilities::KEY_VIEW, self::SLUG_MARKETPLACE, array(
			$marketplace,
			'show'
		) );
		*/

		if ( $can_matomo_be_managed ) {
			add_submenu_page( $this->parentSlug, __( 'System Report', 'matomo' ), __( 'System Report', 'matomo' ), Capabilities::KEY_SUPERUSER, self::SLUG_SYSTEM_REPORT, array(
				$system_report,
				'show'
			) );
		}

		add_submenu_page( $this->parentSlug, __( 'About', 'matomo' ), __( 'About', 'matomo' ), Capabilities::KEY_VIEW, self::SLUG_ABOUT, array(
			$info,
			'show'
		) );
	}

	public function menu_external_icons() {
		global $submenu;

		if ( isset( $submenu[ $this->parentSlug ] ) ) {
			$reporting  = __( 'Reporting', 'matomo' );
			$tagmanager = __( 'Tag Manager', 'matomo' );
			foreach ( $submenu[ $this->parentSlug ] as $key => $menu_item ) {
				if ( 0 === strpos( $menu_item[0], $reporting ) || 0 === strpos( $menu_item[0], $tagmanager ) ) {
					$submenu[ $this->parentSlug ][ $key ][0] .= ' <span class="dashicons-before dashicons-external"></span>';
				}
			}
		}
	}

	public static function get_matomo_goto_url( $goto ) {
		return add_query_arg( array( 'goto' => $goto ), menu_page_url( Menu::SLUG_REPORTING, false ) );
	}

	public static function get_reporting_url() {
		return plugins_url( 'app', MATOMO_ANALYTICS_FILE ) . '/index.php';
	}

	public function tagmanager() {
		if ( has_matomo_tag_manager() ) {
			$this->go_to_matomo_page( 'TagManager', 'manageContainers', Capabilities::KEY_WRITE );
		}
		exit;
	}

	public function reporting() {
		if ( ! empty( $_GET['goto'] ) ) {
			switch ( $_GET['goto'] ) {
				case self::REPORTING_GOTO_ADMIN:
					$this->go_to_matomo_page( 'CoreAdminHome', 'home', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_GDPR_TOOLS:
					$this->go_to_matomo_page( 'PrivacyManager', 'gdprTools', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_GDPR_OVERVIEW:
					$this->go_to_matomo_page( 'PrivacyManager', 'gdprOverview', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_ASK_CONSENT:
					$this->go_to_matomo_page( 'PrivacyManager', 'consent', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_OPTOUT:
					$this->go_to_matomo_page( 'PrivacyManager', 'usersOptOut', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_ANONYMIZE_DATA:
					$this->go_to_matomo_page( 'PrivacyManager', 'privacySettings', Capabilities::KEY_SUPERUSER );
					break;
				case self::REPORTING_GOTO_DATA_RETENTION:
					$this->go_to_matomo_page( 'CoreAdminHome', 'generalSettings', Capabilities::KEY_SUPERUSER );
					break;
			}
		}

		$url = self::get_reporting_url();

		if ( ! empty( $_GET['report_date'] ) ) {
			$url = add_query_arg( array( 'module' => 'CoreHome', 'action' => 'index' ), $url );

			$site   = new Site();
			$idsite = $site->get_current_matomo_site_id();

			if ( $idsite ) {
				$url = add_query_arg( array( 'idSite' => (int) $idsite ), $url );
			}

			$date = new Dates();
			list( $period, $date ) = $date->detect_period_and_date( $_GET['report_date'] );
			$url = add_query_arg( array( 'period' => $period, 'date' => $date ), $url );
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * @api
	 */
	public static function get_matomo_reporting_url( $category, $subcategory, $params = array() ) {
		$site   = new Site();
		$idsite = $site->get_current_matomo_site_id();

		if ( ! $idsite ) {
			return;
		}

		$idsite                = (int) $idsite;
		$params['category']    = $category;
		$params['subcategory'] = $subcategory;
		$params['idSite']      = $idsite;

		if ( empty( $params['period'] ) ) {
			$params['period'] = 'day';
		}
		if ( empty( $params['date'] ) ) {
			$params['date'] = 'today';
		}

		$url = self::make_matomo_app_base_url();
		$url .= '?module=CoreHome&action=index&idSite=' . (int) $idsite . '&period=' . urlencode( $params['period'] ) . '&date=' . urlencode( $params['date'] ) . '#?&' . http_build_query( $params );

		return $url;
	}

	private static function make_matomo_app_base_url() {
		$url = plugins_url( 'app', MATOMO_ANALYTICS_FILE );

		return $url . '/index.php';
	}

	/**
	 * @api
	 */
	public static function get_matomo_action_url( $module, $action, $params = array() ) {
		$site   = new Site();
		$idsite = $site->get_current_matomo_site_id();

		if ( ! $idsite ) {
			return;
		}

		$idsite           = (int) $idsite;
		$params['module'] = $module;
		$params['action'] = $action;
		$params['idSite'] = $idsite;

		if ( empty( $params['period'] ) ) {
			$params['period'] = 'day';
		}
		if ( empty( $params['date'] ) ) {
			$params['date'] = 'today';
		}

		$url = self::make_matomo_app_base_url() . '?' . http_build_query( $params );

		return $url;
	}

	public function go_to_matomo_page( $module, $action, $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return;
		}
		Bootstrap::do_bootstrap();

		$user_preferences = new UserPreferences();
		$website_id       = $user_preferences->getDefaultWebsiteId();
		$default_date     = $user_preferences->getDefaultDate();
		$default_period   = $user_preferences->getDefaultPeriod( false );

		$url = self::make_matomo_app_base_url();
		$url .= '?idSite=' . (int) $website_id . '&period=' . urlencode( $default_period ) . '&date=' . urlencode( $default_date );
		$url .= '&module=' . urlencode( $module ) . '&action=' . urlencode( $action );
		wp_redirect( $url );
		exit;
	}

}
