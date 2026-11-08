<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Shared helpers for the unit tests.
 */
abstract class UnitTestCase extends TestCase {

	/**
	 * Assert that a callback throws an exception of a class.
	 *
	 * @param class-string $class   Expected exception class.
	 * @param callable     $fn      Code that should throw.
	 * @param string       $message Failure message.
	 */
	protected function assertThrowsClass( string $class, callable $fn, string $message = '' ): void {
		try {
			$fn();
		} catch ( Throwable $e ) {
			$this->assertInstanceOf( $class, $e, $message );
			return;
		}
		$this->fail( ( '' !== $message ? $message . ': ' : '' ) . 'Expected ' . $class . ' to be thrown.' );
	}
}
