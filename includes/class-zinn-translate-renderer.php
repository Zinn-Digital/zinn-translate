<?php
/**
 * Swapping the words — the render-time half.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters titles, content and excerpts into the request's locale, and emits hreflang.
 *
 * ⛔⛔ EVERY SWAP FALLS BACK TO THE CUSTOMER'S OWN WORDS, NEVER TO BLANK. A page whose body
 * is translated and whose title is not must still show the English title: a blank heading is
 * a broken page, and the customer is paying us to improve their site. This is the same
 * per-string rule the engine applies at its end, and it is stated in both places because a
 * fallback that exists on only one side of a network boundary is not a fallback.
 */
class Zinn_Translate_Renderer {

	/**
	 * The current request's bundle, fetched once and reused for every filter call.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $bundle = null;

	/**
	 * Register the render-time filters.
	 *
	 * ⛔ Only filters — no action that writes anything. This plugin never modifies the site.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'the_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'content' ) );
		add_filter( 'get_the_excerpt', array( $this, 'excerpt' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'hreflang' ) );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
	}

	/**
	 * The documents for this request's locale, fetched once per request.
	 *
	 * @return array<string, array<string, string>>|null
	 */
	private function bundle(): ?array {
		if ( null !== $this->bundle ) {
			return $this->bundle;
		}
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale ) {
			return null;
		}
		$this->bundle = ( new Zinn_Translate_Client() )->bundle( $locale );
		return $this->bundle;
	}

	/**
	 * One field of one post, translated, or null to leave it alone.
	 *
	 * @param int    $post_id The post to look up.
	 * @param string $field   Field name — `title`, `body`, `excerpt` or `slug`.
	 * @return string|null The translation, or null to leave the original alone.
	 */
	private function field( int $post_id, string $field ): ?string {
		$bundle = $this->bundle();
		if ( null === $bundle || 0 === $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return null;
		}
		// The key the engine built: `<post_type>:<id>`. `post_type` rather than a guess,
		// because a site's post 12 and page 12 are different documents and collapsing them
		// would show one page's words on another.
		$key = $post->post_type . ':' . $post_id;
		if ( ! isset( $bundle[ $key ][ $field ] ) ) {
			return null;
		}
		$value = $bundle[ $key ][ $field ];
		return '' === trim( $value ) ? null : $value;
	}

	/**
	 * Swap a post title for its translation.
	 *
	 * @param string   $title   The title WordPress is about to render.
	 * @param int|null $post_id The post it belongs to.
	 * @return string The translated title, or the original.
	 */
	public function title( $title, $post_id = null ): string {
		$translated = $this->field( (int) $post_id, 'title' );
		// ⛔ `esc_html` is NOT applied here: `the_title` receives raw text and WordPress
		// escapes at the output site. Escaping here would double-encode an apostrophe in
		// every translated French title on the site.
		return null === $translated ? (string) $title : $translated;
	}

	/**
	 * Swap a post body for its translation.
	 *
	 * @param string $content The body WordPress is about to render.
	 * @return string The translated body, or the original.
	 */
	public function content( $content ): string {
		$translated = $this->field( (int) get_the_ID(), 'body' );
		if ( null === $translated ) {
			return (string) $content;
		}
		// ⛔ `wp_kses_post`, not raw output. The text came from our API over TLS and is a
		// translation of the customer's own HTML, so it is not attacker-controlled in any
		// ordinary sense — but "the source is trusted" is exactly the reasoning that puts
		// unfiltered HTML on a page, and this runs on tens of thousands of sites we do not
		// operate. It permits everything a post body may contain and nothing else.
		return wp_kses_post( $translated );
	}

	/**
	 * Swap an excerpt for its translation.
	 *
	 * @param string       $excerpt The excerpt WordPress is about to render.
	 * @param WP_Post|null $post    The post it belongs to.
	 * @return string The translated excerpt, or the original.
	 */
	public function excerpt( $excerpt, $post = null ): string {
		$post_id    = $post instanceof WP_Post ? $post->ID : (int) get_the_ID();
		$translated = $this->field( $post_id, 'excerpt' );
		return null === $translated ? (string) $excerpt : $translated;
	}

	/**
	 * `<html lang="fr">` on a translated request.
	 *
	 * ⛔ Not cosmetic. Screen readers choose a voice from this attribute, and search engines
	 * read it when deciding which audience a page is for — a French page declaring `lang="en"`
	 * is read aloud in an English accent and may be served to the wrong searchers.
	 *
	 * @param string $output The attributes WordPress built.
	 * @return string The attributes to render.
	 */
	public function language_attributes( $output ): string {
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale ) {
			return (string) $output;
		}
		return 'lang="' . esc_attr( $locale ) . '"';
	}

	/**
	 * `rel="alternate" hreflang=...` for every language this site publishes.
	 *
	 * ⛔⛔ WITHOUT THIS THE PRODUCT DOES NOT DO WHAT IT IS SOLD FOR. Translated pages with no
	 * hreflang are, to a search engine, near-duplicate pages competing with each other — so a
	 * customer can buy translation, have it render perfectly, and rank WORSE than before.
	 * `x-default` points at the original, which is what tells a crawler which version to show
	 * a visitor whose language we do not publish.
	 */
	public function hreflang(): void {
		if ( ! is_singular() && ! is_home() && ! is_front_page() ) {
			return;
		}
		$settings = new Zinn_Translate_Settings();
		$locales  = $settings->locales();
		if ( array() === $locales ) {
			return;
		}
		$path = $this->current_path();
		$home = untrailingslashit( home_url() );

		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $home . $path )
		);
		foreach ( $locales as $locale ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $locale ),
				esc_url( $home . '/' . $locale . $path )
			);
		}
	}

	/**
	 * The request path with any locale prefix removed, always starting with `/`.
	 *
	 * ⛔ The prefix must be stripped, or every hreflang on a translated page would point at
	 * `/de/fr/about/` — a set of 404s, announced to crawlers as the canonical alternates.
	 */
	private function current_path(): string {
		// ⛔ SANITIZED, not merely unslashed. `$_SERVER['REQUEST_URI']` is attacker-controlled
		// on every request and this value is interpolated into `href` attributes that we then
		// announce to search engines as this page's canonical alternates. `wp_unslash` undoes
		// magic quotes and validates nothing; `sanitize_text_field` is what strips the control
		// characters and stray tags. Found by WordPress Coding Standards, not by review.
		$raw    = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$uri    = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$locale = Zinn_Translate_Router::current();
		if ( '' !== $locale && str_starts_with( $uri, '/' . $locale ) ) {
			$uri = substr( $uri, strlen( $locale ) + 1 );
		}
		return '' === $uri ? '/' : '/' . ltrim( $uri, '/' );
	}
}
