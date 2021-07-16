<?php
/**
 * @package matomo
 */

use WpMatomo\Admin\TrackingSettings;
use WpMatomo\Capabilities;
use WpMatomo\Roles;
use WpMatomo\Settings;

class AdminTrackingSettingsTest extends MatomoUnit_TestCase {

	/**
	 * @var TrackingSettings
	 */
	private $tracking_settings;

	/**
	 * @var Settings
	 */
	private $settings;

	public function setUp() {
		parent::setUp();

		$this->settings          = new Settings();
		$this->tracking_settings = new TrackingSettings( $this->settings );

		$this->create_set_super_admin();
		$this->assume_admin_page();
	}

	public function tearDown() {
		$_REQUEST = array();
		$_POST    = array();
		parent::tearDown();
	}

	public function test_show_settings_renders_ui() {
		ob_start();
		$this->tracking_settings->show_settings();
		$output = ob_get_clean();
		$this->assertNotEmpty( $output );
		$this->assertContains( 'Tracking code', $output );
	}

	public function test_show_settings_does_change_any_values_if_nonce() {
		$this->assertSame( TrackingSettings::TRACK_MODE_DISABLED, $this->settings->get_global_option( 'track_mode' ) );
		$this->assertSame( 'default', $this->settings->get_global_option( 'track_js_endpoint' ) );
		$this->assertEquals( false, $this->settings->get_global_option( 'track_404' ) );

		$this->fake_request(
			array(
				'track_mode'        => TrackingSettings::TRACK_MODE_MANUALLY,
				'track_js_endpoint' => 'restapi',
				'track_404'         => true,
			)
		);

		ob_start();
		$this->tracking_settings->show_settings();
		ob_end_clean();

		$this->assertSame( TrackingSettings::TRACK_MODE_MANUALLY, $this->settings->get_global_option( 'track_mode' ) );
		$this->assertSame( 'restapi', $this->settings->get_global_option( 'track_js_endpoint' ) );
		$this->assertEquals( true, $this->settings->get_global_option( 'track_404' ) );
	}

	private function fake_request( $params ) {
		$_POST[ TrackingSettings::FORM_NAME ] = $params;
		$_REQUEST['_wpnonce']                 = wp_create_nonce( TrackingSettings::NONCE_NAME );
		$_SERVER['REQUEST_URI']               = home_url();
	}

	public function test_show_settings_does_not_set_any_random_value_but_only_whitelisted() {
		$this->fake_request(
			array(
				'track_mode'                     => 'disabled',
				'foobar'                         => 'baz',
				Settings::OPTION_KEY_CAPS_ACCESS => array( 'editor' => Capabilities::KEY_VIEW ),
			)
		);

		ob_start();
		$this->tracking_settings->show_settings();
		ob_end_clean();

		$this->assertEmpty( $this->settings->get_global_option( 'foobar' ) );
		$this->assertEmpty( $this->settings->get_option( 'foobar' ) );
		$this->assertEquals( array(), $this->settings->get_global_option( Settings::OPTION_KEY_CAPS_ACCESS ) );
	}

	public function test_get_active_containers_when_no_container_defined() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'skipped in multisite' );

			return;
		}
		$containers = $this->tracking_settings->get_active_containers();
		$this->assertSame( array(), $containers );
	}

	public function test_validate_html_comments() {
		$html = '<div></div>';
		$this->assertTrue( $this->tracking_settings->validate_html_comments( $html ) );
		$html = '<script></script>';
		$this->assertTrue( $this->tracking_settings->validate_html_comments( $html ) );
		$html = '<!-- begin comment--><script></script><!-- end comment -->';
		$this->assertTrue( $this->tracking_settings->validate_html_comments( $html ) );
		$html = '<!-- begin comment--><script></script>';
		$this->assertFalse( $this->tracking_settings->validate_html_comments( $html ) );
		$html = '<!-- begin comment--><script></script><!-- end invalid tag ->';
		$this->assertFalse( $this->tracking_settings->validate_html_comments( $html ) );
		$html = '<!-- begin comment--><script></script><!-- valid end --><!-- end invalid tag ->';
		$this->assertFalse( $this->tracking_settings->validate_html_comments( $html ) );
	}

}
