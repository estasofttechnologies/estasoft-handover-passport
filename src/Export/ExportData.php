<?php
/**
 * Everything a pack is made from, as plain arrays, so the rules can be tested without WordPress (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Domain\Sensitivity;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only bundle of the current Passport.
 */
final class ExportData {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>             $site      name, url, client, purpose, status, readiness (ready, total), last_scan, wp_version.
	 * @param array<int, array<string, mixed>> $records   Hydrated records, unredacted.
	 * @param array<int, array<string, mixed>> $people    Hydrated people with roles (list of role keys) and share_flags.
	 * @param array<string, array<int, int>>   $roles     Role key to ordered person IDs.
	 * @param array<int, array<string, mixed>> $runbooks  Runbooks with their current content.
	 * @param array<int, array<string, mixed>> $tests     Restore tests, newest first.
	 * @param array<int, array<string, mixed>> $findings  Open findings as presented for the current person.
	 * @param array<string, mixed>             $facts     Facts of the latest scan, redacted.
	 * @param array<int, array<string, mixed>> $checklist Handover checklist rows.
	 * @param string                           $notes     Notes to the successor.
	 * @param string                           $generated UTC time the data was read.
	 */
	public function __construct(
		public readonly array $site = array(),
		public readonly array $records = array(),
		public readonly array $people = array(),
		public readonly array $roles = array(),
		public readonly array $runbooks = array(),
		public readonly array $tests = array(),
		public readonly array $findings = array(),
		public readonly array $facts = array(),
		public readonly array $checklist = array(),
		public readonly string $notes = '',
		public readonly string $generated = ''
	) {
		$known     = array();
		$sensitive = array();
		foreach ( $records as $record ) {
			$known[ (int) $record['id'] ] = true;
			if ( Sensitivity::isSensitive( $record ) ) {
				$sensitive[ (int) $record['id'] ] = true;
			}
		}
		$this->known_ids     = $known;
		$this->sensitive_ids = $sensitive;
	}

	/**
	 * Records in the Passport, by ID.
	 *
	 * @var array<int, true>
	 */
	private array $known_ids;

	/**
	 * Records marked Sensitive, by ID. This does not depend on who is looking.
	 *
	 * @var array<int, true>
	 */
	private array $sensitive_ids;

	/**
	 * Whether a finding may go into a pack.
	 *
	 * A finding about a Sensitive record names the record, so it goes in only when the record does. A finding about a
	 * record that is gone stays out too, because the record may have been Sensitive. The finding's own "sensitive" flag
	 * depends on the person reading, so packs never rely on it alone.
	 *
	 * @param array<string, mixed> $finding A finding.
	 * @param Profile              $profile Pack profile.
	 * @param ExportOptions        $options Rights and ticked records.
	 */
	public function findingVisible( array $finding, Profile $profile, ExportOptions $options ): bool {
		if ( ! empty( $finding['sensitive'] ) ) {
			return false;
		}
		$id = (int) ( $finding['subject_id'] ?? 0 );
		if ( 'item' !== (string) ( $finding['subject_type'] ?? '' ) || $id <= 0 ) {
			return true;
		}
		if ( ! isset( $this->known_ids[ $id ] ) ) {
			return false;
		}
		return ! isset( $this->sensitive_ids[ $id ] ) || $options->allowsSensitiveRecord( $profile, $id );
	}

	/**
	 * A finding by ID, or null.
	 *
	 * @param int $id Finding ID.
	 * @return array<string, mixed>|null
	 */
	public function finding( int $id ): ?array {
		foreach ( $this->findings as $finding ) {
			if ( (int) $finding['id'] === $id ) {
				return $finding;
			}
		}
		return null;
	}

	/**
	 * A person by ID, or null.
	 *
	 * @param int $id Person ID.
	 * @return array<string, mixed>|null
	 */
	public function person( int $id ): ?array {
		foreach ( $this->people as $person ) {
			if ( (int) $person['id'] === $id ) {
				return $person;
			}
		}
		return null;
	}

	/**
	 * Date of the latest restore test that passed, or an empty string.
	 */
	public function lastPass(): string {
		$best = '';
		foreach ( $this->tests as $test ) {
			if ( 'pass' === (string) ( $test['result'] ?? '' ) && (string) $test['test_date'] > $best ) {
				$best = (string) $test['test_date'];
			}
		}
		return $best;
	}
}
