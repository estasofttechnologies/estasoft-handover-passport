<?php
/**
 * WordPress personal-data export and erase tools (PRV-3, PPL-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Privacy;

use Estasoft\HandoverPassport\Admin\ChangeText;
use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\ScanRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the requests made under Tools > Export Personal Data and Tools > Erase Personal Data.
 *
 * The request is for one email address. It is matched to the people directory by that address, to the
 * acknowledgements by name (they hold no address), and to WordPress administrators found by the scan by address.
 *
 * Erasing replaces a person with "Former contact" and keeps the row, so a role or a record that pointed at them still
 * shows that someone held it and the checks can ask for a new person. Two things stay: the saved snapshots, which
 * are fixed by their checksum and can only be deleted whole, and free text in records, which this plugin cannot
 * rewrite safely. The erase says so, in plain words, so the person processing the request can deal with them.
 */
final class PersonalData {

	/**
	 * Neutral name for a WordPress user after an erase. Stored text; not translated.
	 */
	public const FORMER_USER = 'Former user';

	/**
	 * Change-log entries in one export page.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach hooks. These run on every request type because WordPress also processes requests from cron and the REST
	 * API.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Add the exporters.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function exporters( $exporters ): array {
		$exporters   = is_array( $exporters ) ? $exporters : array();
		$exporters['estasoft-handover-passport']         = array(
			'exporter_friendly_name' => __( 'Estasoft Handover Passport: directory', 'estasoft-handover-passport' ),
			'callback'               => array( $this, 'export' ),
		);
		$exporters['estasoft-handover-passport-changes'] = array(
			'exporter_friendly_name' => __( 'Estasoft Handover Passport: change log', 'estasoft-handover-passport' ),
			'callback'               => array( $this, 'exportChanges' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function erasers( $erasers ): array {
		$erasers                               = is_array( $erasers ) ? $erasers : array();
		$erasers['estasoft-handover-passport'] = array(
			'eraser_friendly_name' => __( 'Estasoft Handover Passport', 'estasoft-handover-passport' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export what the directory, the acknowledgements and the scan hold for an address.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page, always 1 here.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The page is part of the WordPress callback signature.
		$email = $this->address( $email );
		if ( '' === $email ) {
			return array( 'data' => array(), 'done' => true );
		}
		$people = $this->peopleFor( $email );
		$groups = array_merge(
			$this->directoryGroups( $people ),
			$this->linkGroups( $people ),
			$this->acknowledgementGroups( $this->namesOf( $people ) ),
			$this->administratorGroups( $email ),
			$this->mentionGroups( $email )
		);
		return array( 'data' => $groups, 'done' => true );
	}

	/**
	 * Export the change-log entries made by the WordPress user with this address, a page at a time.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page number from 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function exportChanges( $email, $page = 1 ): array {
		$email    = $this->address( $email );
		$user_ids = '' === $email ? array() : $this->identity( $email )['ids'];
		if ( array() === $user_ids ) {
			return array( 'data' => array(), 'done' => true );
		}
		$page   = max( 1, (int) $page );
		$repo   = $this->changes();
		$rows   = $repo->byActors( $user_ids, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		$rows   = $this->plugin->changeLog()->forViewer( $rows, false );
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[] = array(
				'group_id'          => 'estasoft-hp-changes',
				'group_label'       => __( 'Passport change log', 'estasoft-handover-passport' ),
				'group_description' => __( 'Changes this person made that the Passport logged. Records marked Sensitive are shown without their names.', 'estasoft-handover-passport' ),
				'item_id'           => 'change-' . (int) $row['id'],
				'data'              => $this->pairs(
					array(
						__( 'When (UTC)', 'estasoft-handover-passport' ) => (string) $row['changed_at'],
						__( 'What', 'estasoft-handover-passport' ) => ChangeText::describe( $row ),
						__( 'Made through', 'estasoft-handover-passport' ) => (string) $row['source'],
					)
				),
			);
		}
		return array( 'data' => $groups, 'done' => count( $rows ) < self::PAGE_SIZE );
	}

	/**
	 * Erase what can be erased for an address and say what is kept.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page, always 1 here.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The page is part of the WordPress callback signature.
		$email  = $this->address( $email );
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( '' === $email ) {
			return $result;
		}
		$people   = $this->peopleFor( $email );
		$names    = $this->namesOf( $people );
		$identity = $this->identity( $email );
		$counts   = array(
			'people'          => 0,
			'acknowledgements' => 0,
			'scans'           => 0,
			'changes'         => 0,
		);

		foreach ( $people as $person ) {
			$id = (int) $person['id'];
			if ( $this->plugin->people()->anonymise( $id ) ) {
				++$counts['people'];
			}
			$counts['changes'] += $this->changes()->relabel( 'person', $id, PeopleService::FORMER_CONTACT );
		}
		$counts['acknowledgements'] = $this->anonymiseAcknowledgements( $names );
		$counts['changes']         += $this->anonymiseReceivedBy( $names );
		$counts['scans']            = $this->anonymiseScans( $email );
		$counts['changes']         += $this->changes()->anonymiseUsers( $identity['ids'], $identity['names'], self::FORMER_USER );

		$total = array_sum( $counts );
		if ( $total > 0 ) {
			$result['items_removed'] = true;
			$this->plugin->changeLog()->add(
				'personal_data_erased',
				'passport',
				PeopleService::PASSPORT_ID,
				PeopleService::FORMER_CONTACT,
				array(),
				$counts,
				'',
				'wordpress'
			);
		}

		$kept = $this->retained( $email, $names, $identity['account'] );
		if ( array() !== $kept ) {
			$result['items_retained'] = true;
			$result['messages']       = $kept;
		}
		return $result;
	}

	/**
	 * Directory rows for an address, deleted ones included, because a deleted person is still stored for 30 days.
	 *
	 * @param string $email Lower-case address.
	 * @return list<array<string, mixed>>
	 */
	private function peopleFor( string $email ): array {
		return $this->plugin->people()->repo()->byEmail( $email );
	}

	/**
	 * Names and organisations of the people found, for matching acknowledgements.
	 *
	 * @param list<array<string, mixed>> $people Directory rows.
	 * @return list<array{name: string, organisation: string}>
	 */
	private function namesOf( array $people ): array {
		$out = array();
		foreach ( $people as $person ) {
			$name = trim( (string) $person['name'] );
			if ( '' === $name || PeopleService::FORMER_CONTACT === $name ) {
				continue;
			}
			$out[] = array( 'name' => $name, 'organisation' => trim( (string) $person['organisation'] ) );
		}
		return $out;
	}

	/**
	 * Whether an acknowledgement belongs to one of the people. It holds a name and sometimes an organisation, never
	 * an address, so the names must match, and the organisations too when both are filled in.
	 *
	 * @param array<string, mixed>                              $ack   Acknowledgement.
	 * @param list<array{name: string, organisation: string}>   $names People found.
	 */
	private function matches( array $ack, array $names ): bool {
		$name = trim( (string) ( $ack['name'] ?? '' ) );
		if ( '' === $name ) {
			return false;
		}
		$org = trim( (string) ( $ack['organisation'] ?? '' ) );
		foreach ( $names as $person ) {
			if ( 0 !== strcasecmp( $name, $person['name'] ) ) {
				continue;
			}
			if ( '' === $org || '' === $person['organisation'] || 0 === strcasecmp( $org, $person['organisation'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The WordPress account behind an address: its number if the account exists, plus the numbers and names the saved
	 * scans hold for administrators with that address, which stay after an account is deleted.
	 *
	 * @param string $email Lower-case address.
	 * @return array{ids: list<int>, names: list<string>, account: ?\WP_User}
	 */
	private function identity( string $email ): array {
		$ids     = array();
		$names   = array();
		$account = get_user_by( 'email', $email );
		if ( $account instanceof \WP_User ) {
			$ids[]   = (int) $account->ID;
			$names[] = (string) $account->display_name;
		}
		foreach ( ( new ScanRepository() )->query( array(), 'id', 'ASC' ) as $scan ) {
			foreach ( (array) ( $scan['facts']['users']['admins'] ?? array() ) as $admin ) {
				if ( 0 === strcasecmp( trim( (string) ( $admin['email'] ?? '' ) ), $email ) ) {
					$ids[]   = (int) ( $admin['id'] ?? 0 );
					$names[] = (string) ( $admin['name'] ?? '' );
				}
			}
		}
		return array(
			'ids'     => array_values( array_unique( array_filter( $ids ) ) ),
			'names'   => array_values( array_unique( array_filter( $names, static fn( string $name ): bool => '' !== $name && self::FORMER_USER !== $name ) ) ),
			'account' => $account instanceof \WP_User ? $account : null,
		);
	}

	/**
	 * Directory groups (PRV-3).
	 *
	 * @param list<array<string, mixed>> $people Directory rows.
	 * @return list<array<string, mixed>>
	 */
	private function directoryGroups( array $people ): array {
		$groups = array();
		foreach ( $people as $person ) {
			$id      = (int) $person['id'];
			$roles   = array();
			foreach ( PeopleService::roles() as $key => $label ) {
				if ( in_array( $id, $this->plugin->people()->inRole( $key ), true ) ) {
					$roles[] = $label;
				}
			}
			$shared = array();
			foreach ( (array) $person['share_flags'] as $flag => $on ) {
				if ( ! empty( $on ) ) {
					$shared[] = (string) $flag;
				}
			}
			$kinds   = PeopleService::kinds();
			$groups[] = array(
				'group_id'          => 'estasoft-hp-directory',
				'group_label'       => __( 'Passport directory', 'estasoft-handover-passport' ),
				'group_description' => __( 'People listed in the Handover Passport of this site.', 'estasoft-handover-passport' ),
				'item_id'           => 'person-' . $id,
				'data'              => $this->pairs(
					array(
						__( 'Name', 'estasoft-handover-passport' ) => (string) $person['name'],
						__( 'Organisation', 'estasoft-handover-passport' ) => (string) $person['organisation'],
						__( 'Kind', 'estasoft-handover-passport' ) => (string) ( $kinds[ (string) $person['kind'] ] ?? $person['kind'] ),
						__( 'Title', 'estasoft-handover-passport' ) => (string) $person['title'],
						__( 'Email', 'estasoft-handover-passport' ) => (string) $person['email'],
						__( 'Phone', 'estasoft-handover-passport' ) => (string) $person['phone'],
						__( 'Language', 'estasoft-handover-passport' ) => (string) $person['language'],
						__( 'Time zone', 'estasoft-handover-passport' ) => (string) $person['timezone'],
						__( 'How to reach them urgently', 'estasoft-handover-passport' ) => (string) $person['urgent_method'],
						__( 'Left on', 'estasoft-handover-passport' ) => Dates::displayDate( (string) ( $person['left_on'] ?? '' ) ),
						__( 'Note', 'estasoft-handover-passport' ) => (string) $person['note'],
						__( 'Roles', 'estasoft-handover-passport' ) => implode( ', ', $roles ),
						__( 'Shown in client packs and emergency sheets', 'estasoft-handover-passport' ) => implode( ', ', $shared ),
						__( 'Added (UTC)', 'estasoft-handover-passport' ) => (string) $person['created_at'],
						__( 'Last changed (UTC)', 'estasoft-handover-passport' ) => (string) $person['updated_at'],
						__( 'Deleted (UTC)', 'estasoft-handover-passport' ) => (string) ( $person['deleted_at'] ?? '' ),
					)
				),
			);
		}
		return $groups;
	}

	/**
	 * Records that name the person as account holder, payer, administrator or someone with access. A Sensitive record
	 * is named only by its kind.
	 *
	 * @param list<array<string, mixed>> $people Directory rows.
	 * @return list<array<string, mixed>>
	 */
	private function linkGroups( array $people ): array {
		$relations = array(
			LinkRepository::HOLDER => __( 'Account holder', 'estasoft-handover-passport' ),
			LinkRepository::PAYER  => __( 'Payer', 'estasoft-handover-passport' ),
			LinkRepository::ADMIN  => __( 'Administrator', 'estasoft-handover-passport' ),
			LinkRepository::ACCESS => __( 'Has access', 'estasoft-handover-passport' ),
		);
		$items  = new ItemRepository();
		$groups = array();
		foreach ( $people as $person ) {
			foreach ( $this->plugin->people()->assetsFor( (int) $person['id'] ) as $link ) {
				$item = $items->find( $link['item_id'] );
				if ( null === $item || null !== $item['deleted_at'] ) {
					continue;
				}
				$groups[] = array(
					'group_id'          => 'estasoft-hp-records',
					'group_label'       => __( 'Passport records that name this person', 'estasoft-handover-passport' ),
					'group_description' => __( 'Domains, services, access entries and other records where this person is named.', 'estasoft-handover-passport' ),
					'item_id'           => 'record-' . (int) $item['id'] . '-' . $link['relation'],
					'data'              => $this->pairs(
						array(
							__( 'Record', 'estasoft-handover-passport' ) => $this->itemLabel( $item ),
							__( 'Relation', 'estasoft-handover-passport' ) => (string) ( $relations[ $link['relation'] ] ?? $link['relation'] ),
						)
					),
				);
			}
		}
		return $groups;
	}

	/**
	 * Snapshot acknowledgements that name the person.
	 *
	 * @param list<array{name: string, organisation: string}> $names People found.
	 * @return list<array<string, mixed>>
	 */
	private function acknowledgementGroups( array $names ): array {
		if ( array() === $names ) {
			return array();
		}
		$methods = \Estasoft\HandoverPassport\Handover\SnapshotService::methods();
		$groups  = array();
		foreach ( $this->plugin->snapshots()->repo()->listAll() as $snapshot ) {
			foreach ( (array) $snapshot['acknowledgements'] as $index => $ack ) {
				if ( ! is_array( $ack ) || ! $this->matches( $ack, $names ) ) {
					continue;
				}
				$groups[] = array(
					'group_id'          => 'estasoft-hp-acknowledgements',
					'group_label'       => __( 'Passport handover acknowledgements', 'estasoft-handover-passport' ),
					'group_description' => __( 'Records that this person received a handover snapshot. They are matched by name, because they hold no email address.', 'estasoft-handover-passport' ),
					'item_id'           => 'acknowledgement-' . (int) $snapshot['id'] . '-' . (int) $index,
					'data'              => $this->pairs(
						array(
							__( 'Snapshot', 'estasoft-handover-passport' ) => (string) $snapshot['name'],
							__( 'Received by', 'estasoft-handover-passport' ) => (string) ( $ack['name'] ?? '' ),
							__( 'Organisation', 'estasoft-handover-passport' ) => (string) ( $ack['organisation'] ?? '' ),
							__( 'Role', 'estasoft-handover-passport' ) => (string) ( $ack['role'] ?? '' ),
							__( 'Date', 'estasoft-handover-passport' ) => Dates::displayDate( (string) ( $ack['date'] ?? '' ) ),
							__( 'How it was received', 'estasoft-handover-passport' ) => (string) ( $methods[ (string) ( $ack['method'] ?? '' ) ] ?? ( $ack['method'] ?? '' ) ),
							__( 'Note', 'estasoft-handover-passport' ) => (string) ( $ack['note'] ?? '' ),
							__( 'Recorded by', 'estasoft-handover-passport' ) => (string) ( $ack['recorded_by'] ?? '' ),
							__( 'Recorded (UTC)', 'estasoft-handover-passport' ) => (string) ( $ack['recorded_at'] ?? '' ),
						)
					),
				);
			}
		}
		return $groups;
	}

	/**
	 * The administrator entry in the latest scan, when its address matches.
	 *
	 * @param string $email Lower-case address.
	 * @return list<array<string, mixed>>
	 */
	private function administratorGroups( string $email ): array {
		$groups = array();
		foreach ( (array) ( $this->plugin->scanService()->latestFacts()['users']['admins'] ?? array() ) as $admin ) {
			if ( 0 !== strcasecmp( trim( (string) ( $admin['email'] ?? '' ) ), $email ) ) {
				continue;
			}
			$groups[] = array(
				'group_id'          => 'estasoft-hp-administrators',
				'group_label'       => __( 'Passport scan: administrators', 'estasoft-handover-passport' ),
				'group_description' => __( 'The WordPress administrators found by the last scan of this site.', 'estasoft-handover-passport' ),
				'item_id'           => 'administrator-' . (int) ( $admin['id'] ?? 0 ),
				'data'              => $this->pairs(
					array(
						__( 'User number', 'estasoft-handover-passport' ) => (string) ( $admin['id'] ?? '' ),
						__( 'Name', 'estasoft-handover-passport' ) => (string) ( $admin['name'] ?? '' ),
						__( 'Email', 'estasoft-handover-passport' ) => (string) ( $admin['email'] ?? '' ),
						__( 'Registered (UTC)', 'estasoft-handover-passport' ) => (string) ( $admin['registered'] ?? '' ),
					)
				),
			);
		}
		return $groups;
	}

	/**
	 * Records that contain the address in their text.
	 *
	 * @param string $email Lower-case address.
	 * @return list<array<string, mixed>>
	 */
	private function mentionGroups( string $email ): array {
		$groups = array();
		foreach ( ( new ItemRepository() )->mentioning( $email ) as $item ) {
			$groups[] = array(
				'group_id'          => 'estasoft-hp-mentions',
				'group_label'       => __( 'Passport records that mention this email address', 'estasoft-handover-passport' ),
				'group_description' => __( 'Records whose text contains the address. A Sensitive record is named only by its kind.', 'estasoft-handover-passport' ),
				'item_id'           => 'mention-' . (int) $item['id'],
				'data'              => $this->pairs( array( __( 'Record', 'estasoft-handover-passport' ) => $this->itemLabel( $item ) ) ),
			);
		}
		return $groups;
	}

	/**
	 * Blank the details in acknowledgements that name one of the people. The date, the way it was received and who
	 * recorded it stay, so the snapshot still shows that it was received.
	 *
	 * @param list<array{name: string, organisation: string}> $names People found.
	 * @return int Acknowledgements changed.
	 */
	private function anonymiseAcknowledgements( array $names ): int {
		if ( array() === $names ) {
			return 0;
		}
		$repo    = $this->plugin->snapshots()->repo();
		$changed = 0;
		foreach ( $repo->listAll() as $snapshot ) {
			$list = (array) $snapshot['acknowledgements'];
			$hit  = false;
			foreach ( $list as $index => $ack ) {
				if ( is_array( $ack ) && $this->matches( $ack, $names ) ) {
					$list[ $index ]['name']         = PeopleService::FORMER_CONTACT;
					$list[ $index ]['organisation'] = '';
					$list[ $index ]['note']         = '';
					$hit                            = true;
					++$changed;
				}
			}
			if ( $hit ) {
				$repo->update( (int) $snapshot['id'], array( 'acknowledgements' => array_values( $list ) ) );
			}
		}
		return $changed;
	}

	/**
	 * Blank the receiver's name in the change-log entries about acknowledgements.
	 *
	 * @param list<array{name: string, organisation: string}> $names People found.
	 * @return int Entries changed.
	 */
	private function anonymiseReceivedBy( array $names ): int {
		if ( array() === $names ) {
			return 0;
		}
		$repo    = $this->changes();
		$changed = 0;
		foreach ( $repo->search( array( 'kind' => 'snapshot_acknowledged' ), 0 ) as $row ) {
			$after = (array) $row['after_data'];
			if ( $this->matches( array( 'name' => (string) ( $after['received_by'] ?? '' ), 'organisation' => (string) ( $after['organisation'] ?? '' ) ), $names ) ) {
				$after['received_by']  = PeopleService::FORMER_CONTACT;
				$after['organisation'] = '';
				$repo->update( (int) $row['id'], array( 'after_data' => $after, 'note' => '' ) );
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Blank the name and address of an administrator in every stored scan. The user number stays so a later scan does
	 * not report the same account as a new administrator.
	 *
	 * @param string $email Lower-case address.
	 * @return int Scans changed.
	 */
	private function anonymiseScans( string $email ): int {
		$repo    = new ScanRepository();
		$changed = 0;
		foreach ( $repo->query( array(), 'id', 'ASC' ) as $scan ) {
			$facts = (array) $scan['facts'];
			$hit   = false;
			foreach ( (array) ( $facts['users']['admins'] ?? array() ) as $index => $admin ) {
				if ( 0 === strcasecmp( trim( (string) ( $admin['email'] ?? '' ) ), $email ) ) {
					$facts['users']['admins'][ $index ]['name']  = self::FORMER_USER;
					$facts['users']['admins'][ $index ]['email'] = '';
					$hit                                         = true;
				}
			}
			if ( $hit ) {
				$repo->update( (int) $scan['id'], array( 'facts' => $facts ) );
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * What an erase cannot change, as plain sentences.
	 *
	 * @param string                                          $email   Lower-case address.
	 * @param list<array{name: string, organisation: string}> $names   People found.
	 * @param \WP_User|null                                   $account The WordPress account with this address, if it exists.
	 * @return list<string>
	 */
	private function retained( string $email, array $names, ?\WP_User $account ): array {
		$out = array();

		if ( null !== $account && in_array( 'administrator', (array) $account->roles, true ) ) {
			$out[] = __( 'This address still belongs to an administrator account on this site, so the next Passport scan lists the account again. Remove or change the account under Users first if it must not appear.', 'estasoft-handover-passport' );
		}

		$needles = array( $email );
		foreach ( $names as $person ) {
			$needles[] = $person['name'];
		}
		$snapshots = $this->plugin->snapshots()->repo()->countMentioning( $needles );
		if ( $snapshots > 0 ) {
			$out[] = sprintf(
				/* translators: %d: number of snapshots. */
				_n(
					'%d saved Handover Passport snapshot may contain this person\'s details. A snapshot is fixed by its checksum and cannot be edited. Delete it under Passport > Handover if it must go.',
					'%d saved Handover Passport snapshots may contain this person\'s details. A snapshot is fixed by its checksum and cannot be edited. Delete them under Passport > Handover if they must go.',
					$snapshots,
					'estasoft-handover-passport'
				),
				$snapshots
			);
		}

		$records = count( ( new ItemRepository() )->mentioning( $email ) );
		if ( $records > 0 ) {
			$out[] = sprintf(
				/* translators: %d: number of records. */
				_n(
					'%d Handover Passport record mentions this email address in its text. Open it and remove the address by hand.',
					'%d Handover Passport records mention this email address in their text. Open them and remove the address by hand.',
					$records,
					'estasoft-handover-passport'
				),
				$records
			);
		}
		return $out;
	}

	/**
	 * Name a record for an export. A Sensitive record gives only its kind.
	 *
	 * @param array<string, mixed> $item Record row.
	 */
	private function itemLabel( array $item ): string {
		$kind  = Kinds::get( (string) $item['kind'] );
		$label = null === $kind ? (string) $item['kind'] : (string) $kind['label'];
		return ! empty( $item['sensitive'] ) ? $label : $label . ': ' . (string) $item['title'];
	}

	/**
	 * Name and value pairs for WordPress, leaving out empty values.
	 *
	 * @param array<string, string> $map Label => value.
	 * @return list<array{name: string, value: string}>
	 */
	private function pairs( array $map ): array {
		$out = array();
		foreach ( $map as $name => $value ) {
			if ( '' !== trim( $value ) ) {
				$out[] = array( 'name' => (string) $name, 'value' => $value );
			}
		}
		return $out;
	}

	/**
	 * A clean lower-case address, or an empty string.
	 *
	 * @param mixed $email Address as WordPress passes it.
	 */
	private function address( $email ): string {
		$email = strtolower( trim( (string) $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Change repository.
	 */
	private function changes(): ChangeRepository {
		return $this->plugin->changeLog()->repo();
	}
}
