<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Security\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * The five capability names.
 */
final class CapabilitiesTest extends TestCase {

	public function test_the_key_list_matches_the_described_list(): void {
		$this->assertSame( array_keys( Capabilities::all() ), Capabilities::keys() );
	}

	public function test_there_are_five_names_and_each_starts_with_the_plugin_prefix(): void {
		$this->assertCount( 5, Capabilities::keys() );
		foreach ( Capabilities::keys() as $name ) {
			$this->assertSame( 0, strpos( $name, 'estasoft_hp_' ) );
		}
	}
}
