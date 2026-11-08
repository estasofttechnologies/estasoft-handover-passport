<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Export\ExportData;

/**
 * A small Passport in plain arrays for the pack tests.
 */
final class ExportFixture {

	/**
	 * A record as the services hand it over.
	 *
	 * @param int                  $id     ID.
	 * @param string               $kind   Kind.
	 * @param string               $title  Title.
	 * @param array<string, mixed> $fields Field values.
	 * @param array<string, mixed> $extra  Other record keys.
	 * @return array<string, mixed>
	 */
	public static function record( int $id, string $kind, string $title, array $fields = array(), array $extra = array() ): array {
		$fields = array_merge( array( 'legal_owner' => 'client' ), $fields );
		return array_merge(
			array(
				'id'            => $id,
				'kind'          => $kind,
				'title'         => $title,
				'sensitive'     => false,
				'fields'        => $fields,
				'owners'        => array(),
				'due_date'      => '',
				'legal_owner'   => 'client',
				'vis_client'    => true,
				'vis_emergency' => true,
			),
			$extra
		);
	}

	/**
	 * A person.
	 *
	 * @param int                  $id    ID.
	 * @param string               $name  Name.
	 * @param array<string, mixed> $extra Other keys.
	 * @return array<string, mixed>
	 */
	public static function person( int $id, string $name, array $extra = array() ): array {
		return array_merge(
			array(
				'id'            => $id,
				'name'          => $name,
				'organisation'  => 'Acme',
				'title'         => 'Lead',
				'email'         => strtolower( str_replace( ' ', '.', $name ) ) . '@example.test',
				'phone'         => '+100000' . $id,
				'urgent_method' => 'Call',
				'language'      => 'en',
				'timezone'      => 'UTC',
				'left_on'       => '',
				'note'          => '',
				'roles'         => array(),
				'share_flags'   => array( 'name' => true, 'role' => true, 'email' => true, 'phone' => true ),
			),
			$extra
		);
	}

	/**
	 * Passport data. Pass keys to replace parts of it.
	 *
	 * @param array<string, mixed> $with Replacement for records, people, roles, findings and so on.
	 */
	public static function data( array $with = array() ): ExportData {
		$parts = array_merge(
			array(
				'site'      => array( 'name' => 'Fixture Site', 'url' => 'https://fixture.example', 'client' => 'Fixture Client', 'purpose' => 'Sells things', 'status' => 'active', 'status_label' => 'Active', 'readiness' => array( 'ready' => 3, 'total' => 5 ), 'last_scan' => '2026-11-01 02:00:00', 'wp_version' => '6.8' ),
				'records'   => array(),
				'people'    => array(),
				'roles'     => array(),
				'runbooks'  => array(),
				'tests'     => array(),
				'findings'  => array(),
				'facts'     => array(),
				'checklist' => array(),
				'notes'     => '',
				'generated' => '2026-11-08 10:00:00',
			),
			$with
		);
		return new ExportData( $parts['site'], $parts['records'], $parts['people'], $parts['roles'], $parts['runbooks'], $parts['tests'], $parts['findings'], $parts['facts'], $parts['checklist'], $parts['notes'], $parts['generated'] );
	}

	/**
	 * Every string in a nested value, joined, so a test can look for a word anywhere in a pack.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( $value ): string {
		return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '';
	}
}
