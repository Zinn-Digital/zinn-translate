<?php
/**
 * Living alongside Yoast, Rank Math, All in One SEO and SEOPress rather than fighting them.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the site's SEO plugin, translates what it outputs, and stays out of its way.
 *
 * ⛔⛔ **THE COMMONEST WAY A TRANSLATION PLUGIN RUINS A SITE IS BY DUPLICATING SEO OUTPUT.**
 * Every plugin here already prints a canonical, an OpenGraph block and often an `hreflang`
 * set of its own. A translation plugin that prints its own as well gives the page two
 * canonicals — and a page with two canonicals has, to a crawler, none. The customer bought
 * translation to rank in more languages and instead stops ranking in the one they had.
 *
 * So the rule in this file is: **filter what they emit; emit nothing they already do.**
 * Every one of these plugins exposes filters for its title, description, canonical and
 * OpenGraph values, and those filters are how the translated text gets in. The only thing
 * this plugin prints itself is `hreflang`, and only when nothing else is printing it.
 *
 * ⛔ Detection is by CONSTANT or CLASS, never by an option row or an active-plugin list.
 * A plugin can be installed and inactive, network-active, renamed, or loaded as a must-use
 * plugin; the constant its own bootstrap defines is true in exactly the cases where its
 * filters exist, which is the question being asked.
 */
final class Zinn_Translate_SEO {

	/**
	 * Which SEO plugin is running, or an empty string for none.
	 *
	 * @var string|null
	 */
	private static ?string $detected = null;

	/**
	 * Register the compatibility filters for whichever plugin is present.
	 *
	 * @return void
	 */
	public function hooks(): void {
		$which = self::detect();

		if ( 'yoast' === $which ) {
			add_filter( 'wpseo_title', array( $this, 'meta_title' ), 20 );
			add_filter( 'wpseo_metadesc', array( $this, 'meta_description' ), 20 );
			add_filter( 'wpseo_opengraph_title', array( $this, 'og_title' ), 20 );
			add_filter( 'wpseo_opengraph_desc', array( $this, 'og_description' ), 20 );
			// ⭐ Twitter takes the OpenGraph text too, because Yoast's OWN fallback chain is
			// twitter → opengraph → SEO title. A post with an OpenGraph title and no Twitter
			// title arrives here holding the OpenGraph text, so swapping it for the
			// translated SEO title replaced one string with the translation of a different
			// one. (`_yoast_wpseo_twitter-title` is deliberately not collected: a fourth
			// title per post is a fourth string to buy in 57 languages, and on real installs
			// it is either unset or a copy of the OpenGraph one — §2.45.)
			add_filter( 'wpseo_twitter_title', array( $this, 'og_title' ), 20 );
			add_filter( 'wpseo_twitter_description', array( $this, 'og_description' ), 20 );
			add_filter( 'wpseo_canonical', array( $this, 'canonical' ), 20 );
			// ⛔ Yoast builds its own sitemap. Ours is registered under a different name and
			// its index is linked from robots.txt, so the two coexist rather than compete.
			add_filter( 'wpseo_schema_graph', array( $this, 'schema_graph' ), 20 );
		}

		if ( 'rankmath' === $which ) {
			add_filter( 'rank_math/frontend/title', array( $this, 'meta_title' ), 20 );
			add_filter( 'rank_math/frontend/description', array( $this, 'meta_description' ), 20 );
			add_filter( 'rank_math/opengraph/facebook/og_title', array( $this, 'og_title' ), 20 );
			add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'og_description' ), 20 );
			add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ), 20 );
		}

		if ( 'aioseo' === $which ) {
			add_filter( 'aioseo_title', array( $this, 'meta_title' ), 20 );
			add_filter( 'aioseo_description', array( $this, 'meta_description' ), 20 );
			add_filter( 'aioseo_canonical_url', array( $this, 'canonical' ), 20 );
			// ⛔⛔ AIOSEO DELIBERATELY GETS NO OPENGRAPH FILTER, and the reason is the same
			// one that makes `meta_keys()` empty for it: its per-post data lives in AIOSEO's
			// own table, so there is no `og_title` to collect and nothing to put back. A
			// filter here could only ever render the SEO title into the OpenGraph tag —
			// which is precisely the defect the three branches above were just fixed for, so
			// adding one would re-create it under a different plugin's name.
			// ⚠️ What an AIOSEO site therefore gets: its OpenGraph title and description stay
			// in the source language while the page itself is translated. That is a named
			// gap, not an oversight, and closing it means reading AIOSEO's table — a lane
			// that needs a real AIOSEO install to verify against, which this one did not
			// have. Nothing here guesses at a filter name we could not run.
		}

		if ( 'seopress' === $which ) {
			add_filter( 'seopress_titles_the_title', array( $this, 'meta_title' ), 20 );
			add_filter( 'seopress_titles_desc', array( $this, 'meta_description' ), 20 );
			add_filter( 'seopress_social_og_title', array( $this, 'og_title' ), 20 );
			add_filter( 'seopress_social_og_desc', array( $this, 'og_description' ), 20 );
			add_filter( 'seopress_canonical', array( $this, 'canonical' ), 20 );
		}

		if ( '' === $which ) {
			// ⛔ Only with NO SEO plugin do we print a title and description ourselves. With
			// one present, printing them is how a page ends up with two `<title>` tags.
			add_filter( 'document_title_parts', array( $this, 'document_title_parts' ), 20 );
			add_action( 'wp_head', array( $this, 'print_description' ), 5 );
		}

		// ⛔ Registered ONCE, outside the branches above, and deliberately so. Core's
		// `rel_canonical()` runs whatever else is installed, so this filter is needed in
		// every case — and registering it inside the no-SEO-plugin branch as well would
		// attach the same callback twice and translate an already-translated string.
		add_filter( 'get_canonical_url', array( $this, 'core_canonical' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 20, 2 );
	}

	/**
	 * Which SEO plugin is running.
	 *
	 * @return string One of `yoast`, `rankmath`, `aioseo`, `seopress`, or an empty string.
	 */
	public static function detect(): string {
		if ( null !== self::$detected ) {
			return self::$detected;
		}
		$which = '';
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) ) {
			$which = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$which = 'rankmath';
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$which = 'aioseo';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$which = 'seopress';
		}
		self::$detected = $which;
		return $which;
	}

	/**
	 * A human name for the detected plugin, for the settings screen.
	 *
	 * @return string The plugin's name, or an empty string when there is none.
	 */
	public static function detected_label(): string {
		$names = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
		);
		$which = self::detect();
		return $names[ $which ] ?? '';
	}

	/**
	 * Whether something other than us is already emitting `hreflang` tags.
	 *
	 * ⛔⛔ **Asked before we print ours, and the answer decides whether we print at all.**
	 * Two sets of `hreflang` annotations on one page is not twice as good: the sets
	 * disagree about which URL is which language, and a crawler that cannot reconcile them
	 * ignores both. WPML, Polylang and TranslatePress all emit their own, and a site that
	 * has one of those installed alongside this plugin is a migration in progress — the
	 * correct behaviour during one is to be quiet, not to compete.
	 *
	 * @return bool True when another plugin owns the hreflang set.
	 */
	public static function hreflang_taken(): bool {
		$taken = defined( 'ICL_SITEPRESS_VERSION' )        // WPML.
			|| defined( 'POLYLANG_VERSION' )               // Polylang.
			|| defined( 'TRP_PLUGIN_VERSION' )             // TranslatePress.
			|| defined( 'WEGLOT_VERSION' );                // Weglot.
		/**
		 * Filters whether another plugin already owns this page's hreflang tags.
		 *
		 * @param bool $taken True to suppress this plugin's hreflang output.
		 */
		return (bool) apply_filters( 'zinn_translate_hreflang_taken', $taken );
	}

	/**
	 * The meta keys each SEO plugin stores its per-post title and description under.
	 *
	 * ⭐ Returned as data so that ONE list serves both the collector, which reads the
	 * source text out of these keys, and the renderer, which puts the translation back.
	 * Two lists would drift, and the failure is silent: the collector translates a field
	 * the renderer never looks at, and the customer pays for words nobody sees.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	public static function meta_keys(): array {
		switch ( self::detect() ) {
			case 'yoast':
				return array(
					'meta_title'       => '_yoast_wpseo_title',
					'meta_description' => '_yoast_wpseo_metadesc',
					'og_title'         => '_yoast_wpseo_opengraph-title',
					'og_description'   => '_yoast_wpseo_opengraph-description',
				);
			case 'rankmath':
				return array(
					'meta_title'       => 'rank_math_title',
					'meta_description' => 'rank_math_description',
					'og_title'         => 'rank_math_facebook_title',
					'og_description'   => 'rank_math_facebook_description',
				);
			case 'aioseo':
				// ⛔ AIOSEO keeps its per-post data in its OWN table, not in post meta, so
				// there is nothing here to read. Its rendered values are still translated,
				// through the `aioseo_title` / `aioseo_description` filters above — which is
				// why those exist even though this list is empty.
				return array();
			case 'seopress':
				return array(
					'meta_title'       => '_seopress_titles_title',
					'meta_description' => '_seopress_titles_desc',
					'og_title'         => '_seopress_social_fb_title',
					'og_description'   => '_seopress_social_fb_desc',
				);
			default:
				return array();
		}
	}

	/**
	 * Translate a rendered meta title.
	 *
	 * @param string $title The title the SEO plugin built.
	 * @return string The translated title, or the original.
	 */
	public function meta_title( $title ): string {
		return $this->swap( (string) $title, 'meta_title' );
	}

	/**
	 * Translate a rendered meta description.
	 *
	 * @param string $description The description the SEO plugin built.
	 * @return string The translated description, or the original.
	 */
	public function meta_description( $description ): string {
		return $this->swap( (string) $description, 'meta_description' );
	}

	/**
	 * Translate a rendered OpenGraph title.
	 *
	 * ⛔⛔ **THIS CALLBACK EXISTS BECAUSE ITS ABSENCE WAS A LIVE CONTENT DEFECT, NOT MERELY A
	 * MISSING FEATURE.** Every OpenGraph filter on every provider used to be bound to
	 * `meta_title`, so a post whose author had written a *different* OpenGraph title — which
	 * is the entire reason that field exists — had its `og:title` replaced with the
	 * translated SEO title. The tag was present, it was in the right language, and it said
	 * something the author never wrote. An absence check cannot see that state: the only
	 * thing wrong with it is which of two translations it is.
	 *
	 * ⭐ The fallback to `meta_title` is deliberate and is the correct behaviour, not a
	 * leftover. Most posts have no separate OpenGraph title, so both the SEO plugin and this
	 * plugin fall back the same way — the plugin renders its SEO title into `og:title`, and
	 * we translate it as one. What changed is that the fallback now happens only when there
	 * is genuinely no OpenGraph translation to use.
	 *
	 * @param string $title The OpenGraph title the SEO plugin built.
	 * @return string The translated title, or the original.
	 */
	public function og_title( $title ): string {
		return $this->swap_first( (string) $title, 'og_title', 'meta_title' );
	}

	/**
	 * Translate a rendered OpenGraph description, on the same terms.
	 *
	 * @param string $description The OpenGraph description the SEO plugin built.
	 * @return string The translated description, or the original.
	 */
	public function og_description( $description ): string {
		return $this->swap_first( (string) $description, 'og_description', 'meta_description' );
	}

	/**
	 * Point a canonical URL at this request's own locale.
	 *
	 * ⛔⛔ **A translated page's canonical must be ITSELF, never the source page.** Pointing
	 * `/fr/a-propos/` at `/about/` tells a crawler the French page is a duplicate that
	 * should not be indexed — which removes every translated page from the index and makes
	 * the product actively harmful. `hreflang` is what expresses "these are the same page
	 * in different languages"; the canonical is not.
	 *
	 * @param string $canonical The canonical the SEO plugin built.
	 * @return string The canonical for this locale.
	 */
	public function canonical( $canonical ): string {
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale || '' === (string) $canonical ) {
			return (string) $canonical;
		}
		return Zinn_Translate_Router::localise_url( (string) $canonical, $locale );
	}

	/**
	 * Core's own canonical, for the same reason.
	 *
	 * @param string       $canonical The URL core built.
	 * @param WP_Post|null $post      The post it belongs to.
	 * @return string The canonical for this locale.
	 */
	public function core_canonical( $canonical, $post = null ): string {
		unset( $post );
		return $this->canonical( $canonical );
	}

	/**
	 * Translate `alt` text on rendered images.
	 *
	 * ⭐ Alt text is named explicitly in `CLAUDE.md` §2.19 and is the SEO surface most
	 * often left in English by translation plugins — it is invisible on screen, so nobody
	 * notices, and it is read by exactly the two audiences that matter most: screen-reader
	 * users and image search.
	 *
	 * @param array<string, string> $attr       The image attributes.
	 * @param WP_Post|null          $attachment The attachment post.
	 * @return array<string, string> The attributes, with `alt` translated where we have it.
	 */
	public function image_attributes( $attr, $attachment = null ): array {
		$attr = (array) $attr;
		if ( ! $attachment instanceof WP_Post || ! isset( $attr['alt'] ) ) {
			return $attr;
		}
		$translated = Zinn_Translate_Renderer::lookup( 'attachment:' . $attachment->ID, 'alt' );
		if ( null !== $translated ) {
			$attr['alt'] = $translated;
		}
		return $attr;
	}

	/**
	 * Translate the document title when no SEO plugin owns it.
	 *
	 * @param array<string, string> $parts The title parts core assembled.
	 * @return array<string, string> The parts, translated.
	 */
	public function document_title_parts( $parts ): array {
		$parts = (array) $parts;
		if ( isset( $parts['site'] ) ) {
			$site = Zinn_Translate_Renderer::lookup( 'site:options', 'blogname' );
			if ( null !== $site ) {
				$parts['site'] = $site;
			}
		}
		if ( isset( $parts['tagline'] ) ) {
			$tagline = Zinn_Translate_Renderer::lookup( 'site:options', 'blogdescription' );
			if ( null !== $tagline ) {
				$parts['tagline'] = $tagline;
			}
		}
		return $parts;
	}

	/**
	 * Print a translated meta description when no SEO plugin is doing it.
	 *
	 * @return void
	 */
	public function print_description(): void {
		if ( ! is_singular() ) {
			return;
		}
		$description = Zinn_Translate_Renderer::lookup( $this->current_ref(), 'meta_description' );
		if ( null === $description ) {
			return;
		}
		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( wp_strip_all_tags( $description ) )
		);
	}

	/**
	 * Translate the text nodes of a schema graph.
	 *
	 * ⛔ Only `name`, `headline` and `description` are touched. A schema graph is mostly
	 * URLs, identifiers and enumerated types, and translating any of those breaks the
	 * structured data outright — a `@type` in French is not a type. §2.19 asks for the
	 * schema *text* to be localised, which is these three keys.
	 *
	 * @param array<int, array<string, mixed>> $graph The graph Yoast assembled.
	 * @return array<int, array<string, mixed>> The graph, with its prose translated.
	 */
	public function schema_graph( $graph ): array {
		$graph = (array) $graph;
		foreach ( $graph as $index => $piece ) {
			if ( ! is_array( $piece ) ) {
				continue;
			}
			foreach ( array( 'name', 'headline', 'description' ) as $key ) {
				if ( ! isset( $piece[ $key ] ) || ! is_string( $piece[ $key ] ) ) {
					continue;
				}
				// ⛔⛔ MATCHED ON THE TEXT, NEVER ASSUMED TO BELONG TO THE CURRENT POST. The
				// first version looked every node's `name` up as the current page's title,
				// which is right for the `WebPage` node and wrong for every other one — and
				// the graph really does carry others. Measured on a live page: the `WebSite`
				// node, whose `name` is the SITE's name, came out as the page's title. It
				// looked correct in the node anybody would check first, which is why it
				// shipped past a reading of the code.
				$translation = Zinn_Translate_Renderer::lookup_source( $piece[ $key ] );
				if ( null !== $translation ) {
					$graph[ $index ][ $key ] = wp_strip_all_tags( $translation );
				}
			}
		}
		return $graph;
	}

	/**
	 * Swap one meta field of the current post for its translation.
	 *
	 * @param string $original The value the SEO plugin produced.
	 * @param string $field    Our field name.
	 * @return string The translation, or the original.
	 */
	private function swap( string $original, string $field ): string {
		$translated = Zinn_Translate_Renderer::lookup( $this->current_ref(), $field );
		return null === $translated ? $original : $translated;
	}

	/**
	 * Swap for the first of two fields we have a translation for.
	 *
	 * ⛔ The fallback is tried only when the preferred field is genuinely ABSENT — never
	 * when it is present and empty, because `lookup()` already collapses those two into
	 * null on purpose. Falling back on a real empty translation would put the SEO title
	 * into a tag the author had deliberately blanked.
	 *
	 * @param string $original The value the SEO plugin produced.
	 * @param string $field    The field that should answer.
	 * @param string $fallback The field to use when it does not.
	 * @return string The translation, or the original.
	 */
	private function swap_first( string $original, string $field, string $fallback ): string {
		$ref        = $this->current_ref();
		$translated = Zinn_Translate_Renderer::lookup( $ref, $field );
		if ( null === $translated ) {
			$translated = Zinn_Translate_Renderer::lookup( $ref, $fallback );
		}
		return null === $translated ? $original : $translated;
	}

	/**
	 * The object ref for whatever is being rendered right now.
	 *
	 * @return string An object ref, or an empty string off a singular view.
	 */
	private function current_ref(): string {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				return $post->post_type . ':' . $post->ID;
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return 'term:' . $term->term_id;
			}
		}
		return '';
	}
}
