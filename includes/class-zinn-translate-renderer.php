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
 * Filters everything a visitor reads into the request's locale, and emits hreflang.
 *
 * ⛔⛔ EVERY SWAP FALLS BACK TO THE CUSTOMER'S OWN WORDS, NEVER TO BLANK. A page whose body
 * is translated and whose title is not must still show the English title: a blank heading is
 * a broken page, and the customer is paying us to improve their site. This is the same
 * per-string rule the engine applies at its end, and it is stated in both places because a
 * fallback that exists on only one side of a network boundary is not a fallback.
 */
class Zinn_Translate_Renderer {

	/**
	 * This request's translations, read once and reused for every filter call.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static ?array $strings = null;

	/**
	 * Register the render-time filters.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'the_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'content' ) );
		add_filter( 'get_the_excerpt', array( $this, 'excerpt' ), 10, 2 );
		add_filter( 'single_post_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'get_the_archive_title', array( $this, 'archive_title' ) );
		add_filter( 'term_description', array( $this, 'term_description' ), 10, 2 );
		add_filter( 'get_term', array( $this, 'term' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'menu_objects' ), 10, 2 );
		add_filter( 'render_block_data', array( $this, 'navigation_block' ), 10, 1 );
		add_filter( 'get_pages', array( $this, 'pages' ), 10, 1 );
		add_filter( 'option_blogname', array( $this, 'blogname' ) );
		add_filter( 'option_blogdescription', array( $this, 'blogdescription' ) );
		add_action( 'wp_head', array( $this, 'hreflang' ) );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * One translated string, or null when we do not have it.
	 *
	 * ⭐⭐ **The single lookup every other class in this plugin uses.** The SEO compatibility
	 * layer, the WooCommerce layer, the switcher and the sitemap all ask this one function,
	 * so "which locale is this request?" and "what falls back to what?" are answered in one
	 * place. Six classes each deciding that for themselves is six chances to disagree, and
	 * the disagreement is invisible — a page where the title is French and the meta title
	 * is English looks fine to everyone except a search engine.
	 *
	 * ⛔ Returns null rather than an empty string for "no translation", because those mean
	 * different things: absent is *not translated yet* and the caller must show the
	 * original, while empty would be *translated to nothing* and would blank the page.
	 *
	 * @param string $object_ref `<doc_type>:<remote_id>`.
	 * @param string $field      Field name.
	 * @return string|null The translation, or null.
	 */
	public static function lookup( string $object_ref, string $field ): ?string {
		if ( '' === $object_ref || '' === $field ) {
			return null;
		}
		$strings = self::strings();
		if ( null === $strings || ! isset( $strings[ $object_ref ][ $field ] ) ) {
			return null;
		}
		$value = $strings[ $object_ref ][ $field ];
		return '' === trim( $value ) ? null : $value;
	}

	/**
	 * The translation of one exact string, whoever it belongs to, or null.
	 *
	 * ⭐ For values handed to us with no owner attached — a node inside a schema graph, a
	 * label a theme built. Matched on a hash of the text, so it cannot attribute one thing's
	 * translation to another.
	 *
	 * @param string $text The exact source text.
	 * @return string|null The translation, or null.
	 */
	public static function lookup_source( string $text ): ?string {
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale || '' === trim( $text ) ) {
			return null;
		}
		$index = Zinn_Translate_Store::by_source_hash( $locale );
		$hash  = Zinn_Translate_Store::hash( $text );
		return isset( $index[ $hash ] ) && '' !== trim( $index[ $hash ] ) ? $index[ $hash ] : null;
	}

	/**
	 * This request's translations, or null when the request is in the source language.
	 *
	 * @return array<string, array<string, string>>|null Fields keyed by object ref.
	 */
	private static function strings(): ?array {
		if ( null !== self::$strings ) {
			return self::$strings;
		}
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale ) {
			return null;
		}
		self::$strings = Zinn_Translate_Store::for_locale( $locale );
		return self::$strings;
	}

	/**
	 * Forget this request's strings — for tests and for a save that changed them.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$strings = null;
	}

	/**
	 * Swap a post title for its translation.
	 *
	 * ⛔⛔ **The second argument is an INT from `the_title` and a `WP_Post` from
	 * `single_post_title`, and casting it blindly is a PHP warning on every archive page.**
	 * One callback is registered on both filters — which is right, they mean the same thing —
	 * but WordPress does not pass them the same type, and `(int) $post` on an object emits
	 * `Object of class WP_Post could not be converted to int`. Invisible in a `php -l`, in
	 * the diff and in the rendered page; found by turning `WP_DEBUG_LOG` on and reading it.
	 *
	 * @param string           $title  The title WordPress is about to render.
	 * @param int|WP_Post|null $source The post, as an id or as an object depending on the filter.
	 * @return string The translated title, or the original.
	 */
	public function title( $title, $source = null ): string {
		$post = $source instanceof WP_Post ? $source : get_post( is_numeric( $source ) ? (int) $source : null );
		if ( ! $post instanceof WP_Post ) {
			return (string) $title;
		}
		$translated = self::lookup( $post->post_type . ':' . $post->ID, 'title' );
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
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return (string) $content;
		}
		$translated = self::lookup( $post->post_type . ':' . $post->ID, 'body' );
		if ( null === $translated ) {
			return (string) $content;
		}
		// ⛔ `wp_kses_post`, not raw output. The text is a translation of the customer's own
		// HTML, so it is not attacker-controlled in any ordinary sense — but "the source is
		// trusted" is exactly the reasoning that puts unfiltered HTML on a page, and this
		// runs on sites we do not operate. It permits everything a post body may contain
		// and nothing else.
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
		$resolved = $post instanceof WP_Post ? $post : get_post();
		if ( ! $resolved instanceof WP_Post ) {
			return (string) $excerpt;
		}
		$translated = self::lookup( $resolved->post_type . ':' . $resolved->ID, 'excerpt' );
		return null === $translated ? (string) $excerpt : $translated;
	}

	/**
	 * Translate a term's name wherever WordPress renders one.
	 *
	 * ⛔⛔ Filtering `get_term` rather than only the archive title, because a term's name
	 * appears in a dozen places a theme controls — a category list, a post's meta line, a
	 * product's breadcrumb, a filter widget — and there is no filter for most of them. This
	 * is the one hook that reaches all of them.
	 *
	 * ⛔ The `slug` is deliberately NOT swapped here. It is an identifier that `WP_Query`
	 * matches against the database, and translating it in the object would make every term
	 * query on the site return nothing. The translated slug lives in the URL, and the router
	 * maps it back before WordPress ever sees it.
	 *
	 * @param WP_Term|mixed $term     The term object.
	 * @param string        $taxonomy Its taxonomy.
	 * @return WP_Term|mixed The term, with a translated name.
	 */
	public function term( $term, $taxonomy = '' ) {
		unset( $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return $term;
		}
		$translated = self::lookup( 'term:' . $term->term_id, 'name' );
		if ( null !== $translated ) {
			$term->name = $translated;
		}
		return $term;
	}

	/**
	 * Translate a term description.
	 *
	 * @param string $description The description.
	 * @param int    $term_id     The term.
	 * @return string The translation, or the original.
	 */
	public function term_description( $description, $term_id = 0 ): string {
		$translated = self::lookup( 'term:' . (int) $term_id, 'description' );
		return null === $translated ? (string) $description : wp_kses_post( $translated );
	}

	/**
	 * Translate an archive title.
	 *
	 * @param string $title The title WordPress built.
	 * @return string The translated title, or the original.
	 */
	public function archive_title( $title ): string {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return (string) $title;
		}
		$translated = self::lookup( 'term:' . $term->term_id, 'name' );
		if ( null === $translated ) {
			return (string) $title;
		}
		// ⛔ The prefix ("Category: ") is preserved and only the NAME is replaced, because
		// the prefix comes from WordPress's own translation of the theme and is already in
		// the visitor's language when the site has that language pack installed.
		return str_replace( $term->name, $translated, (string) $title );
	}

	/**
	 * Translate navigation menu labels.
	 *
	 * @param array<int, object> $items The menu items.
	 * @param object             $args  The menu arguments.
	 * @return array<int, object> The items, with translated labels.
	 */
	public function menu_objects( $items, $args = null ): array {
		unset( $args );
		foreach ( (array) $items as $item ) {
			if ( ! isset( $item->ID ) ) {
				continue;
			}
			$ref   = 'menu_item:' . (int) $item->ID;
			$title = self::lookup( $ref, 'title' );
			if ( null !== $title ) {
				$item->title = $title;
			}
			$attr = self::lookup( $ref, 'attr_title' );
			if ( null !== $attr ) {
				$item->attr_title = $attr;
			}
			$description = self::lookup( $ref, 'description' );
			if ( null !== $description ) {
				$item->description = $description;
			}
		}
		return (array) $items;
	}

	/**
	 * Translate a navigation label in a block theme.
	 *
	 * ⛔ `render_block_data` and not `render_block`, so the label is swapped in the block's
	 * ATTRIBUTES before core renders it. Filtering the rendered HTML instead would mean
	 * string-replacing inside an anchor tag — which would also hit the `href`, the class and
	 * anything else that happened to contain the same word.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 * @return array<string, mixed> The block, with a translated label.
	 */
	public function navigation_block( $block ): array {
		$block = (array) $block;
		$name  = (string) ( $block['blockName'] ?? '' );
		if ( ! in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
			return $block;
		}
		$label = (string) ( $block['attrs']['label'] ?? '' );
		if ( '' === trim( $label ) ) {
			return $block;
		}
		$translated = self::lookup( 'navlabel:' . sha1( $label ), 'label' );
		if ( null !== $translated ) {
			$block['attrs']['label'] = $translated;
		}
		return $block;
	}

	/**
	 * Translate page titles wherever a list of pages is built.
	 *
	 * ⛔⛔ **THE DEFAULT THEME'S MENU IS A PAGE LIST, AND IT DOES NOT RUN `the_title`.** Twenty
	 * Twenty-Five's header is `<!-- wp:page-list /-->`, whose render callback calls
	 * `get_pages()` and reads `post_title` off each row directly. So every page filter this
	 * class registers was firing correctly and the site's own header stayed in English —
	 * measured on a stock install, and invisible in any diff. This is the one hook that
	 * reaches it, and it fixes `wp_list_pages`, page dropdowns and breadcrumb plugins with it.
	 *
	 * @param array<int, WP_Post>|mixed $pages The pages core assembled.
	 * @return array<int, WP_Post>|mixed The pages, with translated titles.
	 */
	public function pages( $pages ) {
		if ( ! is_array( $pages ) ) {
			return $pages;
		}
		foreach ( $pages as $page ) {
			if ( ! $page instanceof WP_Post ) {
				continue;
			}
			$translated = self::lookup( $page->post_type . ':' . $page->ID, 'title' );
			if ( null !== $translated ) {
				$page->post_title = $translated;
			}
		}
		return $pages;
	}

	/**
	 * Translate the site title.
	 *
	 * @param string $value The stored option.
	 * @return string The translation, or the original.
	 */
	public function blogname( $value ): string {
		$translated = self::lookup( 'site:options', 'blogname' );
		return null === $translated ? (string) $value : $translated;
	}

	/**
	 * Translate the site tagline.
	 *
	 * @param string $value The stored option.
	 * @return string The translation, or the original.
	 */
	public function blogdescription( $value ): string {
		$translated = self::lookup( 'site:options', 'blogdescription' );
		return null === $translated ? (string) $value : $translated;
	}

	/**
	 * `<html lang="fr" dir="rtl">` on a translated request.
	 *
	 * ⛔⛔ **The `dir` attribute is the whole of RTL support and it is one line.** A theme
	 * that supports right-to-left keys its own stylesheet off this attribute; a theme that
	 * does not at least gets the browser's own bidirectional layout, which is far better
	 * than Arabic rendered left to right. Emitting `lang` without `dir` is the version that
	 * looks finished and reads backwards.
	 *
	 * @param string $output The attributes WordPress built.
	 * @return string The attributes to render.
	 */
	public function language_attributes( $output ): string {
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale ) {
			return (string) $output;
		}
		$attributes = 'lang="' . esc_attr( Zinn_Translate_Locales::hreflang( $locale ) ) . '"';
		if ( Zinn_Translate_Locales::is_rtl( $locale ) ) {
			$attributes .= ' dir="rtl"';
		}
		return $attributes;
	}

	/**
	 * Add `zinn-translate-rtl` and the locale to `<body>`, so a theme can style them.
	 *
	 * @param string[] $classes The classes WordPress built.
	 * @return string[] The classes, with ours appended.
	 */
	public function body_class( $classes ): array {
		$locale = Zinn_Translate_Router::current();
		if ( '' === $locale ) {
			return (array) $classes;
		}
		$classes   = (array) $classes;
		$classes[] = 'zinn-translate-locale-' . sanitize_html_class( $locale );
		if ( Zinn_Translate_Locales::is_rtl( $locale ) ) {
			$classes[] = 'rtl';
			$classes[] = 'zinn-translate-rtl';
		}
		return $classes;
	}

	/**
	 * `rel="alternate" hreflang=...` for every language this site publishes.
	 *
	 * ⛔⛔ WITHOUT THIS THE PRODUCT DOES NOT DO WHAT IT IS SOLD FOR. Translated pages with no
	 * hreflang are, to a search engine, near-duplicate pages competing with each other — so a
	 * customer can buy translation, have it render perfectly, and rank WORSE than before.
	 * `x-default` points at the original, which is what tells a crawler which version to show
	 * a visitor whose language we do not publish.
	 *
	 * ⛔ The annotations are RECIPROCAL and include the page itself, because a set that omits
	 * self-reference is ignored outright by Google — the commonest way an hreflang
	 * implementation is wrong while looking complete.
	 *
	 * @return void
	 */
	public function hreflang(): void {
		if ( ! Zinn_Translate_Options::flag( 'hreflang' ) ) {
			return;
		}
		if ( Zinn_Translate_SEO::hreflang_taken() ) {
			return;
		}
		if ( is_404() || is_search() ) {
			return;
		}
		$locales = Zinn_Translate_Options::locales();
		if ( array() === $locales ) {
			return;
		}
		$current = Zinn_Translate_Router::current();
		$path    = Zinn_Translate_Router::strip_prefix( Zinn_Translate_Router::request_path(), $current );
		$source  = Zinn_Translate_Router::to_source_path( ltrim( $path, '/' ), $current );
		$source  = '' === trim( $source, '/' ) ? '/' : user_trailingslashit( '/' . trim( $source, '/' ) );
		$home    = Zinn_Translate_Router::home_base();

		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $home . $source )
		);
		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( Zinn_Translate_Locales::hreflang( Zinn_Translate_Options::source_locale() ) ),
			esc_url( $home . $source )
		);
		foreach ( $locales as $locale ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( Zinn_Translate_Locales::hreflang( $locale ) ),
				esc_url( $home . '/' . $locale . Zinn_Translate_Router::to_localised_path( $source, $locale ) )
			);
		}
	}
}
