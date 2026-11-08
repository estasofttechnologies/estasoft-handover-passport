<?php
/**
 * Builds the chapters of a pack from the Passport (EXP-1, EXP-3, EXP-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\Sensitivity;
use Estasoft\HandoverPassport\Services\PeopleService;

defined( 'ABSPATH' ) || exit;

/**
 * One chapter is a title, optional facts and one or more groups. A group is a table of items that share a set of
 * fields, so every format can turn it into a table, a card list, a CSV file or calendar entries.
 */
final class Sections {

	/**
	 * Record kinds in each chapter that is made of records.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const RECORD_CHAPTERS = array(
		'domains'           => array( Kinds::DOMAIN ),
		'dns'               => array( Kinds::ZONE ),
		'hosting'           => array( Kinds::HOST, Kinds::ENV, Kinds::LAYER ),
		'licences'          => array( Kinds::LICENCE ),
		'services'          => array( Kinds::SERVICE ),
		'backups'           => array( Kinds::BACKUP ),
		'urls'              => array( Kinds::URL ),
		'access'            => array( Kinds::ACCESS ),
		'assets'            => array( Kinds::ASSET ),
		'stack_notes'       => array( Kinds::STACKNOTE ),
		'incidents'         => array( Kinds::INCIDENT ),
		'emergency_hosting' => array( Kinds::HOST ),
		'emergency_domains' => array( Kinds::DOMAIN ),
		'emergency_backups' => array( Kinds::BACKUP ),
		'emergency_urls'    => array( Kinds::URL ),
	);

	/**
	 * Fields of the Emergency sheet, per kind, so a page stays one page.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EMERGENCY_FIELDS = array(
		Kinds::HOST   => array( 'provider', 'plan', 'support_address', 'support_hours', 'control_panel', 'account_holder' ),
		Kinds::DOMAIN => array( 'domain', 'registrar', 'expires_on', 'account_holder' ),
		Kinds::BACKUP => array( 'name', 'stored_at', 'offsite', 'can_restore', 'last_confirmed' ),
		Kinds::URL    => array( 'name', 'address', 'expected_status' ),
	);

	/**
	 * How many items of each emergency chapter fit on the page.
	 *
	 * @var array<string, int>
	 */
	private const EMERGENCY_LIMITS = array(
		'emergency_contacts' => 6,
		'emergency_hosting'  => 2,
		'emergency_domains'  => 4,
		'emergency_backups'  => 3,
		'emergency_runbooks' => 5,
		'emergency_urls'     => 6,
	);

	/**
	 * Notes about what was shortened or left out.
	 *
	 * @var array<int, string>
	 */
	private array $left_out = array();

	/**
	 * Placeholder values for this pack.
	 *
	 * @var array<string, string>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param Profile       $profile Profile.
	 * @param ExportData    $data    Passport data.
	 * @param ExportOptions $options Rights and ticked records.
	 */
	public function __construct( private Profile $profile, private ExportData $data, private ExportOptions $options ) {
		$this->values = Placeholders::values( $data, $profile );
	}

	/**
	 * Build the chapters the profile lists, in its order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function build(): array {
		$out = array();
		foreach ( $this->profile->sections as $key ) {
			$section = $this->section( $key );
			if ( null !== $section ) {
				$out[] = $section;
			}
		}
		return $out;
	}

	/**
	 * What was shortened or left out.
	 *
	 * @return array<int, string>
	 */
	public function leftOut(): array {
		return $this->left_out;
	}

	/**
	 * One chapter, or null when it has nothing to show.
	 *
	 * @param string $key Chapter key.
	 * @return array<string, mixed>|null
	 */
	private function section( string $key ): ?array {
		$title = Profiles::chapters()[ $key ] ?? $key;
		switch ( $key ) {
			case 'passport':
				return $this->passport( $title );
			case 'people':
				return $this->people( $title );
			case 'stack':
				return $this->stack( $title );
			case 'runbooks':
				return $this->runbooks( $title );
			case 'tests':
				return $this->tests( $title );
			case 'transfers':
				return $this->transfers( $title );
			case 'checklist':
				return $this->checklist( $title );
			case 'findings':
				return $this->findings( $title );
			case 'emergency_contacts':
				return $this->emergencyContacts( $title );
			case 'emergency_runbooks':
				return $this->emergencyRunbooks( $title );
		}
		if ( isset( self::RECORD_CHAPTERS[ $key ] ) ) {
			return $this->recordChapter( $key, $title );
		}
		return null;
	}

	/**
	 * The chapter about the site itself.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function passport( string $title ): array {
		$site      = $this->data->site;
		$readiness = (array) ( $site['readiness'] ?? array() );
		$fields    = array(
			$this->def( 'name', __( 'Site name', 'estasoft-handover-passport' ) ),
			$this->def( 'url', __( 'Address', 'estasoft-handover-passport' ) ),
			$this->def( 'client', __( 'Client', 'estasoft-handover-passport' ) ),
			$this->def( 'purpose', __( 'What the site is for', 'estasoft-handover-passport' ), 'textarea' ),
			$this->def( 'status', __( 'Passport status', 'estasoft-handover-passport' ) ),
			$this->def( 'readiness', __( 'Ready for a handover', 'estasoft-handover-passport' ) ),
			$this->def( 'wp_version', __( 'WordPress version when last scanned', 'estasoft-handover-passport' ) ),
			$this->def( 'last_scan', __( 'Last scan', 'estasoft-handover-passport' ), 'date' ),
		);
		$values    = array(
			'name'       => (string) ( $site['name'] ?? '' ),
			'url'        => (string) ( $site['url'] ?? '' ),
			'client'     => (string) ( $site['client'] ?? '' ),
			'purpose'    => (string) ( $site['purpose'] ?? '' ),
			'status'     => (string) ( $site['status_label'] ?? '' ),
			'readiness'  => isset( $readiness['total'] ) ? sprintf( /* translators: 1: the part that is done or clear, 2: the whole. */ __( '%1$d of %2$d', 'estasoft-handover-passport' ), (int) $readiness['ready'], (int) $readiness['total'] ) : '',
			'wp_version' => $this->profile->usesVisibilityFlags() ? '' : (string) ( $site['wp_version'] ?? '' ),
			'last_scan'  => $this->profile->usesVisibilityFlags() ? '' : substr( (string) ( $site['last_scan'] ?? '' ), 0, 10 ),
		);
		return $this->wrap( 'passport', $title, array( $this->group( 'site', $title, $fields, array( $this->item( 'site:0', (string) ( $site['name'] ?? $title ), $values ) ) ) ) );
	}

	/**
	 * The directory of people, following each person's sharing flags in a Client pack (PRV-7).
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function people( string $title ): ?array {
		$flags  = $this->profile->usesSharingFlags();
		$fields = array(
			$this->def( 'name', __( 'Name', 'estasoft-handover-passport' ) ),
			$this->def( 'organisation', __( 'Organisation', 'estasoft-handover-passport' ) ),
			$this->def( 'title', __( 'Title', 'estasoft-handover-passport' ) ),
			$this->def( 'roles', __( 'Roles', 'estasoft-handover-passport' ), 'lines' ),
			$this->def( 'email', __( 'Email', 'estasoft-handover-passport' ) ),
			$this->def( 'phone', __( 'Phone', 'estasoft-handover-passport' ) ),
			$this->def( 'urgent_method', __( 'How to reach them urgently', 'estasoft-handover-passport' ) ),
		);
		if ( ! $flags ) {
			$fields[] = $this->def( 'language', __( 'Language', 'estasoft-handover-passport' ) );
			$fields[] = $this->def( 'timezone', __( 'Time zone', 'estasoft-handover-passport' ) );
			$fields[] = $this->def( 'left_on', __( 'Left on', 'estasoft-handover-passport' ), 'date' );
			$fields[] = $this->def( 'note', __( 'Note', 'estasoft-handover-passport' ), 'textarea' );
		}
		$items = array();
		foreach ( $this->data->people as $person ) {
			if ( $flags && ( '' !== (string) ( $person['left_on'] ?? '' ) || empty( $person['share_flags']['name'] ) ) ) {
				continue;
			}
			$items[] = $this->item( 'person:' . (int) $person['id'], (string) $person['name'], $this->personValues( $person, $flags ) );
		}
		return array() === $items ? null : $this->wrap( 'people', $title, array( $this->group( 'people', $title, $fields, $this->sorted( $items ) ) ) );
	}

	/**
	 * What a pack may say about one person.
	 *
	 * @param array<string, mixed> $person Person.
	 * @param bool                 $flags  Whether the sharing flags apply.
	 * @return array<string, mixed>
	 */
	private function personValues( array $person, bool $flags ): array {
		$share = (array) ( $person['share_flags'] ?? array() );
		$role  = ! $flags || ! empty( $share['role'] );
		$mail  = ! $flags || ! empty( $share['email'] );
		$phone = ! $flags || ! empty( $share['phone'] );
		$roles = PeopleService::roles();
		return array(
			'name'          => (string) $person['name'],
			'organisation'  => $role ? (string) $person['organisation'] : '',
			'title'         => $role ? (string) $person['title'] : '',
			'roles'         => $role ? array_values( array_map( static fn( $r ): string => (string) ( $roles[ (string) $r ] ?? $r ), (array) ( $person['roles'] ?? array() ) ) ) : array(),
			'email'         => $mail ? (string) $person['email'] : '',
			'phone'         => $phone ? (string) $person['phone'] : '',
			'urgent_method' => $phone ? (string) $person['urgent_method'] : '',
			'language'      => (string) ( $person['language'] ?? '' ),
			'timezone'      => (string) ( $person['timezone'] ?? '' ),
			'left_on'       => (string) ( $person['left_on'] ?? '' ),
			'note'          => (string) ( $person['note'] ?? '' ),
		);
	}

	/**
	 * Plugins, themes and must-use plugins from the last scan.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function stack( string $title ): ?array {
		$facts = $this->data->facts;
		if ( array() === $facts ) {
			return null;
		}
		$yes_no = array( 'yes' => __( 'Yes', 'estasoft-handover-passport' ), 'no' => __( 'No', 'estasoft-handover-passport' ) );
		$fields = array(
			$this->def( 'name', __( 'Name', 'estasoft-handover-passport' ) ),
			$this->def( 'version', __( 'Version', 'estasoft-handover-passport' ) ),
			$this->def( 'active', __( 'Active', 'estasoft-handover-passport' ), 'select', $yes_no ),
			$this->def( 'update', __( 'Update available', 'estasoft-handover-passport' ) ),
			$this->def( 'author', __( 'Author', 'estasoft-handover-passport' ) ),
		);
		$groups = array();
		foreach ( array( 'plugins' => __( 'Plugins', 'estasoft-handover-passport' ), 'themes' => __( 'Themes', 'estasoft-handover-passport' ), 'mu_plugins' => __( 'Must-use plugins', 'estasoft-handover-passport' ) ) as $key => $label ) {
			$items = array();
			foreach ( (array) ( $facts[ $key ] ?? array() ) as $slug => $row ) {
				$row     = (array) $row;
				$items[] = $this->item(
					$key . ':' . $slug,
					(string) ( $row['name'] ?? $slug ),
					array(
						'name'    => (string) ( $row['name'] ?? $slug ),
						'version' => (string) ( $row['version'] ?? '' ),
						'active'  => 'mu_plugins' === $key || ! empty( $row['active'] ) ? 'yes' : 'no',
						'update'  => (string) ( $row['update'] ?? '' ),
						'author'  => (string) ( $row['author'] ?? '' ),
					)
				);
			}
			if ( array() !== $items ) {
				$groups[] = $this->group( $key, $label, $fields, $this->sorted( $items ) );
			}
		}
		return array() === $groups ? null : $this->wrap( 'stack', $title, $groups );
	}

	/**
	 * Published runbooks. A Client pack lists what each is for; the others carry the steps, with placeholders filled.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function runbooks( string $title ): ?array {
		$deep   = ! $this->profile->usesVisibilityFlags();
		$roles  = PeopleService::roles();
		$fields = array(
			$this->def( 'title', __( 'Runbook', 'estasoft-handover-passport' ) ),
			$this->def( 'purpose', __( 'What it is for', 'estasoft-handover-passport' ), 'textarea' ),
			$this->def( 'when_to_use', __( 'When to use it', 'estasoft-handover-passport' ), 'textarea' ),
			$this->def( 'owner_role', __( 'Who does it', 'estasoft-handover-passport' ) ),
			$this->def( 'estimated_minutes', __( 'Minutes', 'estasoft-handover-passport' ), 'int' ),
			$this->def( 'last_rehearsed', __( 'Last rehearsed', 'estasoft-handover-passport' ), 'date' ),
		);
		if ( $deep ) {
			$fields[] = $this->def( 'status', __( 'Status', 'estasoft-handover-passport' ) );
			$fields[] = $this->def( 'prerequisites', __( 'Before you start', 'estasoft-handover-passport' ), 'lines' );
			$fields[] = $this->def( 'steps', __( 'Steps', 'estasoft-handover-passport' ), 'lines' );
			$fields[] = $this->def( 'verification', __( 'How to know it worked', 'estasoft-handover-passport' ), 'lines' );
			$fields[] = $this->def( 'rollback_text', __( 'If it goes wrong', 'estasoft-handover-passport' ), 'textarea' );
		}
		$fill  = fn( string $text ): string => Placeholders::fill( $text, $this->values );
		$items = array();
		foreach ( $this->data->runbooks as $book ) {
			if ( 'published' !== (string) $book['status'] && ! $deep ) {
				continue;
			}
			$values  = array(
				'title'             => (string) $book['title'],
				'purpose'           => $fill( (string) $book['purpose'] ),
				'when_to_use'       => $fill( (string) $book['when_to_use'] ),
				'owner_role'        => (string) ( $roles[ (string) $book['owner_role'] ] ?? $book['owner_role'] ),
				'estimated_minutes' => (int) $book['estimated_minutes'],
				'last_rehearsed'    => (string) $book['last_rehearsed'],
				'status'            => (string) $book['status'],
				'prerequisites'     => array_values( array_map( fn( $p ): string => $fill( (string) ( $p['what'] ?? '' ) ), (array) $book['prerequisites'] ) ),
				'steps'             => $this->stepLines( (array) $book['steps'], $roles, $fill ),
				'verification'      => array_values( array_map( static fn( $v ): string => $fill( (string) $v ), (array) $book['verification'] ) ),
				'rollback_text'     => $fill( (string) $book['rollback_text'] ),
			);
			$items[] = $this->item( 'runbook:' . (int) $book['id'], (string) $book['title'], $values );
		}
		return array() === $items ? null : $this->wrap( 'runbooks', $title, array( $this->group( 'runbooks', $title, $fields, $this->sorted( $items ) ) ) );
	}

	/**
	 * Steps as numbered lines with who does them and how long they take.
	 *
	 * @param array<int, mixed>     $steps Steps.
	 * @param array<string, string> $roles Role labels.
	 * @param callable              $fill  Placeholder filler.
	 * @return array<int, string>
	 */
	private function stepLines( array $steps, array $roles, callable $fill ): array {
		$out = array();
		foreach ( $steps as $i => $step ) {
			$step  = (array) $step;
			$extra = array();
			if ( ! empty( $step['who'] ) ) {
				$extra[] = (string) ( $roles[ (string) $step['who'] ] ?? $step['who'] );
			}
			if ( ! empty( $step['minutes'] ) ) {
				/* translators: %d: minutes. */
				$extra[] = sprintf( __( '%d min', 'estasoft-handover-passport' ), (int) $step['minutes'] );
			}
			$line  = ( ! empty( $step['warning'] ) ? __( 'Careful: ', 'estasoft-handover-passport' ) : '' ) . $fill( (string) ( $step['text'] ?? '' ) );
			$line .= array() === $extra ? '' : ' (' . implode( ', ', $extra ) . ')';
			$out[] = ( (int) $i + 1 ) . '. ' . $line;
		}
		return $out;
	}

	/**
	 * Restore tests.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function tests( string $title ): ?array {
		$deep    = ! $this->profile->usesVisibilityFlags();
		$results = array( 'pass' => __( 'Passed', 'estasoft-handover-passport' ), 'partial' => __( 'Partly worked', 'estasoft-handover-passport' ), 'fail' => __( 'Failed', 'estasoft-handover-passport' ) );
		$fields  = array(
			$this->def( 'test_date', __( 'Date', 'estasoft-handover-passport' ), 'date' ),
			$this->def( 'result', __( 'Result', 'estasoft-handover-passport' ), 'select', $results ),
			$this->def( 'scope', __( 'What was restored', 'estasoft-handover-passport' ) ),
			$this->def( 'place', __( 'Restored to', 'estasoft-handover-passport' ) ),
		);
		if ( $deep ) {
			$fields[] = $this->def( 'who', __( 'Who', 'estasoft-handover-passport' ) );
			$fields[] = $this->def( 'backup_date', __( 'Backup from', 'estasoft-handover-passport' ), 'date' );
			$fields[] = $this->def( 'minutes', __( 'Minutes', 'estasoft-handover-passport' ), 'int' );
			$fields[] = $this->def( 'problems', __( 'Problems', 'estasoft-handover-passport' ), 'textarea' );
		}
		$items = array();
		foreach ( array_slice( $this->data->tests, 0, 10 ) as $test ) {
			$values = array();
			foreach ( $fields as $def ) {
				$values[ $def['key'] ] = $test[ $def['key'] ] ?? '';
			}
			$items[] = $this->item( 'test:' . (int) $test['id'], (string) $test['test_date'], $values, (string) $test['test_date'], __( 'Restore test', 'estasoft-handover-passport' ) );
		}
		return array() === $items ? null : $this->wrap( 'tests', $title, array( $this->group( 'tests', $title, $fields, $items ) ) );
	}

	/**
	 * Accounts whose legal owner is not the client, and where the transfer stands (OWN-3).
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function transfers( string $title ): ?array {
		$fields = array(
			$this->def( 'asset', __( 'Asset', 'estasoft-handover-passport' ) ),
			$this->def( 'kind', __( 'Kind', 'estasoft-handover-passport' ) ),
			$this->def( 'legal_owner', __( 'Legal owner', 'estasoft-handover-passport' ), 'select', Kinds::owners() ),
			$this->def( 'transfer_status', __( 'Transfer to the client', 'estasoft-handover-passport' ), 'select', Kinds::transferStates() ),
			$this->def( 'transfer_date', __( 'Transfer date', 'estasoft-handover-passport' ), 'date' ),
			$this->def( 'transfer_owner', __( 'Who handles it', 'estasoft-handover-passport' ) ),
			$this->def( 'transfer_notes', __( 'Notes', 'estasoft-handover-passport' ), 'textarea' ),
		);
		$items  = array();
		foreach ( $this->data->records as $record ) {
			$def = Kinds::get( (string) $record['kind'] );
			if ( empty( $def['ownership'] ) || ! $this->included( $record ) ) {
				continue;
			}
			$owner  = (string) $record['legal_owner'];
			$status = (string) ( $record['fields']['transfer_status'] ?? '' );
			if ( 'not_needed' === $status || ( ( 'client' === $owner || '' === $owner ) && '' === $status ) ) {
				continue;
			}
			$items[] = $this->item(
				'item:' . (int) $record['id'],
				(string) $record['title'],
				array(
					'asset'           => (string) $record['title'],
					'kind'            => (string) ( $def['label'] ?? $record['kind'] ),
					'legal_owner'     => $owner,
					'transfer_status' => $status,
					'transfer_date'   => (string) ( $record['fields']['transfer_date'] ?? '' ),
					'transfer_owner'  => (string) ( $record['fields']['transfer_owner'] ?? '' ),
					'transfer_notes'  => (string) ( $record['fields']['transfer_notes'] ?? '' ),
				),
				'',
				'',
				! empty( $record['sensitive'] )
			);
		}
		return array() === $items ? null : $this->wrap( 'transfers', $title, array( $this->group( 'transfers', $title, $fields, $this->sorted( $items ) ) ) );
	}

	/**
	 * The handover checklist (HND-1).
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function checklist( string $title ): ?array {
		$fields = array(
			$this->def( 'label', __( 'Item', 'estasoft-handover-passport' ) ),
			$this->def( 'state', __( 'State', 'estasoft-handover-passport' ), 'select', Kinds::checklistStates() ),
			$this->def( 'who', __( 'Who', 'estasoft-handover-passport' ) ),
			$this->def( 'when', __( 'When', 'estasoft-handover-passport' ), 'date' ),
			$this->def( 'detail', __( 'Detail', 'estasoft-handover-passport' ), 'textarea' ),
		);
		$items  = array();
		foreach ( $this->data->checklist as $row ) {
			$items[] = $this->item( 'check:' . (string) $row['key'], (string) $row['label'], array( 'label' => (string) $row['label'], 'state' => (string) $row['state'], 'who' => (string) ( $row['who'] ?? '' ), 'when' => (string) ( $row['when'] ?? '' ), 'detail' => (string) ( $row['detail'] ?? '' ) ) );
		}
		return array() === $items ? null : $this->wrap( 'checklist', $title, array( $this->group( 'checklist', $title, $fields, $items ) ) );
	}

	/**
	 * Open findings. A finding about a Sensitive record stays out unless the pack may show Sensitive records.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function findings( string $title ): ?array {
		$levels = array( 'needs_fixing' => __( 'Needs fixing', 'estasoft-handover-passport' ), 'worth_fixing' => __( 'Worth fixing', 'estasoft-handover-passport' ), 'info' => __( 'Info', 'estasoft-handover-passport' ) );
		$fields = array(
			$this->def( 'rule', __( 'Rule', 'estasoft-handover-passport' ) ),
			$this->def( 'severity', __( 'Level', 'estasoft-handover-passport' ), 'select', $levels ),
			$this->def( 'message', __( 'What was found', 'estasoft-handover-passport' ), 'textarea' ),
			$this->def( 'since', __( 'Since', 'estasoft-handover-passport' ), 'date' ),
		);
		$items  = array();
		foreach ( $this->data->findings as $finding ) {
			if ( ! $this->data->findingVisible( $finding, $this->profile, $this->options ) ) {
				continue;
			}
			$items[] = $this->item(
				'finding:' . (int) $finding['id'],
				(string) $finding['rule_id'],
				array( 'rule' => (string) $finding['rule_id'], 'severity' => (string) $finding['severity'], 'message' => (string) $finding['message'], 'since' => substr( (string) ( $finding['first_seen'] ?? '' ), 0, 10 ) )
			);
		}
		return array() === $items ? null : $this->wrap( 'findings', $title, array( $this->group( 'findings', $title, $fields, $items ) ) );
	}

	/**
	 * A chapter made of records of one or more kinds.
	 *
	 * @param string $key   Chapter key.
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function recordChapter( string $key, string $title ): ?array {
		$emergency = 0 === strpos( $key, 'emergency_' );
		$groups    = array();
		foreach ( self::RECORD_CHAPTERS[ $key ] as $kind ) {
			$records = array_values( array_filter( $this->data->records, fn( array $r ): bool => $kind === (string) $r['kind'] && $this->included( $r ) ) );
			if ( array() === $records ) {
				continue;
			}
			$groups[] = $this->kindGroup( $kind, $records, $emergency ? self::EMERGENCY_FIELDS[ $kind ] ?? null : null, $key );
		}
		if ( array() === $groups ) {
			return null;
		}
		$section = $this->wrap( $key, $title, $groups );
		if ( 'emergency_backups' === $key ) {
			$last             = $this->data->lastPass();
			$section['facts'] = array( array( 'label' => __( 'Last restore test that passed', 'estasoft-handover-passport' ), 'value' => '' !== $last ? $last : __( 'None recorded', 'estasoft-handover-passport' ) ) );
		}
		return $section;
	}

	/**
	 * A group for one kind of record.
	 *
	 * @param string                  $kind    Kind key.
	 * @param array<int, array>       $records Records that may go in.
	 * @param array<int, string>|null $only    Field keys to keep, or null for all.
	 * @param string                  $chapter Chapter key, for the item limit.
	 * @return array<string, mixed>
	 */
	private function kindGroup( string $kind, array $records, ?array $only, string $chapter ): array {
		$def      = (array) Kinds::get( $kind );
		$any_sens = false;
		foreach ( $records as $record ) {
			$any_sens = $any_sens || ! empty( $record['sensitive'] );
		}
		$show_sens = $this->options->allowsSensitiveFields( $this->profile ) || ( $any_sens && $this->options->may_see_sensitive );
		$fields    = array();
		foreach ( (array) $def['fields'] as $field_key => $field ) {
			$field_key = (string) $field_key;
			if ( null !== $only && ! in_array( $field_key, $only, true ) ) {
				continue;
			}
			if ( ! empty( $field['sensitive'] ) && ! $show_sens ) {
				continue;
			}
			$entry = array( 'key' => $field_key, 'label' => (string) $field['label'], 'type' => 'people' === $field['type'] ? 'lines' : (string) $field['type'] );
			if ( 'select' === $field['type'] ) {
				$entry['options'] = (array) $field['options'];
			}
			if ( ! empty( $field['sensitive'] ) ) {
				$entry['sensitive'] = true;
			}
			$fields[] = $entry;
		}
		$due_label = ! empty( $def['due'] ) ? (string) ( $def['fields'][ $def['due'] ]['label'] ?? '' ) : '';
		$items     = array();
		foreach ( $records as $record ) {
			$values = array();
			foreach ( $fields as $field ) {
				$values[ $field['key'] ] = $this->recordValue( $record, $def, $field['key'] );
			}
			$items[] = $this->item( 'item:' . (int) $record['id'], (string) $record['title'], $values, (string) $record['due_date'], $due_label, ! empty( $record['sensitive'] ), $kind );
		}
		$items = $this->sorted( $items );
		if ( Kinds::INCIDENT === $kind ) {
			// The latest incident first; the title only breaks a tie.
			usort( $items, static fn( array $a, array $b ): int => strcmp( (string) ( $b['values']['occurred_on'] ?? '' ), (string) ( $a['values']['occurred_on'] ?? '' ) ) );
		}
		$items = $this->limit( $chapter, $items, (string) ( $def['plural'] ?? $kind ) );
		return $this->group( $kind, (string) ( $def['plural'] ?? $kind ), $fields, $items );
	}

	/**
	 * One value of a record, ready for the pack.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param array<string, mixed> $def    Kind definition.
	 * @param string               $key    Field key.
	 * @return mixed
	 */
	private function recordValue( array $record, array $def, string $key ) {
		$field = (array) ( $def['fields'][ $key ] ?? array() );
		if ( 'people' === ( $field['type'] ?? '' ) ) {
			return $this->names( (array) ( $record['owners'][ $key ] ?? array() ) );
		}
		$value = $record['fields'][ $key ] ?? '';
		return 'money' === ( $field['type'] ?? '' ) ? Fields::moneyDisplay( $value ) : $value;
	}

	/**
	 * Names of people, following the sharing flags in a Client pack or Emergency sheet.
	 *
	 * @param array<int, mixed> $ids Person IDs.
	 * @return array<int, string>
	 */
	private function names( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$person = $this->data->person( (int) $id );
			if ( null === $person ) {
				continue;
			}
			if ( $this->profile->usesSharingFlags() && empty( $person['share_flags']['name'] ) ) {
				$out[] = __( '(name not shared)', 'estasoft-handover-passport' );
				continue;
			}
			$out[] = (string) $person['name'];
		}
		return $out;
	}

	/**
	 * Whether a record goes into this pack at all (EXP-3, EXP-4).
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 */
	private function included( array $record ): bool {
		if ( 'yes' === (string) ( $record['fields']['hidden'] ?? '' ) ) {
			return false;
		}
		if ( Sensitivity::isSensitive( $record ) ) {
			return $this->options->allowsSensitiveRecord( $this->profile, (int) $record['id'] );
		}
		if ( Profile::CLIENT === $this->profile->audience ) {
			return ! empty( $record['vis_client'] );
		}
		if ( Profile::EMERGENCY === $this->profile->audience ) {
			return ! empty( $record['vis_emergency'] );
		}
		return true;
	}

	/**
	 * The people to call, in order (PPL-3).
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function emergencyContacts( string $title ): ?array {
		$fields = array(
			$this->def( 'order', __( 'Call', 'estasoft-handover-passport' ), 'int' ),
			$this->def( 'name', __( 'Name', 'estasoft-handover-passport' ) ),
			$this->def( 'organisation', __( 'Organisation', 'estasoft-handover-passport' ) ),
			$this->def( 'roles', __( 'Roles', 'estasoft-handover-passport' ), 'lines' ),
			$this->def( 'phone', __( 'Phone', 'estasoft-handover-passport' ) ),
			$this->def( 'urgent_method', __( 'How to reach them urgently', 'estasoft-handover-passport' ) ),
			$this->def( 'email', __( 'Email', 'estasoft-handover-passport' ) ),
		);
		$items  = array();
		foreach ( (array) ( $this->data->roles['emergency_contact'] ?? array() ) as $position => $id ) {
			$person = $this->data->person( (int) $id );
			if ( null === $person || '' !== (string) ( $person['left_on'] ?? '' ) || empty( $person['share_flags']['name'] ) ) {
				continue;
			}
			$values          = $this->personValues( $person, true );
			$values['order'] = count( $items ) + 1;
			$items[]         = $this->item( 'person:' . (int) $person['id'], (string) $person['name'], $values );
		}
		if ( array() === $items ) {
			return null;
		}
		$items = $this->limit( 'emergency_contacts', $items, __( 'contacts', 'estasoft-handover-passport' ) );
		return $this->wrap( 'emergency_contacts', $title, array( $this->group( 'people', $title, $fields, $items ) ) );
	}

	/**
	 * Published runbooks marked critical, by title, and where they are kept.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>|null
	 */
	private function emergencyRunbooks( string $title ): ?array {
		$fields = array(
			$this->def( 'title', __( 'Runbook', 'estasoft-handover-passport' ) ),
			$this->def( 'when_to_use', __( 'When to use it', 'estasoft-handover-passport' ), 'textarea' ),
			$this->def( 'estimated_minutes', __( 'Minutes', 'estasoft-handover-passport' ), 'int' ),
		);
		$items  = array();
		foreach ( $this->data->runbooks as $book ) {
			if ( 'published' !== (string) $book['status'] || empty( $book['critical'] ) ) {
				continue;
			}
			$items[] = $this->item( 'runbook:' . (int) $book['id'], (string) $book['title'], array( 'title' => (string) $book['title'], 'when_to_use' => Placeholders::fill( (string) $book['when_to_use'], $this->values ), 'estimated_minutes' => (int) $book['estimated_minutes'] ) );
		}
		if ( array() === $items ) {
			return null;
		}
		$items   = $this->limit( 'emergency_runbooks', $this->sorted( $items ), __( 'runbooks', 'estasoft-handover-passport' ) );
		$section = $this->wrap( 'emergency_runbooks', $title, array( $this->group( 'runbooks', $title, $fields, $items ) ) );
		/* translators: %s: the site address. */
		$section['intro'] = sprintf( __( 'The steps are kept in the Passport on %s, under Safety, Runbooks.', 'estasoft-handover-passport' ), (string) ( $this->data->site['url'] ?? '' ) );
		return $section;
	}

	/**
	 * Cut a list to what fits on the page and say what was left off (EXP-1).
	 *
	 * @param string            $chapter Chapter key.
	 * @param array<int, array> $items   Items.
	 * @param string            $noun    Plural name of what was cut.
	 * @return array<int, array>
	 */
	private function limit( string $chapter, array $items, string $noun ): array {
		$max = self::EMERGENCY_LIMITS[ $chapter ] ?? 0;
		if ( ! $this->profile->isOnePage() || $max < 1 || count( $items ) <= $max ) {
			return $items;
		}
		$cut = count( $items ) - $max;
		/* translators: 1: number of items left off, 2: what they are, such as backups. */
		$this->left_out[] = sprintf( _n( '%1$d more %2$s is in the Passport and was left off this page.', '%1$d more %2$s are in the Passport and were left off this page.', $cut, 'estasoft-handover-passport' ), $cut, strtolower( $noun ) );
		return array_slice( $items, 0, $max );
	}

	/**
	 * Items sorted by title, so the same data always gives the same pack.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return array<int, array<string, mixed>>
	 */
	private function sorted( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$by = strcasecmp( (string) $a['title'], (string) $b['title'] );
				return 0 !== $by ? $by : strcmp( (string) $a['ref'], (string) $b['ref'] );
			}
		);
		return $items;
	}

	/**
	 * A chapter around its groups.
	 *
	 * @param string                           $key    Chapter key.
	 * @param string                           $title  Title.
	 * @param array<int, array<string, mixed>> $groups Groups.
	 * @return array<string, mixed>
	 */
	private function wrap( string $key, string $title, array $groups ): array {
		return array( 'key' => $key, 'title' => $title, 'groups' => $groups );
	}

	/**
	 * A group of items that share fields. An item holds a value only for a field the group shows, so a value that the
	 * page leaves out is not in the JSON or in a snapshot either.
	 *
	 * @param string                           $key    Group key.
	 * @param string                           $title  Title.
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 * @param array<int, array<string, mixed>> $items  Items.
	 * @return array<string, mixed>
	 */
	private function group( string $key, string $title, array $fields, array $items ): array {
		$keys = array_flip( array_map( static fn( array $f ): string => (string) $f['key'], $fields ) );
		foreach ( $items as $i => $item ) {
			$items[ $i ]['values'] = array_intersect_key( (array) $item['values'], $keys );
		}
		return array( 'key' => $key, 'title' => $title, 'fields' => $fields, 'items' => $items );
	}

	/**
	 * One item.
	 *
	 * @param string               $ref        Stable reference such as item:12.
	 * @param string               $title      Title.
	 * @param array<string, mixed> $values     Values by field key.
	 * @param string               $date       A date that belongs on a calendar, or ''.
	 * @param string               $date_label What the date is.
	 * @param bool                 $sensitive  Whether the item is a Sensitive record.
	 * @param string               $kind       Kind key, for records.
	 * @return array<string, mixed>
	 */
	private function item( string $ref, string $title, array $values, string $date = '', string $date_label = '', bool $sensitive = false, string $kind = '' ): array {
		$item = array( 'ref' => $ref, 'title' => $title, 'date' => $date, 'date_label' => $date_label, 'sensitive' => $sensitive, 'values' => $values );
		if ( '' !== $kind ) {
			$item['kind'] = $kind;
		}
		return $item;
	}

	/**
	 * A field definition.
	 *
	 * @param string                $key     Key.
	 * @param string                $label   Label.
	 * @param string                $type    text, textarea, date, int, lines or select.
	 * @param array<string, string> $options Choices for a select.
	 * @return array<string, mixed>
	 */
	private function def( string $key, string $label, string $type = 'text', array $options = array() ): array {
		$def = array( 'key' => $key, 'label' => $label, 'type' => $type );
		if ( 'select' === $type ) {
			$def['options'] = $options;
		}
		return $def;
	}
}
