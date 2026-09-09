<?php
/**
 * WooCommerce: the parts of a shop a REST read of a post cannot see.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and renders the shop-specific strings a customer reads.
 *
 * ⛔⛔ **A HALF-TRANSLATED SHOP IS WORSE THAN AN UNTRANSLATED ONE, AND THAT IS NOT A
 * FIGURE OF SPEECH.** A visitor who reads a French product page, adds to basket, and then
 * meets an English variation picker reading "Choose an option — Colour: Red, Blue" has been
 * shown that the site is not really in their language at exactly the moment they were about
 * to pay. Attributes, variation names, category descriptions and the purchase note are all
 * stored somewhere other than the product post's four core fields, so a translation plugin
 * that only handles posts produces precisely that shop.
 *
 * ── ⛔ What is deliberately NOT translated ────────────────────────────────────────────
 * SKUs, attribute TAXONOMY names (`pa_colour`), variation attribute VALUES as stored on an
 * order, and anything a payment gateway echoes back. Those are identifiers: a translated
 * SKU does not match the warehouse, and a translated stored attribute value stops matching
 * the variation it selects, so the customer's basket silently resolves to the wrong item.
 * Only what is DISPLAYED is translated, and the stored value is left alone underneath it.
 */
final class Zinn_Translate_WooCommerce {

	/**
	 * Register the shop-side render filters.
	 *
	 * @return void
	 */
	public function hooks(): void {
		if ( ! Zinn_Translate_Collector::woocommerce_active() ) {
			return;
		}
		// ⛔ The LABEL a shopper reads, never the taxonomy key underneath it. Filtering the
		// key would break variation matching and put the wrong item in the basket.
		add_filter( 'woocommerce_attribute_label', array( $this, 'attribute_label' ), 20, 2 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'option_name' ), 20 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'short_description' ), 20, 2 );
		add_filter( 'woocommerce_product_get_purchase_note', array( $this, 'purchase_note' ), 20, 2 );
		add_filter( 'woocommerce_display_product_attributes', array( $this, 'display_attributes' ), 20, 2 );
		// ⭐ The variation description, on BOTH seams, and they are not redundant. The getter
		// is the real one: `WC_Product_Variable::get_available_variations()` calls
		// `$variation->get_description()`, so filtering the prop reaches the variation form,
		// the REST response and anything else that asks the object. The array filter is the
		// belt — a theme or a caching layer that assembles that payload without going back
		// through the getter would otherwise hand the shopper the source language at the one
		// moment they are choosing what to buy. Both are safe to run together because
		// `lookup()` is keyed by id and field, never by the text it is replacing, so applying
		// it twice returns the same string rather than translating a translation.
		add_filter( 'woocommerce_product_variation_get_description', array( $this, 'variation_description' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'available_variation' ), 20, 3 );
	}

	/**
	 * Every shop document worth translating.
	 *
	 * @return array<int, array<string, mixed>> Documents.
	 */
	public static function documents(): array {
		$documents = array();
		$documents = array_merge( $documents, self::products() );
		$documents = array_merge( $documents, self::global_attributes() );
		return $documents;
	}

	/**
	 * Products, their short descriptions, purchase notes and per-product attributes.
	 *
	 * ⛔ Variations are collected under their PARENT's document rather than as documents of
	 * their own, keyed `variation:<id>:description`. A shop with forty products and six
	 * variations each is 240 extra documents — a quarter of the engine's whole ceiling —
	 * for one description field apiece, and most of them are empty.
	 *
	 * @return array<int, array<string, mixed>> Documents.
	 */
	private static function products(): array {
		$query     = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A whole-shop inventory pass in cron, bounded well below the engine's 2,000-document ceiling.
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$documents = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$fields = array();

			// The short description is `post_excerpt` on a product, and it is the block a
			// shopper reads beside the price — the single most-read paragraph in a shop.
			if ( '' !== trim( $post->post_excerpt ) ) {
				$fields[] = array(
					'name'    => 'short_description',
					'text'    => $post->post_excerpt,
					'is_html' => true,
					'is_slug' => false,
				);
			}
			$note = (string) get_post_meta( $post->ID, '_purchase_note', true );
			if ( '' !== trim( $note ) ) {
				$fields[] = array(
					'name'    => 'purchase_note',
					'text'    => $note,
					'is_html' => true,
					'is_slug' => false,
				);
			}

			// ⛔ Custom (non-taxonomy) attributes live in one serialised meta value, so the
			// NAME and the pipe-separated VALUES both have to be dug out by hand. A shop
			// that uses custom attributes rather than global ones — which is most small
			// shops — has all of its "Material: Oak | Walnut" strings only here.
			$attributes = get_post_meta( $post->ID, '_product_attributes', true );
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $key => $attribute ) {
					if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) ) {
						continue;
					}
					$name = (string) ( $attribute['name'] ?? '' );
					if ( '' !== trim( $name ) ) {
						$fields[] = array(
							'name'    => 'attr_name.' . sanitize_key( (string) $key ),
							'text'    => $name,
							'is_html' => false,
							'is_slug' => false,
						);
					}
					$value = (string) ( $attribute['value'] ?? '' );
					if ( '' !== trim( $value ) ) {
						$fields[] = array(
							'name'    => 'attr_value.' . sanitize_key( (string) $key ),
							'text'    => $value,
							'is_html' => false,
							'is_slug' => false,
						);
					}
				}
			}

			foreach ( self::variation_descriptions( $post->ID ) as $variation_id => $description ) {
				$fields[] = array(
					'name'    => 'variation.' . $variation_id,
					'text'    => $description,
					'is_html' => true,
					'is_slug' => false,
				);
			}

			if ( array() === $fields ) {
				continue;
			}
			$documents[] = array(
				'remote_id' => (string) $post->ID,
				'doc_type'  => 'product_extra',
				'url'       => (string) get_permalink( $post ),
				'fields'    => $fields,
			);
		}
		return $documents;
	}

	/**
	 * The descriptions of one product's variations, keyed by variation id.
	 *
	 * @param int $product_id The parent product.
	 * @return array<int, string> Descriptions keyed by variation id.
	 */
	private static function variation_descriptions( int $product_id ): array {
		$children = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);
		$out      = array();
		foreach ( (array) $children as $variation_id ) {
			$description = (string) get_post_meta( (int) $variation_id, '_variation_description', true );
			if ( '' !== trim( $description ) ) {
				$out[ (int) $variation_id ] = $description;
			}
		}
		return $out;
	}

	/**
	 * Global attribute labels — the ones stored in WooCommerce's own table.
	 *
	 * ⛔ Read through `wc_get_attribute_taxonomies()` rather than a direct query on
	 * `wp_woocommerce_attribute_taxonomies`: that table's name and shape are WooCommerce's
	 * to change, and a plugin that queries another plugin's table breaks on its next update
	 * with a fatal on a customer's shop.
	 *
	 * @return array<int, array<string, mixed>> A single document, or none.
	 */
	private static function global_attributes(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}
		$fields = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $taxonomy ) {
			$name  = (string) ( $taxonomy->attribute_name ?? '' );
			$label = (string) ( $taxonomy->attribute_label ?? '' );
			if ( '' === trim( $name ) || '' === trim( $label ) ) {
				continue;
			}
			$fields[] = array(
				'name'    => 'label.' . sanitize_key( $name ),
				'text'    => $label,
				'is_html' => false,
				'is_slug' => false,
			);
		}
		if ( array() === $fields ) {
			return array();
		}
		return array(
			array(
				'remote_id' => 'attributes',
				'doc_type'  => 'shop',
				'url'       => '',
				'fields'    => $fields,
			),
		);
	}

	/**
	 * Translate an attribute label a shopper sees.
	 *
	 * @param string $label The label WooCommerce built.
	 * @param string $name  The attribute's taxonomy name.
	 * @return string The translated label, or the original.
	 */
	public function attribute_label( $label, $name = '' ): string {
		$key = sanitize_key( str_replace( 'pa_', '', (string) $name ) );
		if ( '' === $key ) {
			return (string) $label;
		}
		$translated = Zinn_Translate_Renderer::lookup( 'shop:attributes', 'label.' . $key );
		return null === $translated ? (string) $label : $translated;
	}

	/**
	 * Translate a variation option name — the term shown in the dropdown.
	 *
	 * ⛔ Looked up as a TERM, because a global attribute's options are taxonomy terms and
	 * are already collected as such. This filter receives the rendered name, so it is
	 * matched by name rather than by id — the one place in this file where that is
	 * unavoidable, and it is safe because the value returned is only ever displayed.
	 *
	 * @param string $name The option name WooCommerce built.
	 * @return string The translated name, or the original.
	 */
	public function option_name( $name ): string {
		$term = get_term_by( 'name', (string) $name, '' );
		if ( $term instanceof WP_Term ) {
			$translated = Zinn_Translate_Renderer::lookup( 'term:' . $term->term_id, 'name' );
			if ( null !== $translated ) {
				return $translated;
			}
		}
		return (string) $name;
	}

	/**
	 * Translate a product's short description.
	 *
	 * @param string $value   The short description.
	 * @param object $product The WooCommerce product object.
	 * @return string The translation, or the original.
	 */
	public function short_description( $value, $product = null ): string {
		return $this->product_field( (string) $value, $product, 'short_description' );
	}

	/**
	 * Translate a product's purchase note.
	 *
	 * @param string $value   The purchase note.
	 * @param object $product The WooCommerce product object.
	 * @return string The translation, or the original.
	 */
	public function purchase_note( $value, $product = null ): string {
		return $this->product_field( (string) $value, $product, 'purchase_note' );
	}

	/**
	 * Translate one variation's description.
	 *
	 * ⛔⛔ **COLLECTED SINCE THE PLUGIN SHIPPED AND READ BY NOTHING UNTIL THIS CALLBACK.**
	 * `variation.<id>` was dug out of `_variation_description`, sent to a model, paid for and
	 * stored in every locale the customer publishes — and no code path ever asked for it. A
	 * French shopper picking "Oak / Large" got the French product page and an English
	 * paragraph underneath the picker, at the exact moment §2.38's cost is highest: the one
	 * screen where a half-translated shop is visible while somebody is deciding to pay.
	 *
	 * ⛔ The parent's id, not the variation's, is the document — variations are collected
	 * under their parent (see `products()`), because a shop with six variations per product
	 * would otherwise be six times the documents for one field apiece.
	 *
	 * @param string $value   The variation's description.
	 * @param object $product The `WC_Product_Variation`.
	 * @return string The translation, or the original.
	 */
	public function variation_description( $value, $product = null ): string {
		$variation_id = self::product_id( $product );
		$parent_id    = self::parent_id( $product );
		if ( 0 === $variation_id || 0 === $parent_id ) {
			return (string) $value;
		}
		$translated = self::variation_translation( $parent_id, $variation_id );
		return null === $translated ? (string) $value : $translated;
	}

	/**
	 * Translate the description inside the payload the variation form reads.
	 *
	 * @param array<string, mixed> $data      The variation payload WooCommerce assembled.
	 * @param object               $product   The parent variable product.
	 * @param object               $variation The variation itself.
	 * @return array<string, mixed> The payload, with its description translated.
	 */
	public function available_variation( $data, $product = null, $variation = null ): array {
		$data = (array) $data;
		if ( ! isset( $data['variation_description'] ) ) {
			return $data;
		}
		// ⛔ The ids come from the OBJECTS rather than from `$data['variation_id']`, because
		// the payload is a plain array a third party may have already rewritten, and an id
		// read out of it is a claim by whoever last touched it.
		$variation_id = self::product_id( $variation );
		$parent_id    = self::product_id( $product );
		if ( 0 === $parent_id ) {
			$parent_id = self::parent_id( $variation );
		}
		if ( 0 === $variation_id || 0 === $parent_id ) {
			return $data;
		}
		$translated = self::variation_translation( $parent_id, $variation_id );
		if ( null !== $translated ) {
			$data['variation_description'] = $translated;
		}
		return $data;
	}

	/**
	 * One variation's translated description, filtered for output.
	 *
	 * ⭐ ONE helper for both seams, so the two can never disagree about which document a
	 * variation lives under or about how its HTML is filtered — the shape §2.52 warns about,
	 * where a rule copied into two callers is two chances to forget it.
	 *
	 * ⛔ `wp_kses_post`, matching how the renderer treats every other translated body: this
	 * is HTML that came back over a network, and "our own model wrote it" is exactly the
	 * reasoning that puts unfiltered markup on somebody else's shop.
	 *
	 * @param int $parent_id    The parent product.
	 * @param int $variation_id The variation.
	 * @return string|null The translation, or null when there is none.
	 */
	private static function variation_translation( int $parent_id, int $variation_id ): ?string {
		$translated = Zinn_Translate_Renderer::lookup( 'product_extra:' . $parent_id, 'variation.' . $variation_id );
		return null === $translated ? null : wp_kses_post( $translated );
	}

	/**
	 * The parent id of a WooCommerce variation, without assuming its class exists.
	 *
	 * @param object|null $product A `WC_Product_Variation`, or anything else.
	 * @return int The parent product id, or 0.
	 */
	private static function parent_id( $product ): int {
		if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) ) {
			return (int) $product->get_parent_id();
		}
		return 0;
	}

	/**
	 * Translate the labels in the rendered "Additional information" table.
	 *
	 * @param array<string, array<string, mixed>> $rows    The rows WooCommerce built.
	 * @param object                              $product The product.
	 * @return array<string, array<string, mixed>> The rows, with translated labels.
	 */
	public function display_attributes( $rows, $product = null ): array {
		$rows = (array) $rows;
		$id   = self::product_id( $product );
		if ( 0 === $id ) {
			return $rows;
		}
		// Read once, outside the loop: every row asks the same meta row for its options.
		$stored = get_post_meta( $id, '_product_attributes', true );
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['label'] ) ) {
				continue;
			}
			$slug       = sanitize_key( str_replace( array( 'attribute_', 'pa_' ), '', (string) $key ) );
			$translated = Zinn_Translate_Renderer::lookup( 'product_extra:' . $id, 'attr_name.' . $slug );
			if ( null === $translated ) {
				$translated = Zinn_Translate_Renderer::lookup( 'shop:attributes', 'label.' . $slug );
			}
			if ( null !== $translated ) {
				$rows[ $key ]['label'] = $translated;
			}
			if ( isset( $row['value'] ) && is_string( $row['value'] ) ) {
				$rows[ $key ]['value'] = self::translated_attribute_value( $id, $slug, $row['value'], $stored );
			}
		}
		return $rows;
	}

	/**
	 * Translate the VALUES in one row of the attributes table.
	 *
	 * ⛔⛔ **THE OTHER HALF OF THIS TABLE, AND IT WAS COLLECTED AND NEVER READ.** The loop
	 * above rewrote `label` and nothing rewrote `value`, so a French product page showed
	 * "Matériau: Oak, Walnut" — the question translated and the answer not. `attr_value.<key>`
	 * had been paid for in every locale since the plugin shipped and reached no page.
	 *
	 * ⛔⛔ **AND IT IS NOT A SUBSTITUTION, BECAUSE THE TWO SIDES ARE NOT THE SAME STRING.**
	 * What we collected is what WooCommerce stores — `Oak | Walnut`, pipe-separated. What
	 * this filter is handed is what WooCommerce *rendered* — the options escaped, texturised
	 * and joined with ", ", inside a `<p>`. Dropping the translation in whole would replace
	 * `<p>Oak, Walnut</p>` with `Chêne | Noyer`: the right words, the wrong separator, and
	 * the paragraph gone. So the parts are matched up and swapped one for one, inside the
	 * markup WooCommerce built.
	 *
	 * ⛔ And when the two sides do not have the same NUMBER of parts, nothing is swapped. A
	 * model that merged two options into one, or an option edited since the last collection,
	 * would otherwise put one attribute's translation against another attribute's value —
	 * which is worse than leaving it in English, because it is wrong and looks right.
	 *
	 * @param int    $id       The product.
	 * @param string $slug     The attribute key.
	 * @param string $rendered The value WooCommerce rendered.
	 * @param mixed  $stored   The product's `_product_attributes` meta.
	 * @return string The rendered value with its parts translated, or unchanged.
	 */
	private static function translated_attribute_value( int $id, string $slug, string $rendered, $stored ): string {
		// ⛔ Taxonomy attributes are deliberately not here: their options are terms, they are
		// collected as `term:<id>` documents, and the renderer's own `get_term` filter has
		// already translated them by the time this row was built. Only CUSTOM attributes —
		// the ones stored as one serialised string on the product — reach this lookup, which
		// is exactly the set `attr_value.<key>` was collected from.
		$translated = Zinn_Translate_Renderer::lookup( 'product_extra:' . $id, 'attr_value.' . $slug );
		if ( null === $translated ) {
			return $rendered;
		}
		$source = self::attribute_parts( $stored, $slug );
		$parts  = array_map( 'trim', explode( '|', $translated ) );
		if ( array() === $source || count( $source ) !== count( $parts ) ) {
			return $rendered;
		}
		$replace = array();
		foreach ( $source as $index => $original ) {
			if ( '' !== $original && '' !== $parts[ $index ] && $original !== $parts[ $index ] ) {
				$replace[ $original ] = $parts[ $index ];
			}
		}
		if ( array() === $replace ) {
			return $rendered;
		}
		// ⛔ Longest source first, so "Oak" cannot be replaced inside "Oak Veneer" and leave
		// a half-translated word behind.
		uksort(
			$replace,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);
		return str_replace( array_keys( $replace ), array_values( $replace ), $rendered );
	}

	/**
	 * One custom attribute's options, as they were collected.
	 *
	 * @param mixed  $attributes The product's `_product_attributes` meta.
	 * @param string $slug       The attribute key, sanitised.
	 * @return string[] The options, trimmed, or none.
	 */
	private static function attribute_parts( $attributes, string $slug ): array {
		$attributes = maybe_unserialize( $attributes );
		if ( ! is_array( $attributes ) ) {
			return array();
		}
		foreach ( $attributes as $key => $attribute ) {
			if ( ! is_array( $attribute ) || sanitize_key( (string) $key ) !== $slug ) {
				continue;
			}
			if ( ! empty( $attribute['is_taxonomy'] ) ) {
				return array();
			}
			return array_map( 'trim', explode( '|', (string) ( $attribute['value'] ?? '' ) ) );
		}
		return array();
	}

	/**
	 * One translated field of one product.
	 *
	 * @param string $value   The original value.
	 * @param object $product The product.
	 * @param string $field   Our field name.
	 * @return string The translation, or the original.
	 */
	private function product_field( string $value, $product, string $field ): string {
		$id = self::product_id( $product );
		if ( 0 === $id ) {
			return $value;
		}
		$translated = Zinn_Translate_Renderer::lookup( 'product_extra:' . $id, $field );
		return null === $translated ? $value : $translated;
	}

	/**
	 * The id of a WooCommerce product object, without assuming its class exists.
	 *
	 * @param object|null $product A `WC_Product`, or anything else.
	 * @return int The product id, or 0.
	 */
	private static function product_id( $product ): int {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}
		return 0;
	}
}
