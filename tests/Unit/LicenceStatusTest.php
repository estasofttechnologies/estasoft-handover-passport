<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\LicenceStatus;
use PHPUnit\Framework\TestCase;

final class LicenceStatusTest extends TestCase {

	public function test_status_follows_the_renewal_date(): void {
		$today = '2026-06-01';
		$this->assertSame( LicenceStatus::EXPIRED, LicenceStatus::compute( array( 'renews_on' => '2026-05-31' ), $today ) );
		$this->assertSame( LicenceStatus::SOON, LicenceStatus::compute( array( 'renews_on' => '2026-06-01' ), $today ) );
		$this->assertSame( LicenceStatus::SOON, LicenceStatus::compute( array( 'renews_on' => '2026-07-01' ), $today ) );
		$this->assertSame( LicenceStatus::ACTIVE, LicenceStatus::compute( array( 'renews_on' => '2026-07-02' ), $today ) );
	}

	public function test_missing_or_invalid_date_is_unknown_not_a_guess(): void {
		$this->assertSame( LicenceStatus::UNKNOWN, LicenceStatus::compute( array(), '2026-06-01' ) );
		$this->assertSame( LicenceStatus::UNKNOWN, LicenceStatus::compute( array( 'renews_on' => 'soon' ), '2026-06-01' ) );
	}

	public function test_cancelled_and_free_come_before_the_date(): void {
		$this->assertSame( LicenceStatus::CANCELLED, LicenceStatus::compute( array( 'renews_on' => '2020-01-01', 'cancelled' => 'yes' ), '2026-06-01' ) );
		$this->assertSame( LicenceStatus::FREE, LicenceStatus::compute( array( 'renews_on' => '2020-01-01', 'free' => 'yes', 'cancelled' => 'yes' ), '2026-06-01' ) );
	}

	public function test_the_lead_window_is_adjustable(): void {
		$this->assertSame( LicenceStatus::ACTIVE, LicenceStatus::compute( array( 'renews_on' => '2026-06-20' ), '2026-06-01', 7 ) );
		$this->assertSame( LicenceStatus::SOON, LicenceStatus::compute( array( 'renews_on' => '2026-06-20' ), '2026-06-01', 30 ) );
	}
}
