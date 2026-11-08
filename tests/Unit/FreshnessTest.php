<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Freshness;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase {

	public function test_a_record_is_verified_until_its_interval_ends(): void {
		$this->assertSame( Freshness::VERIFIED, Freshness::state( '2026-01-01 00:00:00', '2025-01-01 00:00:00', 90, 14, '2026-03-31 23:59:59' ) );
	}

	public function test_it_is_due_for_the_due_window_then_stale(): void {
		// Interval ends 2026-04-01 00:00:00.
		$this->assertSame( Freshness::DUE, Freshness::state( '2026-01-01 00:00:00', '2025-01-01 00:00:00', 90, 14, '2026-04-01 00:00:00' ) );
		$this->assertSame( Freshness::DUE, Freshness::state( '2026-01-01 00:00:00', '2025-01-01 00:00:00', 90, 14, '2026-04-14 23:59:59' ) );
		$this->assertSame( Freshness::STALE, Freshness::state( '2026-01-01 00:00:00', '2025-01-01 00:00:00', 90, 14, '2026-04-15 00:00:00' ) );
	}

	public function test_a_never_verified_record_counts_from_its_creation(): void {
		$this->assertSame( Freshness::VERIFIED, Freshness::state( null, '2026-01-01 00:00:00', 30, 7, '2026-01-20 00:00:00' ) );
		$this->assertSame( Freshness::STALE, Freshness::state( null, '2026-01-01 00:00:00', 30, 7, '2026-03-01 00:00:00' ) );
	}

	public function test_verifying_again_restarts_the_clock(): void {
		$this->assertSame( Freshness::STALE, Freshness::state( '2025-01-01 00:00:00', '2025-01-01 00:00:00', 30, 7, '2026-01-01 00:00:00' ) );
		$this->assertSame( Freshness::VERIFIED, Freshness::state( '2025-12-30 00:00:00', '2025-01-01 00:00:00', 30, 7, '2026-01-01 00:00:00' ) );
	}

	public function test_labels_are_words(): void {
		$this->assertSame( 'Verified', Freshness::label( Freshness::VERIFIED ) );
		$this->assertSame( 'Due', Freshness::label( Freshness::DUE ) );
		$this->assertSame( 'Stale', Freshness::label( Freshness::STALE ) );
	}
}
