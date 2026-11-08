<?php
/**
 * Makes the content of a pack, once, for every format and for snapshots (EXP-3, EXP-4, HND-2, HOK-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Domain\Sensitivity;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Security\SecretGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The content is a plain array. Sensitive records and fields are removed, and the secret guard runs, after every
 * filter that a developer may add, so a filter cannot weaken either.
 */
final class ContentBuilder {

	public const SCHEMA         = 'estasoft-handover-passport';
	public const SCHEMA_VERSION = '1';

	/**
	 * Constructor.
	 *
	 * @param SecretGuard $guard          Secret guard.
	 * @param string      $plugin_version Version written into the content.
	 */
	public function __construct( private SecretGuard $guard, private string $plugin_version = '' ) {}

	/**
	 * Build the content of a pack.
	 *
	 * @param Profile       $profile Profile.
	 * @param ExportData    $data    Passport data.
	 * @param ExportOptions $options Rights and ticked records.
	 * @return array<string, mixed>
	 * @throws ExportRefused When the person may not make this pack.
	 * @throws SecretFound   When a value looks like a secret.
	 */
	public function build( Profile $profile, ExportData $data, ExportOptions $options ): array {
		if ( $profile->needsSensitiveCapability() && ! $options->may_see_sensitive ) {
			throw new ExportRefused( __( 'You need the Sensitive capability to make this pack.', 'estasoft-handover-passport' ) );
		}
		$builder  = new Sections( $profile, $data, $options );
		$sections = $builder->build();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estasoft_hp_export_sections', $sections, $profile );
			$sections = is_array( $filtered ) ? array_values( $filtered ) : $sections;
		}
		// Everything below runs after the filter (HOK-1).
		$sections  = $this->enforce( $sections, $profile, $data, $options );
		$sensitive = $this->sensitiveIncluded( $sections, $data );

		$content = array(
			'schema'             => self::SCHEMA,
			'schema_version'     => self::SCHEMA_VERSION,
			'plugin_version'     => $this->plugin_version,
			'profile'            => array( 'key' => $profile->key, 'label' => $profile->label, 'audience' => $profile->audience ),
			'generated_at'       => $data->generated,
			'site'               => array(
				'name'    => (string) ( $data->site['name'] ?? '' ),
				'url'     => (string) ( $data->site['url'] ?? '' ),
				'client'  => (string) ( $data->site['client'] ?? '' ),
				'purpose' => (string) ( $data->site['purpose'] ?? '' ),
				'status'  => (string) ( $data->site['status'] ?? '' ),
			),
			'notes'              => $profile->includesNotes() ? $data->notes : '',
			'sections'           => $sections,
			'sensitive_included' => $sensitive,
			'left_out'           => $builder->leftOut(),
		);
		$this->guardContent( $content );
		return $content;
	}

	/**
	 * Remove what this pack may not hold: Sensitive records the person did not tick, and Sensitive field values.
	 *
	 * @param list<array<string, mixed>> $sections Chapters, possibly changed by a filter.
	 * @param Profile                    $profile  Profile.
	 * @param ExportData                 $data     Passport data.
	 * @param ExportOptions              $options  Rights.
	 * @return list<array<string, mixed>>
	 */
	private function enforce( array $sections, Profile $profile, ExportData $data, ExportOptions $options ): array {
		$sensitive_ids = array();
		foreach ( $data->records as $record ) {
			if ( Sensitivity::isSensitive( $record ) ) {
				$sensitive_ids[ (int) $record['id'] ] = true;
			}
		}
		$out = array();
		foreach ( $sections as $section ) {
			$groups = array();
			foreach ( (array) ( $section['groups'] ?? array() ) as $group ) {
				$kept = array();
				foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
					$id = $this->recordId( (string) ( $item['ref'] ?? '' ) );
					if ( null !== $id && isset( $sensitive_ids[ $id ] ) && ! $options->allowsSensitiveRecord( $profile, $id ) ) {
						continue;
					}
					$finding = $this->findingFor( (string) ( $item['ref'] ?? '' ), $data );
					if ( null !== $finding && ! $data->findingVisible( $finding, $profile, $options ) ) {
						continue;
					}
					if ( isset( $item['kind'] ) && ! ( $options->allowsSensitiveFields( $profile ) || ( null !== $id && isset( $sensitive_ids[ $id ] ) ) ) ) {
						foreach ( Sensitivity::fieldKeys( (string) $item['kind'] ) as $key ) {
							unset( $item['values'][ $key ] );
						}
					}
					$kept[] = $item;
				}
				$defined = array_flip( array_map( static fn( $f ): string => (string) ( $f['key'] ?? '' ), (array) ( $group['fields'] ?? array() ) ) );
				foreach ( $kept as $n => $item ) {
					$kept[ $n ]['values'] = array_intersect_key( (array) ( $item['values'] ?? array() ), $defined );
				}
				$group['items']  = $kept;
				$group['fields'] = $this->fieldsStillUsed( (array) ( $group['fields'] ?? array() ), $kept );
				if ( array() !== $kept ) {
					$groups[] = $group;
				}
			}
			$section['groups'] = $groups;
			if ( array() !== $groups ) {
				$out[] = $section;
			}
		}
		return $out;
	}

	/**
	 * Drop the definition of a Sensitive field that no remaining item holds.
	 *
	 * @param list<array<string, mixed>> $fields Field definitions.
	 * @param list<array<string, mixed>> $items  Items kept.
	 * @return list<array<string, mixed>>
	 */
	private function fieldsStillUsed( array $fields, array $items ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['sensitive'] ) ) {
				$used = false;
				foreach ( $items as $item ) {
					$used = $used || array_key_exists( (string) $field['key'], (array) ( $item['values'] ?? array() ) );
				}
				if ( ! $used ) {
					continue;
				}
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Sensitive records that are in the final content, by title only (EXP-4).
	 *
	 * @param list<array<string, mixed>> $sections Chapters after enforcement.
	 * @param ExportData                 $data     Passport data.
	 * @return list<array{ref: string, title: string}>
	 */
	private function sensitiveIncluded( array $sections, ExportData $data ): array {
		$sensitive = array();
		foreach ( $data->records as $record ) {
			if ( Sensitivity::isSensitive( $record ) ) {
				$sensitive[ (int) $record['id'] ] = (string) $record['title'];
			}
		}
		$out = array();
		foreach ( $sections as $section ) {
			foreach ( (array) $section['groups'] as $group ) {
				foreach ( (array) $group['items'] as $item ) {
					$id = $this->recordId( (string) ( $item['ref'] ?? '' ) );
					if ( null !== $id && isset( $sensitive[ $id ] ) ) {
						$out[ 'item:' . $id ] = array( 'ref' => 'item:' . $id, 'title' => $sensitive[ $id ] );
					}
				}
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * The finding a reference such as finding:7 points to, or null when it points to something else.
	 *
	 * @param string     $ref  Reference.
	 * @param ExportData $data Passport data.
	 * @return array<string, mixed>|null
	 */
	private function findingFor( string $ref, ExportData $data ): ?array {
		if ( 1 !== preg_match( '/^finding:(\d+)$/', $ref, $m ) ) {
			return null;
		}
		return $data->finding( (int) $m[1] );
	}

	/**
	 * The record number in a reference such as item:12, or null.
	 *
	 * @param string $ref Reference.
	 */
	private function recordId( string $ref ): ?int {
		return 1 === preg_match( '/^item:(\d+)$/', $ref, $m ) ? (int) $m[1] : null;
	}

	/**
	 * Run the secret guard over the whole content, naming the chapter and item in the message and never the value.
	 *
	 * @param array<string, mixed> $content Content.
	 * @throws SecretFound When a value looks like a secret.
	 */
	private function guardContent( array $content ): void {
		foreach ( (array) $content['sections'] as $section ) {
			foreach ( (array) $section['groups'] as $group ) {
				foreach ( (array) $group['items'] as $item ) {
					try {
						$this->guard->assertClean( (array) $item['values'] );
						$this->guard->assertClean( (string) $item['title'] );
					} catch ( SecretFound $e ) {
						throw new SecretFound( (string) $section['title'] . ' > ' . (string) $item['title'], $e->patternId() );
					}
				}
			}
		}
		foreach ( array( 'notes', 'site', 'left_out' ) as $key ) {
			try {
				$this->guard->assertClean( $content[ $key ] );
			} catch ( SecretFound $e ) {
				throw new SecretFound( 'notes' === $key ? __( 'Notes to the successor', 'estasoft-handover-passport' ) : $key, $e->patternId() );
			}
		}
	}
}
