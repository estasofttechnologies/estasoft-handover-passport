<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Export\ContentBuilder;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Export\Profiles;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Json;

final class ExportContentTest extends UnitTestCase {

	private const SECRET_DOMAIN = 'secret-domain.example';
	private const SECRET_EMAIL  = 'billing-secret@example.test';

	protected function tearDown(): void {
		unset( $GLOBALS['estasoft_hp_test_filters'] );
	}

	private function build( string $profile, $data = null, ?ExportOptions $options = null ): array {
		$profiles = new Profiles();
		return ( new ContentBuilder( new SecretGuard(), '1.0.0' ) )->build( $profiles->get( $profile ), $data ?? $this->passport(), $options ?? new ExportOptions( true, array(), 1 ) );
	}

	private function passport( array $with = array() ) {
		$records = array(
			ExportFixture::record( 1, 'domain', 'plain.example', array( 'domain' => 'plain.example', 'registrar' => 'Reg Co', 'expires_on' => '2027-01-01', 'role' => 'primary' ), array( 'due_date' => '2027-01-01' ) ),
			ExportFixture::record( 2, 'domain', self::SECRET_DOMAIN, array( 'domain' => self::SECRET_DOMAIN, 'registrar' => 'Hidden Reg', 'expires_on' => '2027-02-01' ), array( 'sensitive' => true, 'due_date' => '2027-02-01' ) ),
			ExportFixture::record( 3, 'licence', 'Plain Licence', array( 'product' => 'Plain Licence', 'renews_on' => '2027-03-01', 'account_email' => self::SECRET_EMAIL ), array( 'due_date' => '2027-03-01' ) ),
			ExportFixture::record( 4, 'licence', 'Not for the client', array( 'product' => 'Not for the client', 'renews_on' => '2027-04-01' ), array( 'vis_client' => false, 'due_date' => '2027-04-01' ) ),
			ExportFixture::record( 5, 'host', 'Host Co', array( 'provider' => 'Host Co', 'support_address' => 'help@host.example' ) ),
		);
		return ExportFixture::data( array_merge( array( 'records' => $records ), $with ) );
	}

	public function test_the_content_names_its_schema_pack_and_time(): void {
		$content = $this->build( Profile::TECHNICAL );
		$this->assertSame( 'estasoft-handover-passport', $content['schema'] );
		$this->assertSame( '1', $content['schema_version'] );
		$this->assertSame( '1.0.0', $content['plugin_version'] );
		$this->assertSame( 'technical', $content['profile']['key'] );
		$this->assertSame( '2026-11-08 10:00:00', $content['generated_at'] );
		$this->assertSame( 'Fixture Site', $content['site']['name'] );
	}

	public function test_the_same_data_gives_the_same_canonical_text_and_hash(): void {
		$a = Json::canonical( $this->build( Profile::TECHNICAL ) );
		$b = Json::canonical( $this->build( Profile::TECHNICAL ) );
		$this->assertSame( $a, $b );
		$this->assertSame( hash( 'sha256', $a ), hash( 'sha256', $b ) );
	}

	public function test_a_different_value_changes_the_hash(): void {
		$a = Json::canonical( $this->build( Profile::TECHNICAL ) );
		$b = Json::canonical( $this->build( Profile::TECHNICAL, $this->passport( array( 'notes' => 'changed' ) ) ) );
		$this->assertNotSame( hash( 'sha256', $a ), hash( 'sha256', $b ) );
	}

	public function test_a_sensitive_record_stays_out_of_every_pack_unless_it_is_ticked_or_the_pack_is_full(): void {
		foreach ( array( Profile::CLIENT, Profile::TECHNICAL, Profile::EMERGENCY ) as $key ) {
			$text = ExportFixture::text( $this->build( $key ) );
			$this->assertStringNotContainsString( self::SECRET_DOMAIN, $text, $key );
			$this->assertStringNotContainsString( 'Hidden Reg', $text, $key );
		}
		$this->assertStringContainsString( self::SECRET_DOMAIN, ExportFixture::text( $this->build( Profile::FULL ) ) );
	}

	public function test_a_ticked_sensitive_record_goes_in_and_is_listed(): void {
		$content = $this->build( Profile::TECHNICAL, null, new ExportOptions( true, array( 2 ), 1 ) );
		$this->assertStringContainsString( self::SECRET_DOMAIN, ExportFixture::text( $content ) );
		$this->assertSame( array( array( 'ref' => 'item:2', 'title' => self::SECRET_DOMAIN ) ), $content['sensitive_included'] );
	}

	public function test_a_tick_by_someone_without_the_capability_counts_for_nothing(): void {
		$content = $this->build( Profile::TECHNICAL, null, new ExportOptions( false, array( 2 ), 1 ) );
		$this->assertStringNotContainsString( self::SECRET_DOMAIN, ExportFixture::text( $content ) );
		$this->assertSame( array(), $content['sensitive_included'] );
	}

	public function test_the_full_pack_is_refused_without_the_capability(): void {
		$this->assertThrowsClass( ExportRefused::class, fn() => $this->build( Profile::FULL, null, new ExportOptions( false, array(), 1 ) ) );
	}

	public function test_sensitive_field_values_of_ordinary_records_leave_every_pack_but_the_full_one(): void {
		foreach ( array( Profile::CLIENT, Profile::TECHNICAL, Profile::EMERGENCY ) as $key ) {
			$this->assertStringNotContainsString( self::SECRET_EMAIL, ExportFixture::text( $this->build( $key ) ), $key );
		}
		$this->assertStringContainsString( self::SECRET_EMAIL, ExportFixture::text( $this->build( Profile::FULL ) ) );
	}

	public function test_ticking_a_sensitive_record_does_not_open_the_sensitive_fields_of_its_neighbours(): void {
		$records = array(
			ExportFixture::record( 10, 'licence', 'Ordinary', array( 'product' => 'Ordinary', 'account_email' => self::SECRET_EMAIL ) ),
			ExportFixture::record( 11, 'licence', 'Marked', array( 'product' => 'Marked', 'account_email' => 'marked@example.test' ), array( 'sensitive' => true ) ),
		);
		$content = $this->build( Profile::TECHNICAL, $this->passport( array( 'records' => $records ) ), new ExportOptions( true, array( 11 ), 1 ) );
		$text    = ExportFixture::text( $content );
		$this->assertStringContainsString( 'marked@example.test', $text );
		$this->assertStringNotContainsString( self::SECRET_EMAIL, $text );
	}

	public function test_the_client_pack_holds_only_records_marked_visible_to_the_client(): void {
		$text = ExportFixture::text( $this->build( Profile::CLIENT ) );
		$this->assertStringContainsString( 'plain.example', $text );
		$this->assertStringContainsString( 'Plain Licence', $text );
		$this->assertStringNotContainsString( 'Not for the client', $text );
		$this->assertStringContainsString( 'Not for the client', ExportFixture::text( $this->build( Profile::TECHNICAL ) ) );
	}

	public function test_a_record_marked_hidden_is_in_no_pack(): void {
		$records = array( ExportFixture::record( 20, 'licence', 'Hidden thing', array( 'product' => 'Hidden thing', 'hidden' => 'yes' ) ) );
		foreach ( array( Profile::CLIENT, Profile::TECHNICAL ) as $key ) {
			$this->assertStringNotContainsString( 'Hidden thing', ExportFixture::text( $this->build( $key, $this->passport( array( 'records' => $records ) ) ) ), $key );
		}
	}

	public function test_a_filter_cannot_bring_back_a_sensitive_record_or_field(): void {
		$GLOBALS['estasoft_hp_test_filters']['estasoft_hp_export_sections'][] = function ( array $sections ): array {
			$sections[] = array(
				'key'    => 'sneaky',
				'title'  => 'Sneaky',
				'facts'  => array(),
				'groups' => array(
					array(
						'key'    => 'domain',
						'title'  => 'Domains',
						'fields' => array( array( 'key' => 'domain', 'label' => 'Domain', 'type' => 'text' ) ),
						'items'  => array( array( 'ref' => 'item:2', 'title' => self::SECRET_DOMAIN, 'kind' => 'domain', 'sensitive' => true, 'values' => array( 'domain' => self::SECRET_DOMAIN ), 'date' => '', 'date_label' => '' ) ),
					),
					array(
						'key'    => 'licence',
						'title'  => 'Licences',
						'fields' => array( array( 'key' => 'account_email', 'label' => 'Email', 'type' => 'email', 'sensitive' => true ) ),
						'items'  => array( array( 'ref' => 'item:3', 'title' => 'Plain Licence', 'kind' => 'licence', 'sensitive' => false, 'values' => array( 'account_email' => self::SECRET_EMAIL ), 'date' => '', 'date_label' => '' ) ),
					),
				),
			);
			return $sections;
		};
		$text = ExportFixture::text( $this->build( Profile::CLIENT ) );
		$this->assertStringNotContainsString( self::SECRET_DOMAIN, $text );
		$this->assertStringNotContainsString( self::SECRET_EMAIL, $text );
	}

	public function test_a_filter_cannot_get_a_secret_into_a_pack(): void {
		$GLOBALS['estasoft_hp_test_filters']['estasoft_hp_export_sections'][] = static function ( array $sections ): array {
			$sections[0]['groups'][0]['items'][0]['values'][ array_key_first( $sections[0]['groups'][0]['items'][0]['values'] ) ] = 'password: AKIAIOSFODNN7EXAMPLE';
			return $sections;
		};
		$this->assertThrowsClass( SecretFound::class, fn() => $this->build( Profile::TECHNICAL ) );
	}

	public function test_a_secret_in_a_record_stops_the_pack_and_the_message_does_not_repeat_it(): void {
		$records = array( ExportFixture::record( 30, 'host', 'Host Co', array( 'provider' => 'Host Co', 'plan' => 'key AKIAIOSFODNN7EXAMPLE' ) ) );
		try {
			$this->build( Profile::TECHNICAL, $this->passport( array( 'records' => $records ) ) );
			$this->fail( 'A secret should stop the pack.' );
		} catch ( SecretFound $e ) {
			$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $e->getMessage() );
			$this->assertStringContainsString( 'Host Co', $e->getMessage() );
		}
	}

	public function test_findings_about_a_sensitive_record_stay_out_whoever_is_reading(): void {
		$findings = array(
			array( 'id' => 1, 'rule_id' => 'HP-OWN-001', 'severity' => 'needs_fixing', 'message' => 'The legal owner of ' . self::SECRET_DOMAIN . ' is not known.', 'first_seen' => '2026-11-01 00:00:00', 'subject_type' => 'item', 'subject_id' => 2, 'sensitive' => false ),
			array( 'id' => 2, 'rule_id' => 'HP-OWN-001', 'severity' => 'needs_fixing', 'message' => 'The legal owner of plain.example is not known.', 'first_seen' => '2026-11-01 00:00:00', 'subject_type' => 'item', 'subject_id' => 1, 'sensitive' => false ),
			array( 'id' => 3, 'rule_id' => 'HP-OWN-001', 'severity' => 'needs_fixing', 'message' => 'The legal owner of gone.example is not known.', 'first_seen' => '2026-11-01 00:00:00', 'subject_type' => 'item', 'subject_id' => 999, 'sensitive' => false ),
			array( 'id' => 4, 'rule_id' => 'HP-SCN-001', 'severity' => 'info', 'message' => 'No scan yet.', 'first_seen' => '2026-11-01 00:00:00', 'subject_type' => 'site', 'subject_id' => 0, 'sensitive' => false ),
		);
		$text = ExportFixture::text( $this->build( Profile::TECHNICAL, $this->passport( array( 'findings' => $findings ) ) ) );
		$this->assertStringNotContainsString( self::SECRET_DOMAIN, $text );
		$this->assertStringNotContainsString( 'gone.example', $text, 'A finding about a record that is gone may have been about a Sensitive one.' );
		$this->assertStringContainsString( 'plain.example is not known', $text );
		$this->assertStringContainsString( 'No scan yet.', $text );
	}

	public function test_a_finding_about_a_ticked_sensitive_record_may_go_in(): void {
		$findings = array( array( 'id' => 1, 'rule_id' => 'HP-OWN-001', 'severity' => 'needs_fixing', 'message' => 'The legal owner of ' . self::SECRET_DOMAIN . ' is not known.', 'first_seen' => '2026-11-01 00:00:00', 'subject_type' => 'item', 'subject_id' => 2, 'sensitive' => false ) );
		$text     = ExportFixture::text( $this->build( Profile::TECHNICAL, $this->passport( array( 'findings' => $findings ) ), new ExportOptions( true, array( 2 ), 1 ) ) );
		$this->assertStringContainsString( 'The legal owner of ' . self::SECRET_DOMAIN, $text );
	}

	public function test_a_client_pack_follows_the_sharing_flags_of_each_person(): void {
		$people = array(
			ExportFixture::person( 1, 'Shared Sam', array( 'roles' => array( 'site_owner' ) ) ),
			ExportFixture::person( 2, 'Private Pat', array( 'share_flags' => array( 'name' => false, 'role' => false, 'email' => false, 'phone' => false ) ) ),
			ExportFixture::person( 3, 'Partial Pia', array( 'share_flags' => array( 'name' => true, 'role' => false, 'email' => false, 'phone' => true ) ) ),
			ExportFixture::person( 4, 'Gone Gus', array( 'left_on' => '2026-01-01' ) ),
		);
		$text = ExportFixture::text( $this->build( Profile::CLIENT, $this->passport( array( 'people' => $people ) ) ) );
		$this->assertStringContainsString( 'Shared Sam', $text );
		$this->assertStringContainsString( 'shared.sam@example.test', $text );
		$this->assertStringNotContainsString( 'Private Pat', $text );
		$this->assertStringNotContainsString( 'private.pat@example.test', $text );
		$this->assertStringContainsString( 'Partial Pia', $text );
		$this->assertStringNotContainsString( 'partial.pia@example.test', $text, 'Email is not shared.' );
		$this->assertStringContainsString( '+1000003', $text, 'Phone is shared.' );
		$this->assertStringNotContainsString( 'Gone Gus', $text, 'A person who left is not in a client pack.' );
	}

	public function test_a_technical_pack_lists_everyone_even_those_who_left(): void {
		$people = array( ExportFixture::person( 4, 'Gone Gus', array( 'left_on' => '2026-01-01' ) ), ExportFixture::person( 2, 'Private Pat', array( 'share_flags' => array() ) ) );
		$text   = ExportFixture::text( $this->build( Profile::TECHNICAL, $this->passport( array( 'people' => $people ) ) ) );
		$this->assertStringContainsString( 'Gone Gus', $text );
		$this->assertStringContainsString( 'Private Pat', $text );
	}

	public function test_the_emergency_sheet_cuts_long_lists_and_says_so(): void {
		$people = array();
		$ids    = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$people[] = ExportFixture::person( $i, 'Contact ' . $i );
			$ids[]    = $i;
		}
		$content = $this->build( Profile::EMERGENCY, $this->passport( array( 'people' => $people, 'roles' => array( 'emergency_contact' => $ids ) ) ) );
		$first   = $content['sections'][0];
		$this->assertSame( 'emergency_contacts', $first['key'] );
		$this->assertCount( 6, $first['groups'][0]['items'] );
		$this->assertNotEmpty( $content['left_out'] );
		$this->assertSame( 'Contact 1', $first['groups'][0]['items'][0]['title'] );
	}

	public function test_the_emergency_sheet_shows_a_record_only_when_marked_for_it(): void {
		$records = array(
			ExportFixture::record( 40, 'host', 'On the sheet', array( 'provider' => 'On the sheet' ) ),
			ExportFixture::record( 41, 'host', 'Off the sheet', array( 'provider' => 'Off the sheet' ), array( 'vis_emergency' => false ) ),
		);
		$text = ExportFixture::text( $this->build( Profile::EMERGENCY, $this->passport( array( 'records' => $records ) ) ) );
		$this->assertStringContainsString( 'On the sheet', $text );
		$this->assertStringNotContainsString( 'Off the sheet', $text );
	}

	public function test_notes_to_the_successor_go_only_into_packs_that_carry_them(): void {
		$data = $this->passport( array( 'notes' => 'Beware of the cron job.' ) );
		$this->assertSame( 'Beware of the cron job.', $this->build( Profile::TECHNICAL, $data )['notes'] );
		$this->assertSame( '', $this->build( Profile::EMERGENCY, $data )['notes'] );
	}

	public function test_runbook_placeholders_are_filled_and_gaps_are_visible(): void {
		$book = array( 'id' => 7, 'title' => 'Move site', 'status' => 'published', 'purpose' => 'Move {site_name} from {host_name}', 'when_to_use' => '', 'owner_role' => 'technical_maintainer', 'estimated_minutes' => 30, 'last_rehearsed' => '', 'prerequisites' => array(), 'steps' => array( array( 'text' => 'Open {registrar} and ask {nobody}', 'who' => '', 'minutes' => 5, 'warning' => false ) ), 'verification' => array(), 'rollback_text' => 'Call {emergency_contact}', 'critical' => false );
		$text = ExportFixture::text( $this->build( Profile::TECHNICAL, $this->passport( array( 'runbooks' => array( $book ) ) ) ) );
		$this->assertStringContainsString( 'Move Fixture Site from Host Co', $text );
		$this->assertStringContainsString( '{nobody}', $text, 'A word that is not a placeholder is kept.' );
		$this->assertStringContainsString( '[emergency_contact: not recorded]', $text );
	}

	public function test_a_sensitive_record_never_supplies_a_placeholder(): void {
		$records = array( ExportFixture::record( 50, 'host', 'Secret Host', array( 'provider' => 'Secret Host' ), array( 'sensitive' => true ) ) );
		$book    = array( 'id' => 7, 'title' => 'Move', 'status' => 'published', 'purpose' => 'Host is {host_name}', 'when_to_use' => '', 'owner_role' => '', 'estimated_minutes' => 0, 'last_rehearsed' => '', 'prerequisites' => array(), 'steps' => array(), 'verification' => array(), 'rollback_text' => '', 'critical' => false );
		$text    = ExportFixture::text( $this->build( Profile::FULL, $this->passport( array( 'records' => $records, 'runbooks' => array( $book ) ) ) ) );
		$this->assertStringContainsString( 'Host is [host_name: not recorded]', $text );
	}

	public function test_a_client_pack_lists_the_purpose_of_a_runbook_but_not_its_steps(): void {
		$book = array( 'id' => 7, 'title' => 'Restore', 'status' => 'published', 'purpose' => 'Bring the site back', 'when_to_use' => '', 'owner_role' => '', 'estimated_minutes' => 0, 'last_rehearsed' => '', 'prerequisites' => array(), 'steps' => array( array( 'text' => 'Run the secret internal command' ) ), 'verification' => array(), 'rollback_text' => '', 'critical' => false );
		$draft = array_merge( $book, array( 'id' => 8, 'title' => 'Draft one', 'status' => 'draft' ) );
		$text  = ExportFixture::text( $this->build( Profile::CLIENT, $this->passport( array( 'runbooks' => array( $book, $draft ) ) ) ) );
		$this->assertStringContainsString( 'Bring the site back', $text );
		$this->assertStringNotContainsString( 'secret internal command', $text );
		$this->assertStringNotContainsString( 'Draft one', $text );
	}

	public function test_a_chapter_with_nothing_in_it_is_left_out(): void {
		$content = $this->build( Profile::CLIENT, ExportFixture::data() );
		$keys    = array_column( $content['sections'], 'key' );
		$this->assertSame( array( 'passport' ), $keys );
	}

	public function test_a_value_with_no_visible_field_is_not_in_the_content_at_all(): void {
		$people = array( ExportFixture::person( 1, 'Shared Sam', array( 'note' => 'private note about Sam', 'language' => 'xx-private' ) ) );
		$text   = ExportFixture::text( $this->build( Profile::CLIENT, $this->passport( array( 'people' => $people ) ) ) );
		$this->assertStringContainsString( 'Shared Sam', $text );
		$this->assertStringNotContainsString( 'private note about Sam', $text );
		$this->assertStringNotContainsString( 'xx-private', $text );
	}

	public function test_a_filter_cannot_add_a_value_that_no_field_shows(): void {
		$GLOBALS['estasoft_hp_test_filters']['estasoft_hp_export_sections'][] = static function ( array $sections ): array {
			$sections[0]['groups'][0]['items'][0]['values']['stray'] = 'stray value';
			return $sections;
		};
		$this->assertStringNotContainsString( 'stray value', ExportFixture::text( $this->build( Profile::TECHNICAL ) ) );
	}
}
