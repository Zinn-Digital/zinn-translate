<?php
/**
 * Walking a WordPress site and finding every word a visitor reads.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces the site's complete translatable inventory.
 *
 * ⭐⭐ **This class is the reason the plugin can be better than a REST read.** Zinn
 * Digital® can already fetch a site's posts over the WordPress REST API, and that returns
 * four fields per post: title, body, excerpt, slug. It is not what a site is made of. A
 * shop's product attributes, its variation names, its category descriptions, the labels in
 * its menu, the alt text on its images and every SEO title Yoast keeps in post meta are all
 * invisible to that read, and between them they are most of what a visitor actually looks
 * at. This runs *inside* WordPress, so it can see all of it.
 *
 * ⛔⛔ **THE INVENTORY IS COMPLETE OR IT IS WRONG.** Both consumers of this list — the
 * engine's `/translation/sources` endpoint and the local store — retire anything the list
 * does not name (`CLAUDE.md` §2.52). A collector that silently returns a partial list
 * therefore DELETES the translations of everything it missed. So every failure here is
 * loud: a query that cannot run raises, and the caller does not retire.
 */
final class Zinn_Translate_Collector {

	/**
	 * One field of one document, ready to send.
	 *
	 * @param string $name    Field name.
	 * @param string $text    Source text.
	 * @param bool   $is_html Whether the text is HTML.
	 * @param bool   $is_slug Whether the text is a URL segment.
	 * @return array{name: string, text: string, is_html: bool, is_slug: bool} The field.
	 */
	private static function field( string $name, string $text, bool $is_html = false, bool $is_slug = false ): array {
		return array(
			'name'    => $name,
			'text'    => $text,
			'is_html' => $is_html,
			'is_slug' => $is_slug,
		);
	}

	/**
	 * Every translatable document on the site.
	 *
	 * @param int $limit The most documents to return. The engine's ceiling is 2,000.
	 * @return array<int, array{remote_id: string, doc_type: string, url: string, fields: array<int, array{name: string, text: string, is_html: bool, is_slug: bool}>}> The inventory.
	 */
	public static function inventory( int $limit = 2000 ): array {
		$documents = array();

		foreach ( self::posts( $limit ) as $document ) {
			$documents[] = $document;
		}
		if ( Zinn_Translate_Options::flag( 'translate_terms' ) ) {
			foreach ( self::terms() as $document ) {
				$documents[] = $document;
			}
		}
		if ( Zinn_Translate_Options::flag( 'translate_menus' ) ) {
			foreach ( self::menus() as $document ) {
				$documents[] = $document;
			}
			foreach ( self::block_navigation() as $document ) {
				$documents[] = $document;
			}
		}
		foreach ( self::site_strings() as $document ) {
			$documents[] = $document;
		}
		if ( Zinn_Translate_Options::flag( 'translate_woo' ) && self::woocommerce_active() ) {
			foreach ( Zinn_Translate_WooCommerce::documents() as $document ) {
				$documents[] = $document;
			}
		}

		/**
		 * Filters the complete translatable inventory before it is sent or stored.
		 *
		 * ⛔ Anything removed here is RETIRED, not merely skipped — see this class's own
		 * header. Use it to add documents from a custom plugin, not to filter documents out.
		 *
		 * @param array<int, array<string, mixed>> $documents The inventory.
		 */
		$documents = (array) apply_filters( 'zinn_translate_inventory', $documents );

		return array_slice( array_values( $documents ), 0, max( 1, $limit ) );
	}

	/**
	 * Every object ref the site currently has — the keep-list for retirement.
	 *
	 * @param array<int, array<string, mixed>> $inventory The inventory.
	 * @return string[] Object refs.
	 */
	public static function refs( array $inventory ): array {
		$refs = array();
		foreach ( $inventory as $document ) {
			$refs[] = (string) $document['doc_type'] . ':' . (string) $document['remote_id'];
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Posts, pages and any other public post type the customer chose.
	 *
	 * @param int $limit The most posts to read.
	 * @return array<int, array<string, mixed>> Documents.
	 */
	private static function posts( int $limit ): array {
		$types = Zinn_Translate_Options::post_types();
		if ( array() === $types ) {
			return array();
		}
		// ⛔ Published only. A draft is not on the site, so translating it spends the
		// customer's allowance on words no visitor can reach — and a draft that is later
		// binned would then need retiring, which is a second cost for the same mistake.
		$query     = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, $limit ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);
		$meta_keys = Zinn_Translate_SEO::meta_keys();
		$documents = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$fields = array();
			if ( '' !== trim( $post->post_title ) ) {
				$fields[] = self::field( 'title', $post->post_title );
			}
			if ( '' !== trim( $post->post_content ) ) {
				$fields[] = self::field( 'body', $post->post_content, true );
			}
			if ( '' !== trim( $post->post_excerpt ) ) {
				$fields[] = self::field( 'excerpt', $post->post_excerpt );
			}
			if ( Zinn_Translate_Options::flag( 'translate_slugs' ) && '' !== $post->post_name ) {
				$fields[] = self::field( 'slug', $post->post_name, false, true );
			}
			if ( Zinn_Translate_Options::flag( 'translate_meta' ) ) {
				foreach ( $meta_keys as $field_name => $meta_key ) {
					$value = (string) get_post_meta( $post->ID, $meta_key, true );
					if ( '' !== trim( $value ) ) {
						$fields[] = self::field( $field_name, $value );
					}
				}
			}
			if ( array() === $fields ) {
				continue;
			}
			$documents[] = array(
				'remote_id' => (string) $post->ID,
				'doc_type'  => $post->post_type,
				'url'       => (string) get_permalink( $post ),
				'fields'    => $fields,
			);
		}

		if ( Zinn_Translate_Options::flag( 'translate_meta' ) ) {
			$documents = array_merge( $documents, self::attachment_alt_text( $limit ) );
		}
		return $documents;
	}

	/**
	 * Image `alt` text.
	 *
	 * ⭐ Its own pass, because attachments are not in the customer's chosen post types and
	 * never should be — nobody wants their media library's post titles translated. What is
	 * wanted is the one field a visitor's screen reader reads, which lives in post meta.
	 *
	 * @param int $limit The most attachments to read.
	 * @return array<int, array<string, mixed>> Documents.
	 */
	private static function attachment_alt_text( int $limit ): array {
		$query     = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => max( 1, min( $limit, 500 ) ),
				'meta_key'               => '_wp_attachment_image_alt', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The point of the query IS this key; there is no other way to ask for attachments that have alt text.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$documents = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$alt = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
			if ( '' === trim( $alt ) ) {
				continue;
			}
			$documents[] = array(
				'remote_id' => (string) $post->ID,
				'doc_type'  => 'attachment',
				'url'       => (string) wp_get_attachment_url( $post->ID ),
				'fields'    => array( self::field( 'alt', $alt ) ),
			);
		}
		return $documents;
	}

	/**
	 * Category, tag and custom taxonomy terms.
	 *
	 * @return array<int, array<string, mixed>> Documents.
	 * @throws RuntimeException When the taxonomy query fails — see the note below.
	 */
	private static function terms(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( is_wp_error( $terms ) ) {
			// ⛔ Raised, not swallowed. An empty list here would retire every term
			// translation the site has (§2.52), so "I could not read the terms" and "there
			// are no terms" must never produce the same value (§2.44).
			throw new RuntimeException( esc_html( $terms->get_error_message() ) );
		}
		$documents = array();
		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$fields = array( self::field( 'name', $term->name ) );
			if ( '' !== trim( $term->description ) ) {
				$fields[] = self::field( 'description', $term->description, true );
			}
			if ( Zinn_Translate_Options::flag( 'translate_slugs' ) && '' !== $term->slug ) {
				$fields[] = self::field( 'slug', $term->slug, false, true );
			}
			$documents[] = array(
				'remote_id' => (string) $term->term_id,
				'doc_type'  => 'term',
				'url'       => '',
				'fields'    => $fields,
			);
		}
		return $documents;
	}

	/**
	 * Navigation menu labels.
	 *
	 * ⭐ A menu item's label is stored on the item, not on the thing it points at, and it is
	 * routinely different from the target's title — "Home", "Get in touch", "Shop all". A
	 * site whose pages are translated and whose menu is not looks broken in a way that is
	 * more obvious than an untranslated paragraph, because the menu is on every page.
	 *
	 * @return array<int, array<string, mixed>> Documents.
	 */
	private static function menus(): array {
		$menus     = wp_get_nav_menus();
		$documents = array();
		foreach ( (array) $menus as $menu ) {
			if ( ! $menu instanceof WP_Term ) {
				continue;
			}
			$items = wp_get_nav_menu_items( $menu->term_id );
			foreach ( (array) $items as $item ) {
				if ( ! isset( $item->ID, $item->title ) || '' === trim( (string) $item->title ) ) {
					continue;
				}
				$fields = array( self::field( 'title', (string) $item->title ) );
				if ( '' !== trim( (string) ( $item->attr_title ?? '' ) ) ) {
					$fields[] = self::field( 'attr_title', (string) $item->attr_title );
				}
				if ( '' !== trim( (string) ( $item->description ?? '' ) ) ) {
					$fields[] = self::field( 'description', (string) $item->description );
				}
				$documents[] = array(
					'remote_id' => (string) $item->ID,
					'doc_type'  => 'menu_item',
					'url'       => '',
					'fields'    => $fields,
				);
			}
		}
		return $documents;
	}

	/**
	 * Navigation labels in a BLOCK theme.
	 *
	 * ⛔⛔ **A BLOCK THEME'S MENU IS NOT A MENU, AND A PLUGIN THAT ONLY HANDLES
	 * `nav_menu_item` TRANSLATES NOTHING A VISITOR SEES.** Every default theme since Twenty
	 * Twenty-Two renders `core/navigation`, whose entries are `core/navigation-link` blocks
	 * with their label in a block ATTRIBUTE, stored inside a `wp_navigation` post or a
	 * template part. None of it passes through `wp_nav_menu`, so the classic filters never
	 * fire. Measured on a stock Twenty Twenty-Five install: the classic menu was collected
	 * and translated perfectly, and the header on screen stayed in English.
	 *
	 * ⭐ Keyed by a hash of the LABEL rather than by a block id, because a block has no
	 * stable id — editing one entry rewrites the whole serialised document. Content
	 * addressing means "Shop" is translated once however many navigations contain it, and
	 * editing an unrelated entry does not re-buy the rest.
	 *
	 * @return array<int, array<string, mixed>> Documents.
	 */
	private static function block_navigation(): array {
		$labels = array();

		// ⛔⛔ **`get_block_templates()`, NOT `get_posts()`, AND THIS IS THE WHOLE POINT.** A
		// block theme ships its templates as HTML FILES and only writes a `wp_template_part`
		// post once somebody edits one — so on a stock install, which is most installs, a
		// `get_posts()` query returns nothing and the collector reports a site with no
		// navigation. It answers zero, which is indistinguishable from "this site has no
		// menu" and is exactly the reassuring reading §2.44 warns about. `get_block_templates`
		// returns the file-based and the database-based templates together, which is the
		// question being asked.
		foreach ( array( 'wp_template_part', 'wp_template' ) as $type ) {
			if ( ! function_exists( 'get_block_templates' ) ) {
				break;
			}
			foreach ( (array) get_block_templates( array(), $type ) as $template ) {
				if ( ! isset( $template->content ) || ! is_string( $template->content ) ) {
					continue;
				}
				foreach ( self::labels_in( parse_blocks( $template->content ) ) as $label ) {
					$labels[ $label ] = true;
				}
			}
		}

		// A navigation the site owner has edited lives in a `wp_navigation` post.
		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'any',
				'posts_per_page' => 50,
			)
		);
		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			foreach ( self::labels_in( parse_blocks( $post->post_content ) ) as $label ) {
				$labels[ $label ] = true;
			}
		}
		$documents = array();
		foreach ( array_keys( $labels ) as $label ) {
			$documents[] = array(
				'remote_id' => sha1( (string) $label ),
				'doc_type'  => 'navlabel',
				'url'       => '',
				'fields'    => array( self::field( 'label', (string) $label ) ),
			);
		}
		return $documents;
	}

	/**
	 * Every navigation label in a parsed block tree, however deeply nested.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param int                              $depth  How deep this call already is.
	 * @return string[] The labels.
	 */
	private static function labels_in( array $blocks, int $depth = 0 ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			// ⛔⛔ **A PATTERN REFERENCE IS NOT ITS CONTENT, AND THIS IS WHERE THE DEFAULT
			// THEME KEEPS ITS MENU.** Twenty Twenty-Five's header template part is exactly
			// one block — `<!-- wp:pattern {"slug":"twentytwentyfive/header"} /-->` — and the
			// navigation links live in a PHP file inside the theme that the reference points
			// at. Walking the stored template finds a pattern slug and no labels at all, so
			// the collector reports a site with no navigation and every header on every
			// block-theme site stays in the original language. Measured on a stock install.
			// ⛔ Only patterns the site's own templates REFERENCE are expanded. WordPress has
			// hundreds registered, most of them never rendered, and translating those would
			// spend a customer's allowance on words no visitor will ever see.
			if ( 'core/pattern' === $name && 4 > $depth ) {
				$slug  = (string) ( $block['attrs']['slug'] ?? '' );
				$found = array_merge( $found, self::labels_in( self::pattern_blocks( $slug ), $depth + 1 ) );
				continue;
			}
			if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$label = (string) ( $block['attrs']['label'] ?? '' );
				if ( '' !== trim( $label ) ) {
					$found[] = $label;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				// ⛔ Recursive, because a submenu's children are inner blocks. A one-level
				// walk translates the top row of a menu and leaves every dropdown in English,
				// which looks more broken than translating none of it.
				$found = array_merge( $found, self::labels_in( $block['innerBlocks'], $depth + 1 ) );
			}
		}
		return $found;
	}

	/**
	 * The parsed blocks of one registered pattern.
	 *
	 * ⛔ A depth bound is carried by the caller because a pattern may reference a pattern.
	 * Four is generous for real themes and finite, which is what matters — a theme with a
	 * self-referencing pattern would otherwise hang the collector on a customer's cron.
	 *
	 * @param string $slug The pattern slug.
	 * @return array<int, array<string, mixed>> Parsed blocks, or none.
	 */
	private static function pattern_blocks( string $slug ): array {
		if ( '' === $slug || ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}
		$registry = WP_Block_Patterns_Registry::get_instance();
		if ( ! $registry->is_registered( $slug ) ) {
			return array();
		}
		$pattern = $registry->get_registered( $slug );
		$content = is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '';
		return '' === $content ? array() : parse_blocks( $content );
	}

	/**
	 * The site's own title and tagline.
	 *
	 * @return array<int, array<string, mixed>> A single document, or none.
	 */
	private static function site_strings(): array {
		$fields = array();
		$name   = (string) get_option( 'blogname', '' );
		$about  = (string) get_option( 'blogdescription', '' );
		if ( '' !== trim( $name ) ) {
			$fields[] = self::field( 'blogname', $name );
		}
		if ( '' !== trim( $about ) ) {
			$fields[] = self::field( 'blogdescription', $about );
		}
		if ( array() === $fields ) {
			return array();
		}
		return array(
			array(
				'remote_id' => 'options',
				'doc_type'  => 'site',
				'url'       => home_url( '/' ),
				'fields'    => $fields,
			),
		);
	}

	/**
	 * Whether WooCommerce is running.
	 *
	 * @return bool True when WooCommerce is loaded.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
