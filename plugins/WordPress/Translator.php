<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\WordPress;

use Piwik\AuthResult;
use Piwik\Plugins\UsersManager\Model;
use WpMatomo\Capabilities;
use WpMatomo\Settings;
use WpMatomo\Site;
use WpMatomo\User;

if (!defined( 'ABSPATH')) {
    exit; // if accessed directly
}

class Translator extends \Piwik\Translation\Translator
{
	public function translate($translationId, $args = array(), $language = null)
	{
		$args = is_array($args) ? $args : array($args);

		$translation = __($translationId, 'matomo');

		if ($translation === $translationId) {
			// for third party plugins
			return parent::translate($translationId, $args, $language);
		}

		if (count($args) == 0) {
			return str_replace('%%', '%', $translation);
		}

		return vsprintf($translation, $args);
	}
}
