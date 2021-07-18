<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 * Code Based on
 * @author Andr&eacute; Br&auml;kling
 * https://github.com/braekling/WP-Matomo
 *
 */

use WpMatomo\Admin\TrackingSettings;
use WpMatomo\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var \WpMatomo\Settings $settings */
/** @var bool $was_updated */
/** @var array $matomo_default_tracking_code */
/** @var array $containers */
/** @var array $track_modes */
/** @var array $matomo_currencies */
/** @var array $cookie_consent_modes */

$matomo_form  = new \WpMatomo\Admin\TrackingSettings\Forms( $settings );
$matomo_paths = new Paths();
?>

<?php
if ( $was_updated ) {
	include 'update_notice_clear_cache.php';
}
?>
<form method="post">
	<?php wp_nonce_field( TrackingSettings::NONCE_NAME ); ?>
	<p>
        <?php esc_html_e( 'Here you can optionally configure the tracking to your liking if you want (you don\'t have to configure it).', 'matomo' );?>
        <?php esc_html_e( 'The configured tracking code will be embedded into your website automatically and you won\'t need to do anything unless you disabled the tracking.', 'matomo' );?>
        <?php esc_html_e( 'If you are seeing a tracking code below, you don\'t have to embed this tracking code into your site. The plugin does this automatically for you.', 'matomo' );?>
    </p>
	<table class="matomo-tracking-form widefat">
		<tbody>

		<?php
		// Tracking Configuration
		$matomo_is_not_tracking = $settings->get_global_option( 'track_mode' ) === TrackingSettings::TRACK_MODE_DISABLED;

		$matomo_is_not_generated_tracking     = $matomo_is_not_tracking || $settings->get_global_option( 'track_mode' ) === TrackingSettings::TRACK_MODE_MANUALLY;
		$matomo_full_generated_tracking_group = 'matomo-track-option matomo-track-option-default  ';

		$matomo_description = sprintf( '%s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s', esc_html__( 'You can choose between four tracking code modes:', 'matomo' ), esc_html__( 'Disabled', 'matomo' ), esc_html__( 'matomo will not add the tracking code. Use this, if you want to add the tracking code to your template files or you use another plugin to add the tracking code.', 'matomo' ), esc_html__( 'Default tracking', 'matomo' ), esc_html__( 'matomo will use Matomo\'s standard tracking code.', 'matomo' ) . ' ' .esc_html__('This mode is recommended for most use cases.', 'matomo'), esc_html__( 'Enter manually', 'matomo' ), esc_html__( 'Enter your own tracking code manually. You can choose one of the prior options, pre-configure your tracking code and switch to manually editing at last.', 'matomo' ) . ( $settings->is_network_enabled() ? ' ' . esc_html__( 'Use the placeholder {ID} to add the Matomo site ID.', 'matomo' ) : '' ), esc_html__( 'Tag Manager', 'matomo' ), esc_html__( 'If you have created containers in the Tag Manager, you can select one of them and it will embed the code for the container automatically.', 'matomo' ) );
		$matomo_form->show_select( 'track_mode', esc_html__( 'Add tracking code', 'matomo' ), $track_modes, $matomo_description, 'jQuery(\'tr.matomo-track-option\').addClass(\'hidden\'); jQuery(\'tr.matomo-track-option-\' + jQuery(\'#track_mode\').val()).removeClass(\'hidden\'); jQuery(\'#tracking_code, #noscript_code\').prop(\'readonly\', jQuery(\'#track_mode\').val() != \'manually\');' );

        $matomo_manually_network = '';
        if ( $settings->is_network_enabled() ) {
            $matomo_manually_network = ' ' . sprintf( esc_html__( 'You can use these variables: %1$s. %2$sLearn more%3$s', 'matomo' ), '{MATOMO_IDSITE}, {MATOMO_API_ENDPOINT}, {MATOMO_JS_ENDPOINT}', '<a href="https://matomo.org/faq/wordpress/how-can-i-configure-the-tracking-code-manually-when-i-have-wordpress-network-enabled-in-multisite-mode/" target="_blank" rel="noreferrer noopener">', '</a>' );
        }

		if ( ! empty( $containers ) ) {
			echo '<tr class="matomo-track-option matomo-track-option-tagmanager ' . ( $matomo_is_not_tracking ? ' hidden' : '' ) . '">';
			echo '<th scope="row"><label for="tagmanger_container_ids">' . esc_html__( 'Add these Tag Manager containers', 'matomo' ) . '</label>:</th><td>';
			$selected_container_ids = $settings->get_global_option( 'tagmanger_container_ids' );
			foreach ( $containers as $container_id => $container_name ) {
				echo '<input type="checkbox" ' . ( isset( $selected_container_ids [ $container_id ] ) && $selected_container_ids [ $container_id ] ? 'checked="checked" ' : '' ) . 'value="1" name="matomo[tagmanger_container_ids][' . $container_id . ']" /> ID:' . esc_html( $container_id ) . ' Name: ' . esc_html( $container_name ) . ' &nbsp; <br />';
			}
			echo '<br /><br /><a href="' . menu_page_url( \WpMatomo\Admin\Menu::SLUG_TAGMANAGER, false ) . '" rel="noreferrer noopener" target="_blank">Edit containers <span class="dashicons-before dashicons-external"></span></a>';
			echo '<br /><span class="dashicons dashicons-info-outline"></span> For Matomo to track you will need to add a Matomo Tag to the container. It otherwise won\'t track automatically.';
			echo '</td></tr>';
		}

		$matomo_form->show_textarea( 'tracking_code', esc_html__( 'Tracking code', 'matomo' ), 15, 'This is a preview of your current tracking code based on your configuration below. You don\'t need to do anything with it and this is purely for your information. If you choose to enter your tracking code manually, you can change it here. The tracking code is a piece of code that will be automatically embedded into your site and it is repsonsible for tracking your visitors. Have a look at the system report to get a list of all available JS tracker and tracking API endpoints. You don\'t need to embed this tracking code into your website, our plugin does this automatically.' . $matomo_manually_network, $matomo_is_not_tracking, 'matomo-track-option matomo-track-option-default matomo-track-option-tagmanager  matomo-track-option-manually', ! $settings->is_network_enabled(), '', ( $settings->get_global_option( 'track_mode' ) !== 'manually' ), false );


		$matomo_form->show_select( \WpMatomo\Settings::SITE_CURRENCY, esc_html__( 'Currency', 'matomo' ), $matomo_currencies, esc_html__('Choose the currency which will be used in reports. The currency will be used if you have an ecommerce store or if you are using the Matomo goals feature and assign a monetary value to a goal.', 'matomo'), '' );

		$matomo_form->show_headline(esc_html__('Customise tracking (optional)', 'matomo'), 'matomo-track-option matomo-track-option-default matomo-track-option-manually matomo-track-option-tagmanager');

		$matomo_form->show_checkbox( 'disable_cookies', esc_html__( 'Disable cookies', 'matomo' ), esc_html__( 'Disable all tracking cookies for a visitor.', 'matomo' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'track_ecommerce', esc_html__( 'Enable ecommerce', 'matomo' ), esc_html__( 'Matom can track Ecommerce orders, abandoned carts and product views for WooCommerce, Easy Digital Analytics, MemberPress, and more. Disabling this feature will also remove Ecommerce reports from the Matomo UI.', 'matomo' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager' );

		$matomo_form->show_checkbox( 'track_search', esc_html__( 'Track search', 'matomo' ), esc_html__( 'Use Matomo\'s advanced Site Search Analytics feature.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://matomo.org/docs/site-search/#track-site-search-using-the-tracking-api-advanced-users-only" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager' );

		$matomo_form->show_checkbox( 'track_404', esc_html__( 'Track 404', 'matomo' ), esc_html__( 'Matomo can automatically add a 404-category to track 404-page-visits.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo FAQ%2$s.', 'matomo' ), '<a href="https://matomo.org/faq/how-to/faq_60/" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually' );

		$matomo_form->show_checkbox( 'track_jserrors', esc_html__( 'Track JS errors', 'matomo' ), esc_html__( 'Enable to track JavaScript errors that occur on your website as Matomo events.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo FAQ%2$s.', 'matomo' ), '<a href="https://matomo.org/faq/how-to/how-do-i-enable-basic-javascript-error-tracking-and-reporting-in-matomo-browser-console-error-messages/" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		echo '<tr class="' . $matomo_full_generated_tracking_group . ' matomo-track-option-manually' . ( $matomo_is_not_tracking ? ' hidden' : '' ) . '">';
		echo '<th scope="row"><label for="add_post_annotations">' . esc_html__( 'Add annotation on new post of type', 'matomo' ) . '</label>:</th><td>';
		$matomo_filter = $settings->get_global_option( 'add_post_annotations' );
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			echo '<input type="checkbox" ' . ( isset( $matomo_filter [ $post_type->name ] ) && $matomo_filter [ $post_type->name ] ? 'checked="checked" ' : '' ) . 'value="1" name="matomo[add_post_annotations][' . $post_type->name . ']" /> ' . $post_type->label . ' &nbsp; ';
		}
		echo '<span class="dashicons dashicons-editor-help" style="cursor: pointer;" onclick="jQuery(\'#add_post_annotations-desc\').toggleClass(\'hidden\');"></span> <p class="description hidden" id="add_post_annotations-desc">' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://matomo.org/docs/annotations/" rel="noreferrer noopener" target="_BLANK">', '</a>' ) . '</p></td></tr>';

		$matomo_form->show_select(
			'track_content',
			__( 'Enable content tracking', 'matomo' ),
			array(
				'disabled' => esc_html__( 'Disabled', 'matomo' ),
				'all'      => esc_html__( 'Track all content blocks', 'matomo' ),
				'visible'  => esc_html__( 'Track only visible content blocks', 'matomo' ),
			),
			__( 'Content tracking allows you to track interaction with the content of a web page or application.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/guides/content-tracking" rel="noreferrer noopener" target="_BLANK">', '</a>' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group
		);

		$matomo_form->show_input( 'add_download_extensions', esc_html__( 'Add new file types for download tracking', 'matomo' ), esc_html__( 'Add file extensions for download tracking, divided by a vertical bar (&#124;).', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-javascript-guide#tracking-file-downloads" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'limit_cookies', esc_html__( 'Limit cookie lifetime', 'matomo' ), esc_html__( 'You can limit the cookie lifetime to avoid tracking your users over a longer period as necessary.', 'matomo' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group, true, 'jQuery(\'tr.matomo-cookielifetime-option\').toggleClass(\'matomo-hidden\');' );

		$matomo_form->show_input( 'limit_cookies_visitor', esc_html__( 'Visitor timeout (seconds)', 'matomo' ), false, $matomo_is_not_generated_tracking || ! $settings->get_global_option( 'limit_cookies' ), $matomo_full_generated_tracking_group . ' matomo-cookielifetime-option' . ( $settings->get_global_option( 'limit_cookies' ) ? '' : ' matomo-hidden' ) );

		$matomo_form->show_input( 'limit_cookies_session', esc_html__( 'Session timeout (seconds)', 'matomo' ), false, $matomo_is_not_generated_tracking || ! $settings->get_global_option( 'limit_cookies' ), $matomo_full_generated_tracking_group . ' matomo-cookielifetime-option' . ( $settings->get_global_option( 'limit_cookies' ) ? '' : ' matomo-hidden' ) );

		$matomo_form->show_input( 'limit_cookies_referral', esc_html__( 'Referral timeout (seconds)', 'matomo' ), false, $matomo_is_not_generated_tracking || ! $settings->get_global_option( 'limit_cookies' ), $matomo_full_generated_tracking_group . ' matomo-cookielifetime-option' . ( $settings->get_global_option( 'limit_cookies' ) ? '' : ' matomo-hidden' ) );

		$matomo_form->show_checkbox( 'track_admin', esc_html__( 'Track admin pages', 'matomo' ), esc_html__( 'Enable to track users on admin pages (remember to configure the tracking filter appropriately).', 'matomo' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager' );

		$matomo_form->show_checkbox( 'track_across', esc_html__( 'Track subdomains in the same website', 'matomo' ), esc_html__( 'Adds *.-prefix to cookie domain.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-javascript-guide#tracking-subdomains-in-the-same-website" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'track_across_alias', esc_html__( 'Do not count subdomains as outlink', 'matomo' ), esc_html__( 'Adds *.-prefix to tracked domain.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-javascript-guide#outlink-tracking-exclusions" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'track_crossdomain_linking', esc_html__( 'Enable cross domain linking', 'matomo' ), esc_html__( 'When enabled, it will make sure to use the same visitor ID for the same visitor across several domains. This works only when this feature is enabled because the visitor ID is stored in a cookie and cannot be read on the other domain by default. When this feature is enabled, it will append a URL parameter "pk_vid" that contains the visitor ID when a user clicks on a URL that belongs to one of your domains. For this feature to work, you also have to configure which domains should be treated as local in your Matomo website settings.', 'matomo' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'force_post', esc_html__( 'Force POST requests', 'matomo' ), esc_html__( 'When enabled, Matomo will always use POST requests. This can be helpful should you experience for example HTTP 414 URI too long errors in your tracking code.', 'matomo' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_checkbox( 'track_feed', esc_html__( 'Track RSS feeds', 'matomo' ), esc_html__( 'Enable to track posts in feeds via tracking pixel.', 'matomo' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager' );

		$matomo_form->show_checkbox( 'track_feed_addcampaign', esc_html__( 'Track RSS feed links as campaign', 'matomo' ), esc_html__( 'This will add Matomo campaign parameters to the RSS feed links.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://matomo.org/docs/tracking-campaigns/" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager', true, 'jQuery(\'tr.matomo-feed_campaign-option\').toggle(\'hidden\');' );

		$matomo_form->show_input( 'track_feed_campaign', esc_html__( 'RSS feed campaign', 'matomo' ), esc_html__( 'Keyword: post name.', 'matomo' ), $matomo_is_not_generated_tracking || ! $settings->get_global_option( 'track_feed_addcampaign' ), $matomo_full_generated_tracking_group . ' matomo-feed_campaign-option matomo-track-option-tagmanager' );

		$matomo_form->show_input( 'track_heartbeat', esc_html__( 'Enable heartbeat timer', 'matomo' ), __( 'Enable a heartbeat timer to get more accurate visit lengths by sending periodical HTTP ping requests as long as the site is opened. Enter the time between the pings in seconds (Matomo default: 15) to enable or 0 to disable this feature. <strong>Note:</strong> This will cause a lot of additional HTTP requests on your site.', 'matomo' ), $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_select(
			'track_user_id',
			__( 'User ID Tracking', 'matomo' ),
			array(
				'disabled'    => esc_html__( 'Disabled', 'matomo' ),
				'uid'         => esc_html__( 'WP User ID', 'matomo' ),
				'email'       => esc_html__( 'Email Address', 'matomo' ),
				'username'    => esc_html__( 'Username', 'matomo' ),
				'displayname' => esc_html__( 'Display Name (Not Recommended!)', 'matomo' ),
			),
			__( 'When a user is logged in to WordPress, track their &quot;User ID&quot;. You can select which field from the User\'s profile is tracked as the &quot;User ID&quot;. When enabled, Tracking based on Email Address is recommended.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group . ' matomo-track-option-tagmanager'
		);

		$matomo_form->show_checkbox( 'track_datacfasync', esc_html__( 'Add data-cfasync=false', 'matomo' ), esc_html__( 'Adds data-cfasync=false to the script tag, e.g., to ask Rocket Loader to ignore the script.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sCloudFlare Knowledge Base%2$s.', 'matomo' ), '<a href="https://support.cloudflare.com/hc/en-us/articles/200169436-How-can-I-have-Rocket-Loader-ignore-my-script-s-in-Automatic-Mode-" rel="noreferrer noopener" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group . '  matomo-track-option-tagmanager' );

		$matomo_submit_button = '<tr><td colspan="2"><p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . esc_attr__( 'Save Changes', 'matomo' ) . '" /></p></td></tr>';

		$matomo_form->show_input( 'set_download_extensions', esc_html__( 'Define all file types for download tracking', 'matomo' ), esc_html__( 'Replace Matomo\'s default file extensions for download tracking, divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-javascript-guide#file-extensions-for-tracking-downloads" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_input( 'set_download_classes', esc_html__( 'Set classes to be treated as downloads', 'matomo' ), esc_html__( 'Set classes to be treated as downloads (in addition to piwik_download), divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo JavaScript Tracking Client reference%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/api-reference/tracking-javascript" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_input( 'set_link_classes', esc_html__( 'Set classes to be treated as outlinks', 'matomo' ), esc_html__( 'Set classes to be treated as outlinks (in addition to piwik_link), divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'matomo' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo JavaScript Tracking Client reference%2$s.', 'matomo' ), '<a href="https://developer.matomo.org/api-reference/tracking-javascript" target="_BLANK">', '</a>' ), $matomo_is_not_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_textarea( 'noscript_code', esc_html__( 'Noscript code', 'matomo' ), 2, 'This is a preview of your &lt;noscript&gt; code which is part of your tracking code. Will only show if the noscript feature is enabled.', $matomo_is_not_tracking, 'matomo-track-option matomo-track-option-default  matomo-track-option-manually', true, '', ( $settings->get_global_option( 'track_mode' ) !== 'manually' ), false );

		$matomo_form->show_checkbox( 'track_noscript', __( 'Add &lt;noscript&gt;', 'matomo' ), __( 'Adds the &lt;noscript&gt; code to your footer.', 'matomo' ) . ' This can be useful if you have a lot of visitors that have JavaScript disabled.', $matomo_is_not_tracking, 'matomo-track-option matomo-track-option-default  matomo-track-option-manually' );

		$matomo_form->show_select(
			'force_protocol',
			__( 'Force Matomo to use a specific protocol', 'matomo' ),
			array(
				'disabled' => esc_html__( 'Disabled (default)', 'matomo' ),
				'https'    => esc_html__( 'https (SSL)', 'matomo' ),
			),
			__( 'Choose if you want to explicitly want to force Matomo to use HTTP or HTTPS. Does not work with a CDN URL.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group . ' matomo-track-option-tagmanager'
		);
		$matomo_form->show_select(
			'track_codeposition',
			__( 'JavaScript code position', 'matomo' ),
			array(
				'footer' => esc_html__( 'Footer', 'matomo' ),
				'header' => esc_html__( 'Header', 'matomo' ),
			),
			__( 'Choose whether the JavaScript code is added to the footer or the header.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			'matomo-track-option matomo-track-option-default  matomo-track-option-tagmanager matomo-track-option-manually'
		);
		$matomo_form->show_select(
			'track_api_endpoint',
			__( 'Endpoint for HTTP Tracking API', 'matomo' ),
			array(
				'default' => esc_html__( 'Default', 'matomo' ),
				'restapi' => esc_html__( 'Through WordPress Rest API', 'matomo' ),
			),
			__( 'By default the HTTP Tracking API points to your Matomo plugin directory "' . esc_html( $matomo_paths->get_tracker_api_url_in_matomo_dir() ) . '". You can choose to use the WP Rest API (' . esc_html( $matomo_paths->get_tracker_api_rest_api_endpoint() ) . ') instead for example to hide matomo.php or if the other URL doesn\'t work for you. Note: If the tracking mode "Tag Manager" is selected, then this URL currently only applies to the feed tracking.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group . ' matomo-track-option-manually matomo-track-option-tagmanager'
		);

		$matomo_form->show_select(
			'track_js_endpoint',
			__( 'Endpoint for JavaScript tracker', 'matomo' ),
			array(
				'default' => esc_html__( 'Default', 'matomo' ),
				'restapi' => esc_html__( 'Through WordPress Rest API (slower)', 'matomo' ),
				'plugin' => esc_html__( 'Plugin (an alternative JS file if the default is blocked by the webserver)', 'matomo' ),
			),
			__( 'By default the JS tracking code will be loaded from "' . esc_html( $matomo_paths->get_js_tracker_url_in_matomo_dir() ) . '". You can choose to serve the JS file through the WP Rest API (' . esc_html( $matomo_paths->get_js_tracker_rest_api_endpoint() ) . ') for example to hide matomo.js. Please note that this means every request to the JavaScript file will launch WordPress PHP and therefore will be slower compared to your webserver serving the JS file directly. Using the "Plugin" method will cause issues with our paid Heatmap and Session Recording, Form Analytics, and Media Analyics plugin.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group
		);

		$matomo_form->show_select( 'cookie_consent', esc_html__( 'Consent mode', 'matomo' ), $cookie_consent_modes, sprintf(esc_html__( 'Activates a specific Matomo consent mode. Only configure a consent mode if you are implementing a consent screen yourself. This requires a custom consent implementation. For more information please read this %1$sFAQ%2$s. By default no consent mode is applied.', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-consent" rel="noreferrer noopener" target="_blank">', '</a>'), '', $matomo_is_not_generated_tracking, $matomo_full_generated_tracking_group );

		$matomo_form->show_headline(esc_html__('For Developers', 'matomo'), 'matomo-track-option matomo-track-option-default matomo-track-option-disabled matomo-track-option-manually matomo-track-option-tagmanager');

		$matomo_form->show_select(
			'tracker_debug',
			__( 'Tracker Debug Mode', 'matomo' ),
			array(
				'disabled' => esc_html__( 'Disabled (recommended)', 'matomo' ),
				'always'    => esc_html__( 'Always enabled', 'matomo' ),
				'on_demand'    => esc_html__( 'Enabled on demand', 'matomo' ),
			),
			__( 'For security and privacy reasons you should only enable this setting for as short time of a time as possible.', 'matomo' ),
			'',
			$matomo_is_not_tracking,
			$matomo_full_generated_tracking_group . ' matomo-track-option-disabled matomo-track-option-manually matomo-track-option-tagmanager'
		);

		echo $matomo_submit_button;
		?>

		</tbody>
	</table>
</form>

<?php if ( $matomo_is_not_tracking && ! $settings->is_network_enabled() ) { // Can't show it for multisite as idsite and url is always different. ?>
<div id="matomo_default_tracking_code">
	<h2><?php esc_html_e( 'JavaScript tracking code', 'matomo' ); ?></h2>
	<p>
		<?php echo sprintf( esc_html__( 'Wanting to embed the tracking code manually into your site or using a different plugin? No problem! Simply copy/paste below tracking code. Want to adjust it? %1$sCheck out our developer documentation.%2$s', 'matomo' ), '<a href="https://developer.matomo.org/guides/tracking-javascript-guide" target="_blank" rel="noreferrer noopener">', '</a>' ); ?>
	</p>
	<?php echo '<pre><textarea>' . esc_html( implode( ";\n", explode( ';', $matomo_default_tracking_code['script'] ) ) ) . '</textarea></pre>'; ?>
	<h3><?php esc_html_e( 'NoScript tracking code', 'matomo' ); ?></h3>
	   <?php echo '<pre><textarea class="no_script">' . esc_html( $matomo_default_tracking_code['noscript'] ) . '</textarea></pre>'; ?>
</div>
<?php } ?>
