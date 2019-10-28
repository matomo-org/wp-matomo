<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\WordPress\Commands;

use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Filesystem;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\LanguagesManager;
use Piwik\Translation\Translator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 */
class GenerateLanguageFiles extends ConsoleCommand
{
	protected function configure()
	{
		$this->setName('wordpress:generate-language-files');
		$this->setDescription('Generate language files');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$pluginRoot = plugin_dir_path(MATOMO_ANALYTICS_FILE);
		require_once $pluginRoot . 'vendor/autoload.php';

		$base = $this->getCoreEnglishBaseTranslations();

		foreach (LanguagesManager\API::getInstance()->getAvailableLanguages() as $language) {
			/** @var Translator $translator */
			$translator = StaticContainer::get(Translator::class);
			$translator->setCurrentLanguage($language);

			$translationsGettext = new \Gettext\Translations();
			$translationsGettext->setLanguage($language);

			// todo need to get translations for the plugin file in there somehow... fetch from transifex?
			if ($language === 'en') {
				foreach (Filesystem::globr($pluginRoot .'classes', '*.php') as $file) {
					\Gettext\Extractors\PhpCode::fromFile($file, $translationsGettext);
				}
			}

			$translated = $translator->getAllTranslations();

			foreach ($base as $group => $translations) {
				foreach ($translations as $key => $translation) {
					$insertedTranslation = $translationsGettext->insert('', $group . '_' . $key);

					if (isset($translated[$group][$key])) {
						$insertedTranslation->setTranslation($translated[$group][$key]);
					} else {
						// fallback to english
						$insertedTranslation->setTranslation($translation);
					}
				}
			}

			$translationsGettext->setDomain('matomo');
			foreach ($this->getMatchingLocales($language) as $locale) {
				$translationsGettext->toMoFile($pluginRoot . 'languages/matomo-'.$locale.'.mo');
				$translationsGettext->toPoFile($pluginRoot . 'languages/matomo-'.$locale.'.po');
			}
		}
	}

	private function getCoreEnglishBaseTranslations()
	{
		$translator = StaticContainer::get(Translator::class);
		$translator->setCurrentLanguage('en');

		$translationsGettext = new \Gettext\Translations();
		$translationsGettext->setLanguage('en');

		return $translator->getAllTranslations();
	}

	private function getMatchingLocales($language)
	{
		$matches = array();
		$locales = $this->getWordPressLocales();
		foreach ($locales as $locale) {
			if ($locale === $language || strpos($locale, $language . '_') === 0) {
				$matches[] = $locale;
			}
		}
		return $matches;
	}

	private function getWordPressLocales()
	{
		// from https://wpcentral.io/internationalization/
		return array('af',
					'ak',
					'sq',
					'arq',
					'am',
					'ar',
					'hy',
					'rup_MK',
					'frp',
					'as',
					'az',
					'az_TR',
					'bcc',
					'ba',
					'eu',
					'bel',
					'bn_BD',
					'bs_BA',
					'bre',
					'bg_BG',
					'ca',
					'bal',
					'ceb',
					'zh_CN',
					'zh_HK',
					'zh_TW',
					'co',
					'hr',
					'cs_CZ',
					'da_DK',
					'dv',
					'nl_NL',
					'nl_BE',
					'dzo',
					'art_xemoji',
					'en_US',
					'en_AU',
					'en_CA',
					'en_NZ',
					'en_ZA',
					'en_GB',
					'eo',
					'et',
					'fo',
					'fi',
					'fr_BE',
					'fr_CA',
					'fr_FR',
					'fy',
					'fur',
					'fuc',
					'gl_ES',
					'ka_GE',
					'de_DE',
					'de_CH',
					'el',
					'kal',
					'gn',
					'gu',
					'haw_US',
					'haz',
					'he_IL',
					'hi_IN',
					'hu_HU',
					'is_IS',
					'ido',
					'id_ID',
					'ga',
					'it_IT',
					'ja',
					'jv_ID',
					'kab',
					'kn',
					'kk',
					'km',
					'kin',
					'ky_KY',
					'ko_KR',
					'ckb',
					'lo',
					'lv',
					'li',
					'lin',
					'lt_LT',
					'lb_LU',
					'mk_MK',
					'mg_MG',
					'ms_MY',
					'ml_IN',
					'mri',
					'mr',
					'xmf',
					'mn',
					'me_ME',
					'ary',
					'my_MM',
					'ne_NP',
					'nb_NO',
					'nn_NO',
					'oci',
					'ory',
					'os',
					'ps',
					'fa_IR',
					'fa_AF',
					'pl_PL',
					'pt_BR',
					'pt_PT',
					'pa_IN',
					'rhg',
					'ro_RO',
					'roh',
					'ru_RU',
					'rue',
					'sah',
					'sa_IN',
					'srd',
					'gd',
					'sr_RS',
					'szl',
					'snd',
					'si_LK',
					'sk_SK',
					'sl_SI',
					'so_SO',
					'azb',
					'es_AR',
					'es_CL',
					'es_CO',
					'es_GT',
					'es_MX',
					'es_PE',
					'es_PR',
					'es_ES',
					'es_VE',
					'su_ID',
					'sw',
					'sv_SE',
					'gsw',
					'tl',
					'tah',
					'tg',
					'tzm',
					'ta_IN',
					'ta_LK',
					'tt_RU',
					'te',
					'th',
					'bo',
					'tir',
					'tr_TR',
					'tuk',
					'twd',
					'ug_CN',
					'uk',
					'ur',
					'uz_UZ',
					'vi',
					'wa',
					'cy',
					'yor');
	}

}
