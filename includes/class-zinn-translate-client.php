<?php
/**
 * Fetching a translated bundle from Zinn Digital®, and caching it.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads one locale's bundle and keeps it in the object cache.
 *
 * ⛔⛔ EVERY PAGE VIEW MUST NOT BE AN HTTP REQUEST TO US. A naive version of this class
 * would fetch on each render, which would put our API in the critical path of somebody
 * else's site: our latency becomes their TTFB, our outage becomes their outage, and a
 * popular site becomes a denial of service against us. So a bundle is cached for
 * `CACHE_TTL` and served from cache; the site is slower than untranslated by one cache read.
 */
class Zinn_Translate_Client {

	/**
	 * How long a fetched bundle is trusted, in seconds.
	 *
	 * ⭐ Fifteen minutes is chosen against what the customer experiences, not against load:
	 * they edit a page, wait for it to translate, and want to see it. Much longer and the
	 * product feels broken; much shorter and we are fetching for no reason, because the
	 * pipeline rarely finishes a locale faster than this.
	 */
	private const CACHE_TTL = 900;

	/**
	 * How long a FAILED fetch is remembered, in seconds.
	 *
	 * ⛔⛔ A NEGATIVE CACHE, AND IT IS THE MOST IMPORTANT NUMBER IN THIS FILE. Without it,
	 * an outage on our side turns every page view on every translated site into a fresh HTTP
	 * request that will also fail — so the moment we are struggling, thousands of sites begin
	 * hammering us, and the incident cannot end. It is shorter than CACHE_TTL because
	 * recovering quickly matters more than the saved requests.
	 */
	private const FAILURE_TTL = 120;

	private const CACHE_GROUP = 'zinn_translate';

	/**
	 * The bundle for one locale, or null if we do not have one.
	 *
	 * ⛔ Returns null rather than an empty array on failure, and the caller renders the
	 * site's ORIGINAL content. An empty array would be indistinguishable from "this site has
	 * no translations", and the renderer would blank every string it was asked for — turning
	 * a transient network error into a visibly broken page (§2.44: the ambiguous value must
	 * not be the damaging one).
	 *
	 * @param string $locale Locale code.
	 * @return array<string, array<string, string>>|null Documents keyed by `<type>:<id>`.
	 */
	public function bundle( string $locale ): ?array {
		$key    = 'bundle_' . $locale;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'failed' === $cached ) {
			return null;
		}

		$settings = new Zinn_Translate_Settings();
		$site_id  = $settings->site_id();
		$token    = $settings->token();
		if ( '' === $site_id || '' === $token ) {
			return null;
		}

		$url = sprintf(
			'%s/v1/sites/%s/translation/bundle/%s',
			zinn_translate_api_base(),
			rawurlencode( $site_id ),
			rawurlencode( $locale )
		);

		$response = wp_remote_get(
			$url,
			array(
				// ⛔ A short timeout, because this call sits in front of a page render. A
				// default 5s timeout would mean a slow response from us shows up as a
				// five-second blank page on a customer's site.
				'timeout' => 3,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_cache_set( $key, 'failed', self::CACHE_GROUP, self::FAILURE_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['documents'] ) || ! is_array( $body['documents'] ) ) {
			wp_cache_set( $key, 'failed', self::CACHE_GROUP, self::FAILURE_TTL );
			return null;
		}

		$documents = array();
		foreach ( $body['documents'] as $document ) {
			if ( ! is_array( $document ) || ! isset( $document['document'], $document['fields'] ) ) {
				continue;
			}
			if ( ! is_array( $document['fields'] ) ) {
				continue;
			}
			$documents[ (string) $document['document'] ] = array_map(
				'strval',
				$document['fields']
			);
		}

		wp_cache_set( $key, $documents, self::CACHE_GROUP, self::CACHE_TTL );
		return $documents;
	}
}
