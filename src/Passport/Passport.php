<?php
/**
 * The site-level Passport record (PRT-1, PRT-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Passport;

defined( 'ABSPATH' ) || exit;

/**
 * One Passport per site, kept in a single non-autoloaded option.
 */
class Passport {

	public const OPTION = 'estasoft_hp_passport';

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_HANDED = 'handed_over';

	/**
	 * Cached values.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Default values for a new Passport.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'site_name'       => (string) get_option( 'blogname', '' ),
			'client_name'     => '',
			'purpose'         => '',
			'status'          => self::STATUS_DRAFT,
			'logo_id'         => 0,
			'brand_color'     => '#2f4858',
			'successor_notes' => '',
			'setup'           => array(
				'done'    => array(),
				'skipped' => array(),
				'role'    => '',
				'type'    => '',
			),
		);
	}

	/**
	 * All values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * One value.
	 *
	 * @param string $key     Field name.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$all = $this->all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Save values over the current ones.
	 *
	 * @param array<string, mixed> $changes Sanitised changes.
	 */
	public function update( array $changes ): void {
		$merged = array_replace( $this->all(), $changes );
		update_option( self::OPTION, $merged, false );
		$this->cache = null;
	}

	/**
	 * Create the Passport on first use (PRT-1).
	 */
	public function ensure(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Labels for the three statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statusLabels(): array {
		return array(
			self::STATUS_DRAFT  => __( 'Draft', 'estasoft-handover-passport' ),
			self::STATUS_ACTIVE => __( 'Active', 'estasoft-handover-passport' ),
			self::STATUS_HANDED => __( 'Handed over', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Plain label for the current status.
	 */
	public function statusLabel(): string {
		$labels = self::statusLabels();
		return $labels[ (string) $this->get( 'status', self::STATUS_DRAFT ) ] ?? $labels[ self::STATUS_DRAFT ];
	}
}
