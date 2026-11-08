<?php
/**
 * Shared builders for the rule tests.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\RuleCatalog;
use Estasoft\HandoverPassport\Scan\SupportDates;
use Estasoft\HandoverPassport\Support\Clock;

/**
 * Builds a healthy Context, lets a test change one thing, and runs one rule.
 */
abstract class RuleTestCase extends UnitTestCase {

	protected const TODAY = '2026-11-08';

	/**
	 * Freeze the clock for every test.
	 */
	protected function setUp(): void {
		Clock::freeze( self::TODAY . ' 10:00:00' );
	}

	/**
	 * Release the clock.
	 */
	protected function tearDown(): void {
		Clock::freeze( null );
	}

	/**
	 * Facts of a scan that found nothing to report.
	 *
	 * @param array<string, mixed> $over Replacements for top-level keys.
	 * @return array<string, mixed>
	 */
	protected function facts( array $over = array() ): array {
		return array_merge(
			array(
				'core'    => array(
					'php_version'   => '8.3.10',
					'wp_version'    => '6.8.1',
					'home_url'      => 'https://example.com',
					'site_url'      => 'https://example.com',
					'https'         => true,
					'cron_disabled' => false,
				),
				'plugins' => array(),
				'themes'  => array(),
				'users'   => array( 'admins' => array() ),
				'gaps'    => array(),
			),
			$over
		);
	}

	/**
	 * A Context where nothing is wrong. Pass constructor arguments by name to change them.
	 *
	 * @param array<string, mixed> $over Named constructor arguments.
	 */
	protected function ctx( array $over = array() ): Context {
		$args = array_merge(
			array(
				'today'          => self::TODAY,
				'now'            => self::TODAY . ' 10:00:00',
				'facts'          => $this->facts(),
				'last_scan'      => array( 'status' => 'finished' ),
				'scan_age_hours' => 1,
				'support'        => new SupportDates(
					array(
						'version'   => 1,
						'reviewed'  => '2026-11-01',
						'php'       => array( '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31' ),
						'wordpress' => array( '6.8' => '2027-06-30' ),
					)
				),
			),
			$over
		);
		return new Context( ...$args );
	}

	/**
	 * A hydrated record as the Context holds it.
	 *
	 * @param int                  $id     Record ID.
	 * @param string               $title  Title.
	 * @param array<string, mixed> $fields Field values.
	 * @param array<string, mixed> $extra  Other record columns and owners.
	 * @return array<string, mixed>
	 */
	protected function record( int $id, string $title, array $fields = array(), array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'title'       => $title,
				'subject_key' => $title,
				'fields'      => $fields,
				'owners'      => array(),
				'legal_owner' => 'client',
				'freshness'   => 'verified',
				'verified_at' => self::TODAY . ' 09:00:00',
				'created_at'  => '2026-01-01 00:00:00',
			),
			$extra
		);
	}

	/**
	 * A person as the Context holds it.
	 *
	 * @param int                  $id    Person ID.
	 * @param string               $name  Name.
	 * @param array<string, mixed> $extra Other columns.
	 * @return array<string, mixed>
	 */
	protected function person( int $id, string $name, array $extra = array() ): array {
		return array_merge(
			array(
				'id'            => $id,
				'name'          => $name,
				'email'         => strtolower( $name ) . '@example.com',
				'urgent_method' => 'Phone',
				'active'        => 1,
				'left_on'       => '',
				'roles'         => array(),
				'freshness'     => 'verified',
				'verified_at'   => self::TODAY . ' 09:00:00',
			),
			$extra
		);
	}

	/**
	 * A runbook as the Context holds it.
	 *
	 * @param int                  $id    Runbook ID.
	 * @param array<string, mixed> $extra Columns to change.
	 * @return array<string, mixed>
	 */
	protected function runbook( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'             => $id,
				'title'          => 'Restore the site',
				'template_key'   => '',
				'restore_scope'  => '',
				'review_days'    => 0,
				'last_reviewed'  => self::TODAY . ' 08:00:00',
				'last_rehearsed' => '2026-10-01',
				'created_at'     => '2026-01-01 00:00:00',
			),
			$extra
		);
	}

	/**
	 * The candidates one rule produces, or null when the rule could not check.
	 *
	 * @param string  $id Rule ID.
	 * @param Context $c  Context.
	 * @return list<Candidate>|null
	 */
	protected function run( string $id, Context $c ): ?array {
		$rules = RuleCatalog::all();
		$this->assertArrayHasKey( $id, $rules, 'Rule is in the catalogue' );
		return $rules[ $id ]->evaluate( $c );
	}

	/**
	 * Subject IDs of the candidates a rule produces.
	 *
	 * @param string  $id Rule ID.
	 * @param Context $c  Context.
	 * @return list<int|string>
	 */
	protected function subjects( string $id, Context $c ): array {
		$out = $this->run( $id, $c );
		$this->assertNotNull( $out, $id . ' could be checked' );
		return array_map( static fn( Candidate $x ) => '' !== $x->subject_key ? $x->subject_key : $x->subject_id, $out );
	}

	/**
	 * The sentence a rule shows for its first candidate.
	 *
	 * @param string  $id Rule ID.
	 * @param Context $c  Context.
	 */
	protected function sentence( string $id, Context $c ): string {
		$out = $this->run( $id, $c );
		$this->assertNotEmpty( $out, $id . ' produced a candidate' );
		$text = RuleCatalog::all()[ $id ]->message( $out[0]->values );
		$this->assertSame( 0, preg_match( '/\b(safe|secure|compliant|compliance|protected|healthy)\b/i', $text ), $id . ' makes no claim of safety: ' . $text );
		return $text;
	}

	/**
	 * Assert a rule is silent on this Context.
	 *
	 * @param string  $id Rule ID.
	 * @param Context $c  Context.
	 */
	protected function assertQuiet( string $id, Context $c ): void {
		$this->assertSame( array(), $this->run( $id, $c ), $id . ' stays quiet' );
	}

	/**
	 * Assert a rule says it could not check.
	 *
	 * @param string  $id Rule ID.
	 * @param Context $c  Context.
	 */
	protected function assertNotChecked( string $id, Context $c ): void {
		$this->assertNull( $this->run( $id, $c ), $id . ' is Not checked' );
	}
}
