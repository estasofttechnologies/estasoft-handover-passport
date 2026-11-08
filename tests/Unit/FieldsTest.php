<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\ValidationError;

final class FieldsTest extends UnitTestCase {

	private function def( string $type, array $extra = array() ): array {
		return array( 'type' => $type, 'label' => 'Field' ) + $extra;
	}

	public function test_money_becomes_integer_minor_units(): void {
		$d = $this->def( 'money' );
		$this->assertSame( 123450, Fields::clean( $d, '1,234.50' ) );
		$this->assertSame( 123450, Fields::clean( $d, '1234.5' ) );
		$this->assertSame( 100, Fields::clean( $d, '1' ) );
		$this->assertSame( 5, Fields::clean( $d, '0.05' ) );
		$this->assertSame( 1999, Fields::clean( $d, '19,99' ) );
		$this->assertSame( '', Fields::clean( $d, '' ) );
	}

	public function test_money_rejects_text_and_odd_formats(): void {
		foreach ( array( 'free', '12.345', '1.2.3', '-5', '$10', '1,23,456' ) as $bad ) {
			$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'money' ), $bad ), $bad );
		}
	}

	public function test_money_display_round_trips(): void {
		$this->assertSame( '1234.50', Fields::moneyDisplay( 123450 ) );
		$this->assertSame( '0.05', Fields::moneyDisplay( 5 ) );
		$this->assertSame( '', Fields::moneyDisplay( '' ) );
	}

	public function test_dates_must_be_real(): void {
		$this->assertSame( '2026-02-28', Fields::clean( $this->def( 'date' ), ' 2026-02-28 ' ) );
		$this->assertSame( '', Fields::clean( $this->def( 'date' ), '' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'date' ), '2026-02-30' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'date' ), 'tomorrow' ) );
	}

	public function test_whole_numbers(): void {
		$this->assertSame( 12, Fields::clean( $this->def( 'int' ), '12' ) );
		$this->assertSame( '', Fields::clean( $this->def( 'int' ), '' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'int' ), '1.5' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'int' ), '-1' ) );
	}

	public function test_select_accepts_only_listed_choices(): void {
		$d = $this->def( 'select', array( 'options' => array( 'a' => 'A', 'b' => 'B' ) ) );
		$this->assertSame( 'a', Fields::clean( $d, 'a' ) );
		$this->assertSame( '', Fields::clean( $d, '' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $d, 'c' ) );
	}

	public function test_urls_allow_only_http_and_https(): void {
		$d = $this->def( 'url' );
		$this->assertSame( 'https://example.com/x', Fields::clean( $d, 'https://example.com/x' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $d, 'javascript:alert(1)' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $d, 'ftp://example.com' ) );
	}

	public function test_email(): void {
		$this->assertSame( 'a@b.co', Fields::clean( $this->def( 'email' ), 'a@b.co' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'email' ), 'not-an-email' ) );
	}

	public function test_text_is_limited_and_stripped(): void {
		$this->assertSame( 'Hello', Fields::clean( $this->def( 'text' ), '<b>Hello</b>' ) );
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'text', array( 'max' => 5 ) ), 'too long here' ) );
	}

	public function test_unknown_types_are_refused(): void {
		$this->assertThrowsClass( ValidationError::class, fn() => Fields::clean( $this->def( 'file' ), 'x' ) );
	}
}
