<?php
/**
 * Read-only view of the Passport that rules look at (FND-1, FND-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Scan\SupportDates;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data, so rules can be tested without WordPress. {@see ContextFactory} builds the real one.
 */
final class Context {

	/**
	 * Constructor.
	 *
	 * @param string                                      $today               Today as Y-m-d in site time.
	 * @param string                                      $now                 Now as a UTC date-time.
	 * @param array<string, mixed>                        $facts               Facts of the latest finished scan, or empty.
	 * @param array<string, mixed>|null                   $last_scan           Latest scan row of any state.
	 * @param int|null                                    $scan_age_hours      Hours since the last finished scan.
	 * @param array<string, list<array<string, mixed>>>   $items               Live records by kind, unredacted.
	 * @param list<array<string, mixed>>                  $people              Live people with their roles.
	 * @param array<int, list<array<string, mixed>>>      $assets_by_person    What each person owns or can reach: item_id and relation.
	 * @param array<string, list<int>>                    $role_holders        Person IDs by role key, in order.
	 * @param list<array<string, mixed>>                  $runbooks            Live runbooks.
	 * @param list<array<string, mixed>>                  $tests               Restore tests, newest first.
	 * @param int                                         $snapshot_count      Number of snapshots.
	 * @param list<array<string, mixed>>                  $snapshot_mismatches Snapshots whose hash does not match.
	 * @param array<string, int>                          $thresholds          Thresholds from Settings.
	 * @param array<string, int|null>                     $recovery            Acceptable data loss and downtime in hours.
	 * @param array<string, int>                          $review_days         Review interval by section.
	 * @param int                                         $due_days            Days a record stays Due.
	 * @param array<string, mixed>                        $state               Small flags kept by other parts: reminder health, copy guard.
	 * @param SupportDates                                $support             Shipped support dates.
	 * @param list<array{slug: string, kind: string, service: string, plugin: string}> $service_hints Plugins that imply a service.
	 */
	public function __construct(
		public readonly string $today,
		public readonly string $now,
		public readonly array $facts = array(),
		public readonly ?array $last_scan = null,
		public readonly ?int $scan_age_hours = null,
		public readonly array $items = array(),
		public readonly array $people = array(),
		public readonly array $assets_by_person = array(),
		public readonly array $role_holders = array(),
		public readonly array $runbooks = array(),
		public readonly array $tests = array(),
		public readonly int $snapshot_count = 0,
		public readonly array $snapshot_mismatches = array(),
		public readonly array $thresholds = array(),
		public readonly array $recovery = array(),
		public readonly array $review_days = array(),
		public readonly int $due_days = 14,
		public readonly array $state = array(),
		private ?SupportDates $support = null,
		public readonly array $service_hints = array()
	) {
		$this->support ??= new SupportDates( array() );
	}

	/**
	 * Whether a scan has finished at least once.
	 */
	public function hasFacts(): bool {
		return array() !== $this->facts;
	}

	/**
	 * Live records of one kind.
	 *
	 * @param string $kind Kind key.
	 * @return list<array<string, mixed>>
	 */
	public function items( string $kind ): array {
		return $this->items[ $kind ] ?? array();
	}

	/**
	 * One field of a record.
	 *
	 * @param array<string, mixed> $record  Hydrated record.
	 * @param string               $field   Field key.
	 * @param string               $default Default.
	 */
	public static function field( array $record, string $field, string $default = '' ): string {
		$value = $record['fields'][ $field ] ?? $default;
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * People linked to a record through an ownership field.
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 * @param string               $field  Field key such as payer.
	 * @return list<int>
	 */
	public static function owners( array $record, string $field ): array {
		return array_values( array_map( 'intval', (array) ( $record['owners'][ $field ] ?? array() ) ) );
	}

	/**
	 * A threshold from Settings.
	 *
	 * @param string $key     Threshold key.
	 * @param int    $default Default when missing.
	 */
	public function threshold( string $key, int $default ): int {
		return isset( $this->thresholds[ $key ] ) ? (int) $this->thresholds[ $key ] : $default;
	}

	/**
	 * A value from the facts by dotted path.
	 *
	 * @param string $path    For example "core.php_version".
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function fact( string $path, $default = null ) {
		$value = $this->facts;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/**
	 * Installed plugins from the facts, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function plugins(): array {
		return (array) $this->fact( 'plugins', array() );
	}

	/**
	 * Installed themes from the facts, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function themes(): array {
		return (array) $this->fact( 'themes', array() );
	}

	/**
	 * Slugs of everything installed, lower case.
	 *
	 * @return array<string, true>
	 */
	public function installedSlugs(): array {
		return array_fill_keys( array_map( 'strtolower', array_merge( array_keys( $this->plugins() ), array_keys( $this->themes() ) ) ), true );
	}

	/**
	 * Host of the site address.
	 */
	public function siteHost(): string {
		$host = wp_parse_url( (string) $this->fact( 'core.home_url', (string) $this->fact( 'core.site_url', '' ) ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * A person by ID.
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
	 * Shipped support dates.
	 */
	public function support(): SupportDates {
		return $this->support ?? new SupportDates( array() );
	}

	/**
	 * A small flag kept by another part of the plugin.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function state( string $key, $default = null ) {
		return $this->state[ $key ] ?? $default;
	}
}
