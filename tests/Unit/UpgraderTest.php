<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Install\Upgrader;
use PHPUnit\Framework\TestCase;

/**
 * When a site of a network is set up (WPI-5).
 */
final class UpgraderTest extends TestCase {

	public function test_a_network_site_that_was_never_set_up_waits_for_an_admin_visit(): void {
		$this->assertTrue( Upgrader::mustWait( 0, true, false, false ) );
	}

	public function test_an_admin_request_sets_the_site_up(): void {
		$this->assertFalse( Upgrader::mustWait( 0, true, true, false ) );
	}

	public function test_a_command_line_request_sets_the_site_up(): void {
		$this->assertFalse( Upgrader::mustWait( 0, true, false, true ) );
	}

	public function test_a_site_that_is_set_up_never_waits(): void {
		$this->assertFalse( Upgrader::mustWait( 1, true, false, false ) );
	}

	public function test_a_single_site_never_waits(): void {
		$this->assertFalse( Upgrader::mustWait( 0, false, false, false ) );
	}
}
