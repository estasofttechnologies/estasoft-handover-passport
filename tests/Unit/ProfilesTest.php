<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Export\Placeholders;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Export\Profiles;

final class ProfilesTest extends UnitTestCase {

	public function test_four_packs_ship(): void {
		$this->assertSame( array( 'client', 'technical', 'full', 'emergency' ), array_keys( Profiles::builtin() ) );
	}

	public function test_only_the_full_pack_needs_the_sensitive_capability(): void {
		foreach ( Profiles::builtin() as $key => $profile ) {
			$this->assertSame( 'full' === $key, $profile->needsSensitiveCapability(), $key );
		}
	}

	public function test_client_and_emergency_packs_follow_visibility_flags_the_others_do_not(): void {
		$all = Profiles::builtin();
		$this->assertTrue( $all['client']->usesVisibilityFlags() );
		$this->assertTrue( $all['emergency']->usesVisibilityFlags() );
		$this->assertFalse( $all['technical']->usesVisibilityFlags() );
		$this->assertFalse( $all['full']->usesVisibilityFlags() );
	}

	public function test_only_the_emergency_sheet_is_one_page(): void {
		foreach ( Profiles::builtin() as $key => $profile ) {
			$this->assertSame( 'emergency' === $key, $profile->isOnePage(), $key );
		}
	}

	public function test_every_chapter_a_pack_lists_exists(): void {
		$known = array_keys( Profiles::chapters() );
		foreach ( Profiles::builtin() as $key => $profile ) {
			$this->assertSame( array(), array_diff( $profile->sections, $known ), $key );
		}
	}

	public function test_a_copy_keeps_the_rules_of_the_pack_it_came_from(): void {
		$profiles = new Profiles( array( 'mine' => array( 'label' => 'Mine', 'base' => 'client', 'sections' => array( 'passport', 'domains', 'findings' ) ) ) );
		$mine     = $profiles->get( 'mine' );
		$this->assertNotNull( $mine );
		$this->assertSame( 'client', $mine->audience );
		$this->assertFalse( $mine->builtin );
		$this->assertSame( array( 'passport', 'domains', 'findings' ), $mine->sections );
	}

	public function test_a_copy_cannot_take_the_key_of_a_shipped_pack_or_a_base_that_does_not_exist(): void {
		$profiles = new Profiles(
			array(
				'client' => array( 'label' => 'Fake', 'base' => 'full', 'sections' => array( 'passport' ) ),
				'orphan' => array( 'label' => 'Orphan', 'base' => 'nothing', 'sections' => array( 'passport' ) ),
			)
		);
		$this->assertSame( 'client', $profiles->get( 'client' )->audience );
		$this->assertNull( $profiles->get( 'orphan' ) );
	}

	public function test_a_copy_drops_chapters_that_do_not_exist_and_is_ignored_when_none_is_left(): void {
		$profiles = new Profiles(
			array(
				'odd'   => array( 'label' => 'Odd', 'base' => 'technical', 'sections' => array( 'passport', 'made_up' ) ),
				'empty' => array( 'label' => 'Empty', 'base' => 'technical', 'sections' => array( 'made_up' ) ),
			)
		);
		$this->assertSame( array( 'passport' ), $profiles->get( 'odd' )->sections );
		$this->assertNull( $profiles->get( 'empty' ) );
	}

	public function test_a_copy_of_the_full_pack_still_needs_the_capability(): void {
		$profiles = new Profiles( array( 'inside' => array( 'label' => 'Inside', 'base' => 'full', 'sections' => array( 'passport' ) ) ) );
		$this->assertTrue( $profiles->get( 'inside' )->needsSensitiveCapability() );
		$this->assertSame( Profile::FULL, $profiles->get( 'inside' )->audience );
	}

	public function test_an_unknown_pack_is_null(): void {
		$this->assertNull( ( new Profiles() )->get( 'nope' ) );
	}

	public function test_placeholders_are_filled_and_unknown_words_are_left_alone(): void {
		$values = array( 'site_name' => 'Acme', 'registrar' => '' );
		$this->assertSame( 'Hello Acme', Placeholders::fill( 'Hello {site_name}', $values ) );
		$this->assertSame( 'Ask [registrar: not recorded]', Placeholders::fill( 'Ask {registrar}', $values ) );
		$this->assertSame( 'Keep {unknown_word} and {braces}', Placeholders::fill( 'Keep {unknown_word} and {braces}', $values ) );
		$this->assertSame( 'No braces', Placeholders::fill( 'No braces', $values ) );
		$this->assertSame( '{SITE_NAME}', Placeholders::fill( '{SITE_NAME}', $values ), 'Placeholders are lower case.' );
	}

	public function test_placeholder_values_come_from_the_passport_and_skip_sensitive_records(): void {
		$records = array(
			ExportFixture::record( 1, 'domain', 'old.example', array( 'domain' => 'old.example', 'registrar' => 'Old Reg', 'role' => 'redirect' ) ),
			ExportFixture::record( 2, 'domain', 'main.example', array( 'domain' => 'main.example', 'registrar' => 'Main Reg', 'role' => 'primary' ) ),
			ExportFixture::record( 3, 'domain', 'hidden.example', array( 'domain' => 'hidden.example', 'registrar' => 'Hidden Reg', 'role' => 'primary' ), array( 'sensitive' => true ) ),
			ExportFixture::record( 4, 'host', 'Host Co', array( 'provider' => 'Host Co' ) ),
			ExportFixture::record( 5, 'backup', 'Nightly', array( 'name' => 'Nightly', 'stored_at' => 'The vault' ) ),
		);
		$people = array( ExportFixture::person( 1, 'Olive Owner' ), ExportFixture::person( 2, 'Max Maker' ) );
		$data   = ExportFixture::data( array( 'records' => $records, 'people' => $people, 'roles' => array( 'site_owner' => array( 1 ), 'technical_maintainer' => array( 2 ) ), 'tests' => array( array( 'id' => 1, 'test_date' => '2026-09-01', 'result' => 'pass' ), array( 'id' => 2, 'test_date' => '2026-10-01', 'result' => 'fail' ) ) ) );
		$values = Placeholders::values( $data );
		$this->assertSame( 'https://fixture.example', $values['site_url'] );
		$this->assertSame( 'main.example', $values['domain'], 'The primary domain wins, and a Sensitive one never counts.' );
		$this->assertSame( 'Main Reg', $values['registrar'] );
		$this->assertSame( 'Host Co', $values['host_name'] );
		$this->assertSame( 'The vault', $values['backup_location'] );
		$this->assertSame( 'Olive Owner', $values['owner_name'] );
		$this->assertSame( 'Max Maker', $values['maintainer_name'] );
		$this->assertSame( '2026-09-01', $values['last_restore_test'], 'Only a test that passed counts.' );
	}

	public function test_a_person_who_does_not_share_their_name_gives_none_to_a_client_pack(): void {
		$people = array( ExportFixture::person( 1, 'Private Pat', array( 'share_flags' => array( 'name' => false ) ) ) );
		$data   = ExportFixture::data( array( 'people' => $people, 'roles' => array( 'emergency_contact' => array( 1 ) ) ) );
		$this->assertSame( 'Private Pat', Placeholders::values( $data )['emergency_contact'] );
		$this->assertSame( '', Placeholders::values( $data, Profiles::builtin()['client'] )['emergency_contact'] );
	}
}
