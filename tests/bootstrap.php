<?php
/**
 * PHPUnit bootstrap for the unit tests.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ESTASOFT_HP_DIR' ) ) {
	define( 'ESTASOFT_HP_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/stubs.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

Estasoft\HandoverPassport\Autoloader::register( 'Estasoft\\HandoverPassport\\Tests\\', __DIR__ . '/' );
Estasoft\HandoverPassport\Autoloader::register( 'Estasoft\\HandoverPassport\\', dirname( __DIR__ ) . '/src/' );
