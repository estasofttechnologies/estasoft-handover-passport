<?php
/**
 * The list of rules (HLT-4, FND-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Findings\Rules\DomainRules;
use Estasoft\HandoverPassport\Findings\Rules\HostingRules;
use Estasoft\HandoverPassport\Findings\Rules\LicenceRules;
use Estasoft\HandoverPassport\Findings\Rules\PeopleRules;
use Estasoft\HandoverPassport\Findings\Rules\SafetyRules;
use Estasoft\HandoverPassport\Findings\Rules\StackRules;
use Estasoft\HandoverPassport\Findings\Rules\SystemRules;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the shipped rules and lets developers add or remove rules through a filter.
 */
final class RuleCatalog {

	/**
	 * The shipped rules, before any filter.
	 *
	 * @return list<Rule>
	 */
	public static function shipped(): array {
		return array_merge(
			StackRules::all(),
			LicenceRules::all(),
			DomainRules::all(),
			HostingRules::all(),
			SafetyRules::all(),
			PeopleRules::all(),
			SystemRules::all()
		);
	}

	/**
	 * Every rule that is active in the catalog, keyed by ID.
	 *
	 * @return array<string, Rule>
	 */
	public static function all(): array {
		/**
		 * Filters the list of rules. Add a Rule object to add a rule; remove an entry to drop one.
		 * Entries that are not Rule objects, or that reuse an ID, are ignored.
		 *
		 * @param list<Rule> $rules Rules.
		 */
		$rules = apply_filters( 'estasoft_hp_rules', self::shipped() );
		$out   = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( $rule instanceof Rule && ! isset( $out[ $rule->id ] ) ) {
				$out[ $rule->id ] = $rule;
			}
		}
		ksort( $out );
		return $out;
	}
}
