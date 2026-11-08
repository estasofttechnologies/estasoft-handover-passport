<?php
/**
 * Runs a scan: collect, compare with the last one, log the differences (SCN-1 to SCN-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Scan;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\ScanRepository;
use Estasoft\HandoverPassport\Services\RecordService;
use Estasoft\HandoverPassport\Settings\Settings;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One scan at a time, never longer than the configured limit.
 */
class ScanService {

	public const LOCK_OPTION = 'estasoft_hp_scan_lock';
	public const LOCK_TTL    = 120;

	/**
	 * Constructor.
	 *
	 * @param Collector      $collector Fact collector.
	 * @param ScanRepository $scans     Scan store.
	 * @param ChangeLog      $log       Change log.
	 * @param RecordService  $records   Record service.
	 * @param Settings       $settings  Settings.
	 */
	public function __construct(
		private Collector $collector,
		private ScanRepository $scans,
		private ChangeLog $log,
		private RecordService $records,
		private Settings $settings
	) {}

	/**
	 * Run a scan.
	 *
	 * @param string $trigger "scheduled", "manual", "cli" or "install".
	 * @return array{status: string, scan_id: int, changes: int, found: int, gaps: list<string>, baseline: bool}
	 */
	public function run( string $trigger = 'manual' ): array {
		// A scan never runs while a visitor's page is being built (SCN-6).
		$allowed = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $allowed ) {
			return array( 'status' => 'refused', 'scan_id' => 0, 'changes' => 0, 'found' => 0, 'gaps' => array(), 'baseline' => false );
		}
		if ( ! $this->acquireLock() ) {
			return array( 'status' => 'busy', 'scan_id' => 0, 'changes' => 0, 'found' => 0, 'gaps' => array(), 'baseline' => false );
		}

		$limit   = max( 5, min( 120, (int) $this->settings->get( 'scan_limit', 20 ) ) );
		$started = Clock::sql();
		$scan_id = $this->scans->insert(
			array(
				'trigger_kind' => $trigger,
				'status'       => 'running',
				'started_at'   => $started,
				'facts'        => array(),
				'differences'  => array(),
			)
		);

		try {
			$previous = $this->scans->latestDone();
			$facts    = $this->collector->collect( time() + $limit );
			$changes  = 0;
			$diffs    = array();

			if ( null !== $previous ) {
				$diffs   = Differ::diff( (array) $previous['facts'], $facts );
				$changes = $this->logDifferences( $diffs, (string) $previous['started_at'] );
			}

			$this->syncDetectedRecords( $facts );

			$this->scans->update(
				$scan_id,
				array(
					'status'      => 'done',
					'finished_at' => Clock::sql(),
					'facts'       => $facts,
					'differences' => $diffs,
				)
			);
			$this->scans->prune( 30 );

			/**
			 * Fires after a scan finishes. The facts passed here have Sensitive values removed.
			 *
			 * @param int                  $scan_id Scan ID.
			 * @param array<string, mixed> $facts   Redacted facts.
			 * @param int                  $changes Number of changes logged.
			 */
			do_action( 'estasoft_hp_scan_completed', $scan_id, self::redact( $facts ), $changes );

			return array(
				'status'  => 'done',
				'scan_id' => $scan_id,
				'changes' => $changes,
				'found'   => count( $diffs ),
				'gaps'    => array_keys( (array) ( $facts['gaps'] ?? array() ) ),
				'baseline' => null === $previous,
			);
		} catch ( \Throwable $e ) {
			$this->scans->update( $scan_id, array( 'status' => 'failed', 'finished_at' => Clock::sql(), 'error_code' => 'estasoft_hp_scan_failed' ) );
			return array( 'status' => 'failed', 'scan_id' => $scan_id, 'changes' => 0, 'found' => 0, 'gaps' => array(), 'baseline' => false );
		} finally {
			$this->releaseLock();
		}
	}

	/**
	 * Facts of the latest finished scan, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	public function latestFacts(): array {
		$row = $this->scans->latestDone();
		return null === $row ? array() : (array) $row['facts'];
	}

	/**
	 * The latest scan row of any state.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest(): ?array {
		return $this->scans->latest();
	}

	/**
	 * Hours since the last finished scan, or null when none ever finished.
	 */
	public function hoursSinceLast(): ?int {
		$row = $this->scans->latestDone();
		if ( null === $row || empty( $row['finished_at'] ) ) {
			return null;
		}
		$then = strtotime( (string) $row['finished_at'] . ' UTC' );
		return false === $then ? null : (int) floor( ( Clock::now()->getTimestamp() - $then ) / HOUR_IN_SECONDS );
	}

	/**
	 * Remove Sensitive facts for viewers who may not see them (SEC-3).
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	public static function redact( array $facts ): array {
		unset( $facts['sensitive'] );
		if ( isset( $facts['users']['admins'] ) && is_array( $facts['users']['admins'] ) ) {
			foreach ( $facts['users']['admins'] as $i => $admin ) {
				unset( $facts['users']['admins'][ $i ]['email'] );
			}
		}
		return $facts;
	}

	/**
	 * Write the differences, skipping those a live capture already logged since the last scan.
	 *
	 * @param list<array<string, mixed>> $diffs Differences.
	 * @param string                     $since Start of the last scan, UTC.
	 * @return int Number written.
	 */
	private function logDifferences( array $diffs, string $since ): int {
		if ( array() === $diffs ) {
			return 0;
		}
		$known = array();
		foreach ( $this->log->repo()->search( array( 'since' => $since ), 0 ) as $entry ) {
			$known[ $entry['kind'] . '|' . $entry['subject_label'] ] = true;
		}
		$written = 0;
		$this->log->inBatch(
			function () use ( $diffs, $known, &$written ): void {
				foreach ( $diffs as $diff ) {
					$key = $diff['kind'] . '|' . mb_substr( (string) $diff['label'], 0, 255 );
					if ( isset( $known[ $key ] ) ) {
						continue;
					}
					$this->log->add(
						(string) $diff['kind'],
						(string) $diff['subject_type'],
						0,
						(string) $diff['label'],
						(array) $diff['before'],
						(array) $diff['after'],
						'',
						'scan',
						ChangeLog::SYSTEM,
						0
					);
					++$written;
				}
			}
		);
		return $written;
	}

	/**
	 * Keep the Detected domain and environment records in step with the site (SCN-8, PRT-6).
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function syncDetectedRecords( array $facts ): void {
		$core = (array) ( $facts['core'] ?? array() );
		$url  = (string) ( $core['site_url'] ?? '' );
		$host = '' === $url ? '' : strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return;
		}

		$items = $this->records->items();
		if ( null === $items->bySubject( 'domain', $host ) ) {
			$this->records->create( 'domain', array( 'domain' => $host, 'role' => 'primary' ), 'detected', 'scan' );
		}

		$environment = (string) ( $core['environment'] ?? 'production' );
		$environment = in_array( $environment, array( 'production', 'staging', 'development' ), true ) ? $environment : 'production';
		$existing    = $items->bySubject( 'environment', $environment );
		if ( null === $existing ) {
			$this->records->create( 'environment', array( 'name' => $environment, 'address' => $url ), 'detected', 'scan' );
		} elseif ( 'detected' === $existing['source'] && (string) ( $existing['data']['address'] ?? '' ) !== $url ) {
			$this->records->update( (int) $existing['id'], array( 'address' => $url ), 'scan' );
		}
	}

	/**
	 * Take the scan lock. A lock older than the TTL is treated as abandoned.
	 */
	private function acquireLock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $now - $held > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, (string) $now, false );
			return true;
		}
		return false;
	}

	/**
	 * Release the scan lock.
	 */
	private function releaseLock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
