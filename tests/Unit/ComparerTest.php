<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Handover\Comparer;

final class ComparerTest extends UnitTestCase {

	private function content( array $items, string $notes = '' ): array {
		$fields = array(
			array( 'key' => 'domain', 'label' => 'Domain', 'type' => 'text' ),
			array( 'key' => 'expires_on', 'label' => 'Expires on', 'type' => 'date' ),
			array( 'key' => 'account_email', 'label' => 'Account email', 'type' => 'email', 'sensitive' => true ),
		);
		return array(
			'notes'    => $notes,
			'sections' => array( array( 'key' => 'domains', 'title' => 'Domains', 'groups' => array( array( 'key' => 'domain', 'title' => 'Domains', 'fields' => $fields, 'items' => $items ) ) ) ),
		);
	}

	private function item( int $id, string $title, array $values = array(), bool $sensitive = false ): array {
		return array( 'ref' => 'item:' . $id, 'title' => $title, 'sensitive' => $sensitive, 'values' => array_merge( array( 'domain' => $title, 'expires_on' => '2027-01-01' ), $values ) );
	}

	public function test_identical_contents_have_no_differences(): void {
		$a    = $this->content( array( $this->item( 1, 'a.example' ), $this->item( 2, 'b.example' ) ) );
		$diff = Comparer::diff( $a, $a );
		$this->assertSame( array(), $diff['added'] );
		$this->assertSame( array(), $diff['removed'] );
		$this->assertSame( array(), $diff['changed'] );
		$this->assertSame( 2, $diff['unchanged'] );
		$this->assertFalse( $diff['notes_changed'] );
	}

	public function test_added_removed_and_changed_items_are_listed(): void {
		$old  = $this->content( array( $this->item( 1, 'a.example' ), $this->item( 2, 'b.example' ), $this->item( 3, 'c.example' ) ) );
		$new  = $this->content( array( $this->item( 1, 'a.example' ), $this->item( 3, 'c.example', array( 'expires_on' => '2028-05-05' ) ), $this->item( 4, 'd.example' ) ) );
		$diff = Comparer::diff( $old, $new );
		$this->assertSame( array( 'd.example' ), array_column( $diff['added'], 'title' ) );
		$this->assertSame( array( 'b.example' ), array_column( $diff['removed'], 'title' ) );
		$this->assertSame( array( 'c.example' ), array_column( $diff['changed'], 'title' ) );
		$this->assertSame( 1, $diff['unchanged'] );
		$field = $diff['changed'][0]['fields'][0];
		$this->assertSame( 'Expires on', $field['label'] );
		$this->assertSame( '2027-01-01', $field['before'] );
		$this->assertSame( '2028-05-05', $field['after'] );
	}

	public function test_an_empty_text_and_an_empty_list_are_the_same(): void {
		$old  = $this->content( array( $this->item( 1, 'a.example', array( 'note' => '' ) ) ) );
		$new  = $this->content( array( $this->item( 1, 'a.example', array( 'note' => array() ) ) ) );
		$diff = Comparer::diff( $old, $new );
		$this->assertSame( array(), $diff['changed'] );
	}

	public function test_a_sensitive_field_is_reported_as_changed_without_either_value(): void {
		$old  = $this->content( array( $this->item( 1, 'a.example', array( 'account_email' => 'old-secret@example.test' ) ) ) );
		$new  = $this->content( array( $this->item( 1, 'a.example', array( 'account_email' => 'new-secret@example.test' ) ) ) );
		$diff = Comparer::diff( $old, $new );
		$this->assertCount( 1, $diff['changed'] );
		$field = $diff['changed'][0]['fields'][0];
		$this->assertTrue( $field['masked'] );
		$this->assertSame( '', $field['before'] );
		$this->assertSame( '', $field['after'] );
		$this->assertStringNotContainsString( 'secret@example.test', ExportFixture::text( $diff ) );
	}

	public function test_a_sensitive_record_is_listed_without_its_name_or_values(): void {
		$old  = $this->content( array() );
		$new  = $this->content( array( $this->item( 9, 'hidden.example', array(), true ) ) );
		$diff = Comparer::diff( $old, $new );
		$this->assertCount( 1, $diff['added'] );
		$this->assertSame( '', $diff['added'][0]['title'] );
		$this->assertStringNotContainsString( 'hidden.example', ExportFixture::text( $diff ) );
		$back = Comparer::diff( $new, $old );
		$this->assertSame( '', $back['removed'][0]['title'] );
	}

	public function test_changes_to_a_sensitive_record_carry_no_values(): void {
		$old  = $this->content( array( $this->item( 9, 'hidden.example', array( 'expires_on' => '2027-01-01' ), true ) ) );
		$new  = $this->content( array( $this->item( 9, 'hidden.example', array( 'expires_on' => '2030-01-01' ), true ) ) );
		$diff = Comparer::diff( $old, $new );
		$this->assertCount( 1, $diff['changed'] );
		$this->assertTrue( $diff['changed'][0]['fields'][0]['masked'] );
		$this->assertStringNotContainsString( '2030-01-01', ExportFixture::text( $diff ) );
	}

	public function test_a_change_to_the_notes_is_reported(): void {
		$diff = Comparer::diff( $this->content( array(), 'one' ), $this->content( array(), 'two' ) );
		$this->assertTrue( $diff['notes_changed'] );
	}

	public function test_sensitive_records_can_be_cut_out_before_comparing(): void {
		$content = $this->content( array( $this->item( 1, 'a.example' ), $this->item( 9, 'hidden.example', array(), true ), $this->item( 8, 'also.example', array(), true ) ) );
		$cut     = Comparer::withoutSensitive( $content );
		$this->assertSame( 2, $cut['removed'] );
		$this->assertSame( array( 'a.example' ), array_column( $cut['content']['sections'][0]['groups'][0]['items'], 'title' ) );
	}

	public function test_the_same_ref_in_a_different_chapter_is_a_different_item(): void {
		$one = $this->content( array( $this->item( 1, 'a.example' ) ) );
		$two = $one;
		$two['sections'][0]['key'] = 'dns';
		$diff = Comparer::diff( $one, $two );
		$this->assertCount( 1, $diff['added'] );
		$this->assertCount( 1, $diff['removed'] );
	}
}
