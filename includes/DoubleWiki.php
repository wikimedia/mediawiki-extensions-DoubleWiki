<?php

/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\DoubleWiki;

use Config;
use Language;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Html\Html;
use MediaWiki\Html\HtmlHelper;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Languages\LanguageNameUtils;
use OutputPage;
use Skin;
use Title;
use WANObjectCache;
use Wikimedia\RemexHtml\Serializer\SerializerNode;

class DoubleWiki implements OutputPageBeforeHTMLHook, BeforePageDisplayHook {

	private Config $mainConfig;
	private Language $contentLanguage;
	private LanguageFactory $languageFactory;
	private LanguageNameUtils $languageNameUtils;
	private HttpRequestFactory $httpRequestFactory;
	private WANObjectCache $cache;

	/** Constructor. */
	public function __construct(
		Config $mainConfig,
		Language $contentLanguage,
		LanguageFactory $languageFactory,
		LanguageNameUtils $languageNameUtils,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $cache
	) {
		$this->mainConfig = $mainConfig;
		$this->contentLanguage = $contentLanguage;
		$this->languageFactory = $languageFactory;
		$this->languageNameUtils = $languageNameUtils;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
	}

	/**
	 * OutputPageBeforeHTML hook handler. Transform $text into
	 * a bilingual version if `match` query parameter is provided.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/OutputPageBeforeHTML
	 *
	 * @param OutputPage $out OutputPage object
	 * @param string &$text HTML to mangle
	 */
	public function onOutputPageBeforeHTML( $out, &$text ): bool {
		$matchCode = $out->getRequest()->getText( 'match' );
		if ( $matchCode === '' ) {
			return true;
		}

		$fname = __METHOD__;

		foreach ( $out->getLanguageLinks() as $iwLinkText ) {
			$iwt = Title::newFromText( $iwLinkText );
			if ( !$iwt || $iwt->getInterwiki() !== $matchCode ) {
				continue;
			}

			$newText = $this->cache->getWithSetCallback(
				$this->cache->makeKey(
					'doublewiki-bilingual-pagetext',
					$out->getLanguage()->getCode(),
					$iwt->getPrefixedDbKey()
				),
				$this->mainConfig->get( 'DoubleWikiCacheTime' ),
				// @TODO: maybe integrate with WikiPage::purgeInterwikiCheckKey() somehow?
				function ( $oldValue ) use ( $iwt, $out, $matchCode, $text, $fname ) {
					$foreignUrl = $iwt->getCanonicalURL();
					$currentUrl = $out->getTitle()->getLocalURL();

					// TODO: Consider getting Last-Modified header and use $cache->daptiveTTL()
					$translation = $this->httpRequestFactory
						->get( wfAppendQuery( $foreignUrl, [ 'action' => 'render' ] ), [], $fname );

					if ( $translation === null ) {
						// not cached
						return false;
					}

					list( $text, $translation ) = $this->getMangledTextAndTranslation(
						$text,
						$translation,
						$matchCode
					);

					return $this->matchColumns(
						$text,
						$currentUrl,
						$this->contentLanguage,
						$translation,
						$foreignUrl,
						$this->languageFactory->getLanguage( $matchCode )
					);
				}
			);

			if ( $newText !== false ) {
				$text = $newText;
				$out->addModuleStyles( 'ext.doubleWiki' );
			}

			break;
		}

		return true;
	}

	/**
	 * @return string[] (new text, new translation)
	 */
	private function getMangledTextAndTranslation( string $text, string $translation, string $matchLangCode ): array {
		// add prefixes to internal links, in order to prevent duplicates
		$translation = HtmlHelper::modifyElements(
			$translation,
			static function ( SerializerNode $n ): bool {
				return $n->name === 'a' && isset( $n->attrs['href'] ) && str_starts_with( $n->attrs['href'], '#' );
			},
			static function ( SerializerNode $n ): SerializerNode {
				$n->attrs['href'] = '#l_' . substr( $n->attrs['href'], 1 );
				return $n;
			}
		);
		$translation = HtmlHelper::modifyElements(
			$translation,
			static function ( SerializerNode $n ): bool {
				return $n->name === 'li' && isset( $n->attrs['id'] );
			},
			static function ( SerializerNode $n ): SerializerNode {
				$n->attrs['id'] = 'l_' . $n->attrs['id'];
				return $n;
			}
		);

		$text = HtmlHelper::modifyElements(
			$text,
			static function ( SerializerNode $n ): bool {
				return $n->name === 'a' && isset( $n->attrs['href'] ) && str_starts_with( $n->attrs['href'], '#' );
			},
			static function ( SerializerNode $n ): SerializerNode {
				$n->attrs['href'] = '#r_' . substr( $n->attrs['href'], 1 );
				return $n;
			}
		);
		$text = HtmlHelper::modifyElements(
			$text,
			static function ( SerializerNode $n ): bool {
				return $n->name === 'li' && isset( $n->attrs['id'] );
			},
			static function ( SerializerNode $n ): SerializerNode {
				$n->attrs['id'] = 'r_' . $n->attrs['id'];
				return $n;
			}
		);

		// add ?match= to local links of the local wiki
		$text = HtmlHelper::modifyElements(
			$text,
			static function ( SerializerNode $n ): bool {
				return $n->name === 'a' && isset( $n->attrs['href'] )
					&& str_starts_with( $n->attrs['href'], '/' )
					&& !str_contains( $n->attrs['href'], '?' );
			},
			static function ( SerializerNode $n ) use ( $matchLangCode ): SerializerNode {
				$n->attrs['href'] = wfAppendQuery( $n->attrs['href'], [ 'match' => $matchLangCode ] );
				return $n;
			}
		);

		return [ $text, $translation ];
	}

	/**
	 * Format the text as a two-column table
	 */
	private function matchColumns(
		string $left_text, string $left_url, Language $left_lang,
		string $right_text, string $right_url, Language $right_lang
	): string {
		$left_langcode = htmlspecialchars( $left_lang->getHtmlCode() );
		$left_langdir = $left_lang->getDir();
		$right_langcode = htmlspecialchars( $right_lang->getHtmlCode() );
		$right_langdir = $right_lang->getDir();
		$left_title = $this->languageNameUtils->getLanguageName( $left_lang->getCode() );
		$right_title = $this->languageNameUtils->getLanguageName( $right_lang->getCode() );

		return Html::rawElement( 'table', [ 'id' => 'doubleWikiTable' ],
			Html::rawElement( 'thead', [],
				Html::rawElement( 'tr', [],
					Html::rawElement( 'td', [ 'lang' => $left_langcode ],
						Html::element( 'a', [ 'href' => $left_url ],
							$left_title
						)
					) .
					Html::rawElement( 'td', [ 'lang' => $right_langcode ],
						Html::element( 'a', [ 'href' => $right_url, 'class' => 'extiw' ],
							$right_title
						)
					)
				)
			) .
			Html::rawElement( 'tr', [],
				// phpcs:ignore Generic.Files.LineLength.TooLong
				Html::rawElement( 'td', [ 'lang' => $left_langcode, 'dir' => $left_langdir, 'class' => "mw-content-$left_langdir" ],
					Html::rawElement( 'div', [],
						$left_text
					)
				) .
				// phpcs:ignore Generic.Files.LineLength.TooLong
				Html::rawElement( 'td', [ 'lang' => $right_langcode, 'dir' => $right_langdir, 'class' => "mw-content-$right_langdir" ],
					Html::rawElement( 'div', [],
						$right_text
					)
				)
			)
		);
	}

	/**
	 * BeforePageDisplay hook handler
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 *
	 * @param OutputPage $out OutputPage object
	 * @param Skin $skin The skin in use
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getRequest()->getText( 'match' ) !== '' ) {
			$out->setRobotPolicy( 'noindex,nofollow' );
		}
	}
}
