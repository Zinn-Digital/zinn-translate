<?php
/**
 * The one refusal every translation provider raises.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider could not be reached, or refused.
 *
 * ⭐ Carries a `kind` so the settings screen can tell a customer WHICH sort of problem they
 * have — `CLAUDE.md` §2.57's taxonomy, applied where the site owner is the only person who
 * can fix it. "Your key was rejected" and "the provider is down" have different remedies and
 * a single "translation failed" tells them neither.
 */
final class Zinn_Translate_Provider_Error extends RuntimeException {

	/**
	 * The provider is unreachable, timing out, or answering 5xx.
	 */
	public const OUTAGE = 'outage';

	/**
	 * The key is wrong, revoked, out of quota or unpaid. The site owner must act.
	 */
	public const CREDENTIAL = 'credential';

	/**
	 * We refused before calling — no key, no plan, not in good standing.
	 */
	public const REFUSED = 'refused';

	/**
	 * Anything else. Ours, not theirs, and never reported as a vendor outage.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Which of the four this is.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Where the site owner goes to fix it, when there is such a place.
	 *
	 * @var string
	 */
	private string $help_url;

	/**
	 * Build the error.
	 *
	 * @param string $kind     One of the four constants.
	 * @param string $message  Copy safe to show a site owner.
	 * @param string $help_url A URL that helps, or an empty string.
	 */
	public function __construct( string $kind, string $message, string $help_url = '' ) {
		parent::__construct( $message );
		$this->kind     = $kind;
		$this->help_url = $help_url;
	}

	/**
	 * Which kind of failure this is.
	 *
	 * @return string One of the four constants.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Where to send the site owner.
	 *
	 * @return string A URL, or an empty string.
	 */
	public function help_url(): string {
		return $this->help_url;
	}
}
