<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase {

	public function test_csv_cells_that_start_like_formulas_are_neutralised(): void {
		foreach ( array( '=SUM(A1)', '+1', '-1', '@cmd', "\t=x", "\r=x" ) as $cell ) {
			$out = Str::csvCell( $cell );
			$this->assertSame( "'", $out[0], $cell );
		}
		$this->assertSame( 'Plain text', Str::csvCell( 'Plain text' ) );
		$this->assertSame( '', Str::csvCell( '' ) );
	}

	public function test_lines_drop_blanks_and_trim(): void {
		$this->assertSame( array( 'a', 'b' ), Str::lines( " a \n\n b\r\n" ) );
	}

	public function test_truncate_counts_characters_not_bytes(): void {
		$this->assertSame( 'مرحبا', Str::truncate( 'مرحبا', 10 ) );
		$this->assertTrue( mb_strlen( Str::truncate( 'مرحبا بالعالم', 5 ) ) <= 6, 'Allows for a one-character ellipsis' );
	}

	public function test_fill_replaces_named_placeholders_only(): void {
		$this->assertSame( 'Hi Sam, from {other}', Str::fill( 'Hi {name}, from {other}', array( 'name' => 'Sam' ) ) );
	}

	public function test_slug(): void {
		$this->assertSame( 'my-site-name', Str::slug( 'My Site  Name!' ) );
	}
}
