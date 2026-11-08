<?php
/**
 * Shipped support dates (STK-6, FND-6).
 *
 * Check every row against php.net and wordpress.org before each release and update
 * "version" and "reviewed" when you do. Passport says so on screen when this table is
 * more than 12 months old.
 *
 * @package Estasoft\HandoverPassport
 */

defined( 'ABSPATH' ) || exit;

return array(
	'version'  => 1,
	'reviewed' => '2026-11-08',
	'php'      => array(
		// Branch => last day of security support.
		'5.6' => '2018-12-31',
		'7.0' => '2018-12-03',
		'7.1' => '2019-12-01',
		'7.2' => '2020-11-30',
		'7.3' => '2021-12-06',
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	),
	// WordPress publishes no fixed end dates per branch. Add a row only after confirming it on wordpress.org.
	'wordpress' => array(),
);
