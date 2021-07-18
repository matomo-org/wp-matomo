<?php
/**
 * @package matomo
 */

use WpMatomo\Access;
use WpMatomo\Admin\TrackingSettings;
use WpMatomo\Admin\CookieConsent;
use WpMatomo\Capabilities;
use WpMatomo\Roles;
use WpMatomo\Settings;
use WpMatomo\TrackingCode\TrackingCodeGenerator;

class TrackingCodeGeneratorTest extends MatomoUnit_TestCase {

	/**
	 * @var TrackingCodeGenerator
	 */
	private $tracking_code;

	/**
	 * @var Settings
	 */
	private $settings;

	public function setUp() {
		parent::setUp();

		$this->settings = new Settings();
		WpMatomo\Site::map_matomo_site_id( get_current_blog_id(), 21 );
	}

	private function make_tracking_code() {
		$this->tracking_code = new TrackingCodeGenerator( $this->settings );
	}

	private function get_tracking_code() {
		$this->make_tracking_code();

		return $this->tracking_code->get_tracking_code();
	}

	public function test_get_tracking_code_when_tracking_is_disabled() {
		$this->assertSame( '', $this->get_tracking_code() );
	}

	public function test_get_tracking_code_when_using_default_tracking_code() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode' => TrackingSettings::TRACK_MODE_DEFAULT,
			)
		);
		$this->assertSame(
			'<!-- Matomo --><script  >var _paq = window._paq = window._paq || [];
_paq.push([\'trackPageView\']);_paq.push([\'enableLinkTracking\']);_paq.push([\'alwaysUseSendBeacon\']);_paq.push([\'setTrackerUrl\', "\/\/example.org\/wp-content\/plugins\/matomo\/app\/matomo.php"]);_paq.push([\'setSiteId\', \'21\']);var d=document, g=d.createElement(\'script\'), s=d.getElementsByTagName(\'script\')[0];
g.type=\'text/javascript\'; g.async=true; g.src="\/\/example.org\/wp-content\/plugins\/matomo\/app\/matomo.js"; s.parentNode.insertBefore(g,s);</script><!-- End Matomo Code -->',
			$this->get_tracking_code()
		);
	}

	public function test_get_tracking_code_when_using_default_tracking_code_using_rest_api_and_other_features() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'                => TrackingSettings::TRACK_MODE_DEFAULT,
				'track_js_endpoint'         => 'restapi',
				'track_api_endpoint'        => 'restapi',
				'track_noscript'            => true,
				'track_content'             => 'all',
				'add_download_extensions'   => 'zip|waf',
				'set_link_classes'          => 'clickme|foo',
				'disable_cookies'           => true,
				'track_across'              => true,
				'track_crossdomain_linking' => true,
			)
		);

		$this->assertSame(
			'<!-- Matomo --><script  >var _paq = window._paq = window._paq || [];
_paq.push([\'addDownloadExtensions\', "zip|waf"]);
_paq.push([\'setLinkClasses\', "clickme|foo"]);
_paq.push([\'disableCookies\']);
_paq.push([\'enableCrossDomainLinking\']);
_paq.push(["setCookieDomain", "*.example.org"]);
_paq.push([\'trackAllContentImpressions\']);_paq.push([\'trackPageView\']);_paq.push([\'enableLinkTracking\']);_paq.push([\'alwaysUseSendBeacon\']);_paq.push([\'setTrackerUrl\', "\/\/example.org\/index.php?rest_route=\/matomo\/v1\/hit\/"]);_paq.push([\'setSiteId\', \'21\']);var d=document, g=d.createElement(\'script\'), s=d.getElementsByTagName(\'script\')[0];
g.type=\'text/javascript\'; g.async=true; g.src="\/\/example.org\/index.php?rest_route=\/matomo\/v1\/hit\/"; s.parentNode.insertBefore(g,s);</script><!-- End Matomo Code -->',
			$this->get_tracking_code()
		);
	}

	public function test_get_tracker_endpoint() {
		$this->make_tracking_code();
		$this->assertSame(
			'//example.org/wp-content/plugins/matomo/app/matomo.php',
			$this->tracking_code->get_tracker_endpoint()
		);

		$this->settings->apply_tracking_related_changes(
			array(
				'track_api_endpoint' => 'restapi',
			)
		);

		$this->assertSame(
			'//example.org/index.php?rest_route=/matomo/v1/hit/',
			$this->tracking_code->get_tracker_endpoint()
		);

		$this->settings->apply_tracking_related_changes(
			array(
				'force_protocol' => 'https',
			)
		);

		$this->assertSame(
			'https://example.org/index.php?rest_route=/matomo/v1/hit/',
			$this->tracking_code->get_tracker_endpoint()
		);
	}

	public function test_get_js_endpoint() {
		$this->make_tracking_code();
		$this->assertSame(
			'//example.org/wp-content/plugins/matomo/app/matomo.js',
			$this->tracking_code->get_js_endpoint()
		);

		$this->settings->apply_tracking_related_changes(
			array(
				'track_js_endpoint' => 'restapi',
			)
		);

		$this->assertSame(
			'//example.org/index.php?rest_route=/matomo/v1/hit/',
			$this->tracking_code->get_js_endpoint()
		);

		$this->settings->apply_tracking_related_changes(
			array(
				'force_protocol' => 'https',
			)
		);

		$this->assertSame(
			'https://example.org/index.php?rest_route=/matomo/v1/hit/',
			$this->tracking_code->get_js_endpoint()
		);
	}

	public function test_get_tracking_code_test_user_id() {
		$id1 = self::factory()->user->create();

		wp_set_current_user( $id1 );
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'    => TrackingSettings::TRACK_MODE_DEFAULT,
				'track_user_id' => 'uid',
			)
		);
		$this->assertContains( "_paq.push(['setUserId', '$id1']);", $this->get_tracking_code() );
	}

	public function test_get_tracking_code_when_using_manually_tracking_code() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'    => TrackingSettings::TRACK_MODE_MANUALLY,
				'tracking_code' => '<script>foobar</script>',
			)
		);
		$this->assertSame( '<script>foobar</script>', $this->get_tracking_code() );
	}

	public function test_get_tracking_code_when_using_tagmanager_mode() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'              => TrackingSettings::TRACK_MODE_TAGMANAGER,
				'tagmanger_container_ids' => array(
					'abcdefgh' => 1,
					'cfk3jjw'  => 0,
				),
			)
		);

		if ( is_multisite() ) {
			$this->assertSame( '<!-- Matomo: no supported track_mode selected -->', $this->get_tracking_code() );
		} else {
			$this->assertSame(
				'<!-- Matomo Tag Manager -->
<script >
var _mtm = _mtm || [];
_mtm.push({\'mtm.startTime\': (new Date().getTime()), \'event\': \'mtm.Start\'});
var d=document, g=d.createElement(\'script\'), s=d.getElementsByTagName(\'script\')[0];
g.type=\'text/javascript\'; g.async=true; g.src="http://example.org/wp-content/uploads/matomo/container_abcdefgh.js"; s.parentNode.insertBefore(g,s);
</script><!-- End Matomo Tag Manager -->',
				$this->get_tracking_code()
			);
		}
	}

	public function test_get_tracking_code_when_using_tagmanager_mode_and_no_containers() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'              => TrackingSettings::TRACK_MODE_TAGMANAGER,
				'tagmanger_container_ids' => array(),
			)
		);
		if ( is_multisite() ) {
			$this->assertSame( '<!-- Matomo: no supported track_mode selected -->', $this->get_tracking_code() );
		} else {
			$this->assertSame( '<!-- Matomo Tag Manager --><!-- End Matomo Tag Manager -->', $this->get_tracking_code() );
		}
	}

	public function test_cookie_consent_tagmanager() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'     => TrackingSettings::TRACK_MODE_TAGMANAGER,
				'cookie_consent' => CookieConsent::REQUIRE_COOKIE_CONSENT,
			)
		);
		$this->assertNotContains( "_paq.push(['requireCookieConsent']);", $this->get_tracking_code() );
		$this->assertNotContains( "_paq.push(['requireConsent']);", $this->get_tracking_code() );
	}

	public function test_cookie_consent_manually() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'     => TrackingSettings::TRACK_MODE_MANUALLY,
				'cookie_consent' => CookieConsent::REQUIRE_COOKIE_CONSENT,
			)
		);
		$this->assertNotContains( "_paq.push(['requireCookieConsent']);", $this->get_tracking_code() );
		$this->assertNotContains( "_paq.push(['requireConsent']);", $this->get_tracking_code() );
	}

	public function test_cookie_consent_none() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'     => TrackingSettings::TRACK_MODE_DEFAULT,
				'cookie_consent' => CookieConsent::REQUIRE_NONE,
			)
		);
		$this->assertNotContains( "_paq.push(['requireCookieConsent']);", $this->get_tracking_code() );
		$this->assertNotContains( "_paq.push(['requireConsent']);", $this->get_tracking_code() );
	}

	public function test_cookie_consent_cookie() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'     => TrackingSettings::TRACK_MODE_DEFAULT,
				'cookie_consent' => CookieConsent::REQUIRE_COOKIE_CONSENT,
			)
		);
		$this->assertContains( "_paq.push(['requireCookieConsent']);", $this->get_tracking_code() );
	}

	public function test_cookie_consent_tracking() {
		$this->settings->apply_tracking_related_changes(
			array(
				'track_mode'     => TrackingSettings::TRACK_MODE_DEFAULT,
				'cookie_consent' => CookieConsent::REQUIRE_TRACKING_CONSENT,
			)
		);
		$this->assertContains( "_paq.push(['requireConsent']);", $this->get_tracking_code() );
	}
}
