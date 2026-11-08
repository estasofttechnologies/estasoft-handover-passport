<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Support\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase {

	public function test_canonical_form_ignores_key_order(): void {
		$a = array( 'b' => 1, 'a' => array( 'y' => 2, 'x' => 3 ) );
		$b = array( 'a' => array( 'x' => 3, 'y' => 2 ), 'b' => 1 );
		$this->assertSame( Json::canonical( $a ), Json::canonical( $b ) );
		$this->assertSame( Json::hash( $a ), Json::hash( $b ) );
	}

	public function test_lists_keep_their_order(): void {
		$this->assertNotSame( Json::hash( array( 1, 2, 3 ) ), Json::hash( array( 3, 2, 1 ) ) );
	}

	public function test_a_changed_value_changes_the_hash(): void {
		$this->assertNotSame( Json::hash( array( 'a' => 1 ) ), Json::hash( array( 'a' => 2 ) ) );
	}

	public function test_floats_are_written_as_text_so_hashes_are_stable(): void {
		$this->assertSame( '{"v":"1.5"}', Json::canonical( array( 'v' => 1.5 ) ) );
	}

	public function test_unicode_and_slashes_are_not_escaped(): void {
		$this->assertSame( '{"a":"مرحبا/x"}', Json::canonical( array( 'a' => 'مرحبا/x' ) ) );
	}

	public function test_decode_gives_an_empty_array_for_bad_input(): void {
		$this->assertSame( array(), Json::decode( '{broken' ) );
		$this->assertSame( array(), Json::decode( '' ) );
		$this->assertSame( array(), Json::decode( null ) );
		$this->assertSame( array( 'a' => 1 ), Json::decode( '{"a":1}' ) );
	}

	public function test_hash_is_sha256_hex(): void {
		$this->assertSame( 64, strlen( Json::hash( array( 'x' => 1 ) ) ) );
	}
}
