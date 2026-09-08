<?php
/**
 * `wp zinn-translate` — running the pipeline by hand.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for collecting, translating and inspecting.
 *
 * ⭐⭐ **This is how the work is verified rather than believed.** Everything the plugin does
 * in the background can be made to happen now, in the foreground, with its counts printed —
 * so "translation is working" stops being a claim about a cron job nobody has watched and
 * becomes a number somebody read (`CLAUDE.md` §2.24). It is also what a host's support team
 * needs when a customer says nothing is translating.
 *
 * ⛔ Registered only when WP-CLI is running, so nothing here is loaded on a page view.
 */
final class Zinn_Translate_CLI {

	/**
	 * Register the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'zinn-translate', self::class );
	}

	/**
	 * What this site holds, and what is outstanding.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-translate status
	 *
	 * @return void
	 */
	public function status(): void {
		$counts = Zinn_Translate_Store::counts();
		$status = Zinn_Translate_Status::status();
		WP_CLI::log( 'state:        ' . (string) $status['state'] );
		WP_CLI::log( 'summary:      ' . (string) $status['summary'] );
		WP_CLI::log( 'source:       ' . Zinn_Translate_Options::source_locale() );
		WP_CLI::log( 'publishing:   ' . implode( ', ', Zinn_Translate_Options::locales() ) );
		WP_CLI::log( 'provider:     ' . Zinn_Translate_Provider_Factory::current()->label() );
		WP_CLI::log( 'seo plugin:   ' . ( '' === Zinn_Translate_SEO::detected_label() ? 'none' : Zinn_Translate_SEO::detected_label() ) );
		WP_CLI::log( 'rows current: ' . $counts[ Zinn_Translate_Store::STATUS_CURRENT ] );
		WP_CLI::log( 'rows stale:   ' . $counts[ Zinn_Translate_Store::STATUS_STALE ] );
		WP_CLI::log( 'rows edited:  ' . $counts[ Zinn_Translate_Store::STATUS_OVERRIDE ] );
		WP_CLI::log( 'outstanding:  ' . Zinn_Translate_Queue::outstanding_count() );
	}

	/**
	 * List what this site would send for translation.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-translate inventory --format=count
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function inventory( $args, $assoc_args ): void {
		unset( $args );
		$rows = array();
		foreach ( Zinn_Translate_Collector::inventory() as $document ) {
			$rows[] = array(
				'document' => (string) $document['doc_type'] . ':' . (string) $document['remote_id'],
				'fields'   => count( (array) $document['fields'] ),
				'url'      => (string) $document['url'],
			);
		}
		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'document', 'fields', 'url' )
		);
	}

	/**
	 * Translate what is outstanding, now.
	 *
	 * ⛔ Loops until nothing remains rather than doing one batch, because the point of
	 * running this by hand is to finish — a command that did one slice and reported success
	 * would be the truncating sweep `CLAUDE.md` §2.10 forbids, with a person watching it.
	 *
	 * ## OPTIONS
	 *
	 * [--max-passes=<n>]
	 * : Stop after this many batches. A safety rail for an interactive session, not a work cap.
	 * ---
	 * default: 200
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-translate run
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function run( $args, $assoc_args ): void {
		unset( $args );
		$queue  = new Zinn_Translate_Queue();
		$passes = max( 1, (int) ( $assoc_args['max-passes'] ?? 200 ) );
		$total  = 0;
		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$result = $queue->work();
			$total += (int) $result['translated'];
			WP_CLI::log( sprintf( 'pass %d: translated %d, remaining %d', $pass + 1, $result['translated'], $result['remaining'] ) );
			if ( 0 === (int) $result['remaining'] ) {
				break;
			}
			if ( 0 === (int) $result['translated'] ) {
				// ⛔ Nothing translated and work remaining means the provider refused. Going
				// round again would make the same failing call two hundred times and, on a
				// rate-limited key, get it blocked.
				$failure = Zinn_Translate_Status::last();
				WP_CLI::error( null === $failure ? 'nothing was translated and no reason was recorded' : $failure['message'] );
			}
		}
		WP_CLI::success( sprintf( 'translated %d strings', $total ) );
	}

	/**
	 * Re-read the site, retire what has gone, and push the inventory to Zinn Digital®.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-translate sweep
	 *
	 * @return void
	 */
	public function sweep(): void {
		$result = ( new Zinn_Translate_Queue() )->sweep();
		WP_CLI::success(
			sprintf(
				'documents %d, retired %d, registered with Zinn Digital® %d',
				$result['documents'],
				$result['retired'],
				$result['pushed']
			)
		);
	}
}
