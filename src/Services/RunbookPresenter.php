<?php
/**
 * A runbook ready to read: placeholders filled from the Passport (RUN-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Domain\RunbookSteps;
use Estasoft\HandoverPassport\Export\ExportDataLoader;
use Estasoft\HandoverPassport\Export\Placeholders;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored runbook, or one of its earlier versions, into the text a person reads on screen or on paper. A detail
 * that the Passport does not hold shows as "[name: not recorded]" and is listed, so a gap is never hidden.
 */
final class RunbookPresenter {

	/**
	 * Placeholder values, read once per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $values = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The values the placeholders stand for now. A Sensitive record never supplies one.
	 *
	 * @return array<string, string>
	 */
	public function values(): array {
		if ( null === $this->values ) {
			$this->values = Placeholders::values( ( new ExportDataLoader( $this->plugin ) )->load() );
		}
		return $this->values;
	}

	/**
	 * A runbook as text for reading.
	 *
	 * @param int      $id      Runbook ID.
	 * @param int|null $version Version number, or null for the current one.
	 * @return array<string, mixed>|null Null when the runbook or the version does not exist.
	 */
	public function present( int $id, ?int $version = null ): ?array {
		$runbook = $this->plugin->runbooks()->get( $id );
		if ( null === $runbook ) {
			return null;
		}
		$current = (int) $runbook['current_version'];
		$source  = $runbook;
		$number  = $current;
		$made_at = '';
		if ( null !== $version && $version !== $current ) {
			$found = null;
			foreach ( $this->plugin->runbooks()->versions( $id ) as $candidate ) {
				if ( (int) $candidate['version'] === $version ) {
					$found = $candidate;
					break;
				}
			}
			if ( null === $found ) {
				return null;
			}
			$source  = $found + $runbook;
			$number  = $version;
			$made_at = (string) $found['created_at'];
		}

		$values = $this->values();
		$raw    = array();
		$fill   = static function ( string $text ) use ( $values, &$raw ): string {
			$raw[] = $text;
			return Placeholders::fill( $text, $values );
		};
		$roles  = RunbookService::roles();

		$steps = array();
		foreach ( (array) $source['steps'] as $step ) {
			$steps[] = array(
				'text'    => $fill( (string) ( $step['text'] ?? '' ) ),
				'who'     => (string) ( $roles[ (string) ( $step['who'] ?? '' ) ] ?? '' ),
				'minutes' => (int) ( $step['minutes'] ?? 0 ),
				'link'    => (string) ( $step['link'] ?? '' ),
				'warning' => ! empty( $step['warning'] ),
			);
		}
		$prerequisites = array();
		foreach ( (array) $source['prerequisites'] as $row ) {
			$who             = (string) ( $row['who'] ?? '' );
			$prerequisites[] = array( 'what' => $fill( (string) ( $row['what'] ?? '' ) ), 'who' => (string) ( $roles[ $who ] ?? $who ) ); // A template names a role; a person may type a name instead.
		}
		$present         = array(
			'id'                => $id,
			'title'             => (string) $runbook['title'],
			'status'            => (string) $runbook['status'],
			'critical'          => ! empty( $runbook['critical'] ),
			'version'           => $number,
			'is_current'        => $number === $current,
			'version_made_at'   => $made_at,
			'change_note'       => (string) ( $source['change_note'] ?? '' ),
			'purpose'           => $fill( (string) $runbook['purpose'] ),
			'when_to_use'       => $fill( (string) $runbook['when_to_use'] ),
			'owner'             => (string) ( $roles[ (string) $runbook['owner_role'] ] ?? '' ),
			'restore_scope'     => (string) $runbook['restore_scope'],
			'estimated_minutes' => RunbookSteps::minutes( $steps ),
			'last_reviewed'     => is_string( $runbook['last_reviewed'] ) ? Dates::datePart( $runbook['last_reviewed'] ) : '',
			'last_rehearsed'    => is_string( $runbook['last_rehearsed'] ) ? Dates::datePart( $runbook['last_rehearsed'] ) : '',
			'prerequisites'     => $prerequisites,
			'steps'             => $steps,
			'verification'      => array_values( array_map( static fn( $line ): string => $fill( (string) $line ), (array) $source['verification'] ) ),
			'rollback_text'     => $fill( (string) ( $source['rollback_text'] ?? '' ) ),
		);
		$present['gaps'] = $this->gaps( $raw, $values );
		return $present;
	}

	/**
	 * Placeholders used in the texts that the Passport holds no value for.
	 *
	 * @param array<int, string>    $texts  Raw texts.
	 * @param array<string, string> $values Values.
	 * @return array<int, string>
	 */
	private function gaps( array $texts, array $values ): array {
		$missing = array();
		foreach ( $texts as $text ) {
			if ( 1 > preg_match_all( '/\{([a-z_]+)\}/', $text, $found ) ) {
				continue;
			}
			foreach ( $found[1] as $name ) {
				if ( array_key_exists( $name, Placeholders::names() ) && '' === ( $values[ $name ] ?? '' ) ) {
					$missing[ $name ] = true;
				}
			}
		}
		return array_keys( $missing );
	}
}
