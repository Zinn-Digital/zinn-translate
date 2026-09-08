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
		}
		return $rows;
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
