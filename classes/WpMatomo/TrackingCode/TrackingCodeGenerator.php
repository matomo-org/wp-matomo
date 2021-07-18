<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

namespace WpMatomo\TrackingCode;

use WpMatomo\Admin\TrackingSettings;
use WpMatomo\Admin\CookieConsent;
use WpMatomo\Logger;
use WpMatomo\Paths;
use WpMatomo\Settings;
use WpMatomo\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

class TrackingCodeGenerator {

	const TRACKPAGEVIEW = "_paq.push(['trackPageView']);";
	const MTM_INIT      = 'var _mtm = _mtm || [];';

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @param Settings $settings
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
		$this->logger   = new Logger();
	}

	public function register_hooks() {
		add_action( 'matomo_site_synced', array( $this, 'update_tracking_code' ), $prio = 10, $args = 0 );
		add_action( 'matomo_tracking_settings_changed', array( $this, 'update_tracking_code' ), $prio = 10, $args = 0 );
	}

	public function update_tracking_code() {
		if ( $this->settings->is_current_tracking_code()
			 && $this->settings->get_option( 'tracking_code' ) ) {
			return false;
		}

		$track_mode = $this->settings->get_global_option( 'track_mode' );

		if ( ! $this->settings->is_tracking_enabled()
			 || $track_mode == TrackingSettings::TRACK_MODE_MANUALLY ) {
			return false;
		}

		$blod_id = get_current_blog_id();
		$idsite  = Site::get_matomo_site_id( $blod_id );

		if ( ! $idsite ) {
			$this->logger->log( 'Not found related idSite for blog ' . get_current_blog_id() );

			return false;
		}

		if ( TrackingSettings::TRACK_MODE_DEFAULT === $track_mode ) {
			$result = $this->prepare_tracking_code( $idsite );

			if ( ! $this->settings->get_global_option( 'track_noscript' ) ) {
				$result['noscript'] = '';
			}
		} elseif ( TrackingSettings::TRACK_MODE_TAGMANAGER === $track_mode && matomo_has_tag_manager() ) {
			$result = $this->prepare_tagmanger_code( $this->settings, $this->logger );
		} else {
			$result = array(
				'script'   => '<!-- Matomo: no supported track_mode selected -->',
				'noscript' => '',
			);
		}

		if ( ! empty( $result['script'] ) ) {
			$this->settings->set_option( 'tracking_code', $result['script'] );
			$this->settings->set_option( 'noscript_code', $result['noscript'] );
		}

		$this->settings->set_option( Settings::OPTION_LAST_TRACKING_CODE_UPDATE, time() );
		$this->settings->save();

		return $result;
	}

	public function get_noscript_code() {
		$this->update_tracking_code();

		return $this->settings->get_noscript_tracking_code();
	}

	public function get_tracking_code() {
		$this->update_tracking_code();

		$tracking_code = $this->settings->get_js_tracking_code();

		if ( $this->settings->track_user_id_enabled() ) {
			$tracking_code = $this->apply_user_tracking( $tracking_code );
		}
		if ( $this->settings->track_404_enabled() && is_404() ) {
			$tracking_code = $this->apply_404_changes( $tracking_code );
		}
		if ( $this->settings->track_search_enabled() ) {
			$tracking_code = $this->apply_search_changes( $tracking_code );
		}

		return $tracking_code;
	}

	/**
	 * @param Settings $settings
	 * @param Logger   $logger
	 *
	 * @return array
	 */
	private function prepare_tagmanger_code( $settings, $logger ) {
		$logger->log( 'Apply tag manager code changes:' );

		$container_ids = $settings->get_global_option( 'tagmanger_container_ids' );

		$code = '<!-- Matomo Tag Manager -->';

		if ( ! empty( $container_ids ) && is_array( $container_ids ) ) {
			$paths      = new Paths();
			$upload_url = $paths->get_upload_base_url();

			foreach ( $container_ids as $container_id => $enabled ) {
				if ( $enabled
					 && ctype_alnum( $container_id )
					 && strlen( $container_id ) <= 16 ) {
					$container_url = $upload_url . '/container_' . urlencode( $container_id ) . '.js';

					$data_cf_async = '';
					if ( $settings->get_global_option( 'track_datacfasync' ) ) {
						$data_cf_async = 'data-cfasync="false"';
					}

					if ( $settings->get_global_option( 'force_protocol' ) == 'https' ) {
						$container_url = preg_replace( '(^http://)', 'https://', $container_url );
					}

					$code .= '
<script ' . $data_cf_async . '>
' . self::MTM_INIT . '
_mtm.push({\'mtm.startTime\': (new Date().getTime()), \'event\': \'mtm.Start\'});
var d=document, g=d.createElement(\'script\'), s=d.getElementsByTagName(\'script\')[0];
g.type=\'text/javascript\'; g.async=true; g.src="' . $container_url . '"; s.parentNode.insertBefore(g,s);
</script>';
				}
			}
		}

		$code .= '<!-- End Matomo Tag Manager -->';

		return array(
			'script'   => $code,
			'noscript' => '',
		);
	}

	public function get_tracker_endpoint() {
		$paths = new Paths();

		if ( $this->settings->get_global_option( 'track_api_endpoint' ) === 'restapi' ) {
			$tracker_endpoint = $paths->get_tracker_api_rest_api_endpoint();
		} else {
			$tracker_endpoint = $paths->get_tracker_api_url_in_matomo_dir();
		}

		if ( $this->settings->get_global_option( 'force_protocol' ) === 'https' ) {
			$tracker_endpoint = preg_replace( '(^http://)', 'https://', $tracker_endpoint );
		} else {
			$tracker_endpoint = preg_replace( '(^https?://)', '//', $tracker_endpoint );
		}

		return $tracker_endpoint;
	}

	public function get_js_endpoint() {
		 $paths = new Paths();
		if ( $this->settings->get_global_option( 'track_js_endpoint' ) === 'restapi' ) {
			$js_endpoint = $paths->get_js_tracker_rest_api_endpoint();
		} elseif ( $this->settings->get_global_option( 'track_js_endpoint' ) === 'plugin' ) {
			$js_endpoint = plugins_url( 'app/matomo.js', MATOMO_ANALYTICS_FILE );;
		} else {
			$js_endpoint = $paths->get_js_tracker_url_in_matomo_dir();
		}

		if ( $this->settings->get_global_option( 'force_protocol' ) === 'https' ) {
			$js_endpoint = preg_replace( '(^http://)', 'https://', $js_endpoint );
		} else {
			$js_endpoint = preg_replace( '(^https?://)', '//', $js_endpoint );
		}

		return $js_endpoint;
	}

	/**
	 * @param $idsite
	 *
	 * @return array
	 */
	public function prepare_tracking_code( $idsite ) {
		$logLevel = is_admin() ? Logger::LEVEL_DEBUG : Logger::LEVEL_INFO;

		$this->logger->log( 'Apply tracking code changes:', $logLevel );

		$tracker_endpoint = $this->get_tracker_endpoint();
		$js_endpoint      = $this->get_js_endpoint();

		$options = array();

		if ( $this->settings->get_global_option( 'set_download_extensions' ) ) {
			$options[] = "_paq.push(['setDownloadExtensions', " . wp_json_encode( $this->settings->get_global_option( 'set_download_extensions' ) ) . ']);';
		}
		if ( $this->settings->get_global_option( 'add_download_extensions' ) ) {
			$options[] = "_paq.push(['addDownloadExtensions', " . wp_json_encode( $this->settings->get_global_option( 'add_download_extensions' ) ) . ']);';
		}
		if ( $this->settings->get_global_option( 'set_download_classes' ) ) {
			$options[] = "_paq.push(['setDownloadClasses', " . wp_json_encode( $this->settings->get_global_option( 'set_download_classes' ) ) . ']);';
		}
		if ( $this->settings->get_global_option( 'set_link_classes' ) ) {
			$options[] = "_paq.push(['setLinkClasses', " . wp_json_encode( $this->settings->get_global_option( 'set_link_classes' ) ) . ']);';
		}
		if ( $this->settings->get_global_option( 'disable_cookies' ) ) {
			$options[] = "_paq.push(['disableCookies']);";
		}
		if ( $this->settings->get_global_option( 'track_crossdomain_linking' ) ) {
			$options[] = "_paq.push(['enableCrossDomainLinking']);";
		}
		if ( $this->settings->get_global_option( 'track_jserrors' ) ) {
			$options[] = "_paq.push(['enableJSErrorTracking']);";
		}

		$cookie_domain = $this->settings->get_tracking_cookie_domain();
		if ( ! empty( $cookie_domain ) ) {
			$options[] = '_paq.push(["setCookieDomain", ' . wp_json_encode( $cookie_domain ) . ']);';
		}

		$track_across_alias = $this->settings->get_global_option( 'track_across_alias' );

		if ( $track_across_alias ) {
			// todo detect more hosts such as when using WPML etc
			$hosts = array( @parse_url( home_url(), PHP_URL_HOST ) );
			$hosts = array_filter( $hosts );
			$hosts = array_map(
				function ( $host ) {
						return '*.' . $host;
				},
				$hosts
			);
			if ( ! empty( $hosts ) ) {
				$options[] = '_paq.push(["setDomains", ' . wp_json_encode( $hosts ) . ']);';
			}
		}
		if ( $this->settings->get_global_option( 'force_post' ) ) {
			$options[] = "_paq.push(['setRequestMethod', 'POST']);";
		}

		$cookieConsent = new CookieConsent();
		$cookie_consent_option = $cookieConsent->get_tracking_consent_option( $this->settings->get_global_option( 'cookie_consent' ) );
		// for unit test cases
		if ( ! empty( $cookie_consent_option ) ) {
			$options[] = $cookie_consent_option;
		}

		if ( $this->settings->get_global_option( 'limit_cookies' ) ) {
			$options[] = "_paq.push(['setVisitorCookieTimeout', " . wp_json_encode( $this->settings->get_global_option( 'limit_cookies_visitor' ) ) . ']);';
			$options[] = "_paq.push(['setSessionCookieTimeout', " . wp_json_encode( $this->settings->get_global_option( 'limit_cookies_session' ) ) . ']);';
			$options[] = "_paq.push(['setReferralCookieTimeout', " . wp_json_encode( $this->settings->get_global_option( 'limit_cookies_referral' ) ) . ']);';
		}
		if ( $this->settings->get_global_option( 'track_content' ) === 'all' ) {
			$options[] = "_paq.push(['trackAllContentImpressions']);";
		} elseif ( $this->settings->get_global_option( 'track_content' ) === 'visible' ) {
			$options[] = "_paq.push(['trackVisibleContentImpressions']);";
		}
		if ( (int) $this->settings->get_global_option( 'track_heartbeat' ) > 0 ) {
			$options[] = "_paq.push(['enableHeartBeatTimer', " . intval( $this->settings->get_global_option( 'track_heartbeat' ) ) . ']);';
		}

		$data_cf_async = '';
		if ( $this->settings->get_global_option( 'track_datacfasync' ) ) {
			$data_cf_async = 'data-cfasync="false"';
		}


		$script  = '<!-- Matomo -->';
		$script .= '<script ' . $data_cf_async . ' >';
		$script .= "var _paq = window._paq = window._paq || [];\n";
		$script .= implode( "\n", $options );
		$script .= self::TRACKPAGEVIEW;
		$script .= "_paq.push(['enableLinkTracking']);_paq.push(['alwaysUseSendBeacon']);";
		$script .= "_paq.push(['setTrackerUrl', " . wp_json_encode( $tracker_endpoint ) . ']);';
		$script .= "_paq.push(['setSiteId', '" . intval( $idsite ) . "']);";
		$script .= "var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
g.type='text/javascript'; g.async=true; g.src=" . wp_json_encode( $js_endpoint ) . '; s.parentNode.insertBefore(g,s);';
		$script .= '</script>';
		$script .= '<!-- End Matomo Code -->';

		$no_script = '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="' . esc_url( $tracker_endpoint ) . '?idsite=' . intval( $idsite ) . '&amp;rec=1" style="border:0;" alt="" /></p></noscript>';

		$script = apply_filters( 'matomo_tracking_code_script', $script, $idsite );
		$script = apply_filters( 'matomo_tracking_code_noscript', $script, $idsite );

		$this->logger->log( 'Finished tracking code: ' . $script, $logLevel );
		$this->logger->log( 'Finished noscript code: ' . $no_script, $logLevel);

		return array(
			'script'   => $script,
			'noscript' => $no_script,
		);
	}

	private function apply_404_changes( $tracking_code ) {
		$this->logger->log( 'Apply 404 tracking changes. Blog ID: ' . get_current_blog_id() );

		$code          = "_paq.push(['setDocumentTitle', '404/URL = '+String(document.location.pathname+document.location.search).replace(/\//g,'%2f') + '/From = ' + String(document.referrer).replace(/\//g,'%2f')]);";
		$tracking_code = str_replace( self::TRACKPAGEVIEW, $code . self::TRACKPAGEVIEW, $tracking_code );
		$tracking_code = str_replace( self::MTM_INIT, $code . self::MTM_INIT, $tracking_code );

		return $tracking_code;
	}

	private function apply_search_changes( $tracking_code ) {
		$this->logger->log( 'Apply search tracking changes. Blog ID: ' . get_current_blog_id() );
		$obj_search       = new \WP_Query( 's=' . get_search_query() . '&showposts=-1' );
		$int_result_count = $obj_search->post_count;

		$code          = "window._paq = window._paq || []; window._paq.push(['trackSiteSearch','" . get_search_query() . "', false, " . $int_result_count . "]);\n";
		$tracking_code = str_replace( self::TRACKPAGEVIEW, $code . self::TRACKPAGEVIEW, $tracking_code );
		$tracking_code = str_replace( self::MTM_INIT, $code . self::MTM_INIT, $tracking_code );

		return $tracking_code;
	}

	private function apply_user_tracking( $tracking_code ) {
		$user_id_to_track = null;
		if ( \is_user_logged_in() ) {
			// Get the User ID Admin option, and the current user's data
			$uid_from     = $this->settings->get_global_option( 'track_user_id' );
			$current_user = wp_get_current_user(); // current user
			// Get the user ID based on the admin setting
			if ( 'uid' === $uid_from ) {
				$user_id_to_track = $current_user->ID;
			} elseif ( 'email' === $uid_from ) {
				$user_id_to_track = $current_user->user_email;
			} elseif ( 'username' === $uid_from ) {
				$user_id_to_track = $current_user->user_login;
			} elseif ( 'displayname' === $uid_from ) {
				$user_id_to_track = $current_user->display_name;
			}
		}
		$user_id_to_track = apply_filters( 'matomo_tracking_user_id', $user_id_to_track );
		// Check we got a User ID to track, and track it
		if ( isset( $user_id_to_track ) && ! empty( $user_id_to_track ) ) {
			$code          = "window._paq = window._paq || []; window._paq.push(['setUserId', '" . esc_js( $user_id_to_track ) . "']);\n";
			$tracking_code = str_replace( self::TRACKPAGEVIEW, $code . self::TRACKPAGEVIEW, $tracking_code );
			$tracking_code = str_replace( self::MTM_INIT, $code . self::MTM_INIT, $tracking_code );
		}

		return $tracking_code;
	}

}
