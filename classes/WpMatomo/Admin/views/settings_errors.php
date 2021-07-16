<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */
/** @var string[] $errors */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="updated error">
	<?php foreach ( $errors as $error ) : ?>
	<p><?php echo esc_html( $error ); ?></p>
	<?php endforeach; ?>
</div>
