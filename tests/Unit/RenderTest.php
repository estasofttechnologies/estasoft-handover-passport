<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Export\ContentBuilder;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Export\Profiles;
use Estasoft\HandoverPassport\Export\Render\CsvRenderer;
use Estasoft\HandoverPassport\Export\Render\HtmlRenderer;
use Estasoft\HandoverPassport\Export\Render\IcsRenderer;
use Estasoft\HandoverPassport\Export\Render\JsonRenderer;
use Estasoft\HandoverPassport\Export\Render\Layout;
use Estasoft\HandoverPassport\Export\Render\MarkdownRenderer;
use Estasoft\HandoverPassport\Export\Render\PackStyle;
use Estasoft\HandoverPassport\Export\Render\SmallMarkdown;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Json;

final class RenderTest extends UnitTestCase {

	private const SENTINEL_DOMAIN = 'sentinel-secret-domain.example';
	private const SENTINEL_NOTE   = 'SENTINEL-NOTE-9f3a';
	private const SENTINEL_EMAIL  = 'sentinel-admin@example.test';

	private function data( array $extra = array() ) {
		$records = array(
			ExportFixture::record( 1, 'domain', 'plain.example', array( 'domain' => 'plain.example', 'registrar' => 'Reg, Co; "Quoted"', 'expires_on' => '2027-01-01' ), array( 'due_date' => '2027-01-01' ) ),
			ExportFixture::record( 2, 'domain', self::SENTINEL_DOMAIN, array( 'domain' => self::SENTINEL_DOMAIN, 'note' => self::SENTINEL_NOTE, 'expires_on' => '2027-02-01' ), array( 'sensitive' => true, 'due_date' => '2027-02-01' ) ),
			ExportFixture::record( 3, 'licence', 'Formula Licence', array( 'product' => '=HYPERLINK("http://evil.example","x")', 'vendor' => '@SUM(1)', 'renews_on' => '2027-03-01', 'account_email' => self::SENTINEL_EMAIL ), array( 'due_date' => '2027-03-01' ) ),
			ExportFixture::record( 4, 'host', 'Host <script>alert(1)</script>', array( 'provider' => 'Host <script>alert(1)</script>', 'plan' => 'a | b', 'support_address' => 'help@host.example' ) ),
		);
		return ExportFixture::data( array_merge( array( 'records' => $records ), $extra ) );
	}

	private function content( string $profile = Profile::CLIENT, $data = null, ?ExportOptions $options = null ): array {
		return ( new ContentBuilder( new SecretGuard(), '1.0.0' ) )->build( ( new Profiles() )->get( $profile ), $data ?? $this->data(), $options ?? new ExportOptions( true, array(), 1 ) );
	}

	private function html( array $content, ?PackStyle $style = null, string $mode = HtmlRenderer::MODE_FILE ): string {
		return ( new HtmlRenderer( $mode ) )->render( $content, $style ?? new PackStyle(), hash( 'sha256', Json::canonical( $content ) ), 'Test pack' );
	}

	public function test_html_escapes_text_and_holds_no_script_or_remote_address(): void {
		$html = $this->html( $this->content( Profile::TECHNICAL ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<link', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertSame( 0, preg_match( '/(?:src|href)="https?:/i', $html ), 'No remote file is loaded.' );
		$this->assertSame( 0, preg_match( '/\bon[a-z]+\s*=/i', $html ), 'No event handler attributes.' );
	}

	public function test_html_is_one_complete_document_with_inline_styles(): void {
		$html = $this->html( $this->content( Profile::TECHNICAL ) );
		$this->assertTrue( str_starts_with( $html, '<!DOCTYPE html>' ) );
		$this->assertStringContainsString( '<style>', $html );
		$this->assertStringContainsString( '<meta charset="utf-8">', $html );
		$this->assertStringContainsString( '<title>Fixture Site</title>', $html );
	}

	public function test_the_pack_title_cover_text_and_footer_come_from_the_settings(): void {
		$style = PackStyle::fromSettings( array( 'title' => 'Acme handover', 'cover_text' => "Line one\nLine two", 'footer_text' => 'Confidential', 'page_size' => 'Letter', 'credit' => false ) );
		$html  = $this->html( $this->content( Profile::TECHNICAL ), $style );
		$this->assertStringContainsString( '<h1>Acme handover</h1>', $html );
		$this->assertStringContainsString( "Line one<br>\nLine two", $html );
		$this->assertStringContainsString( 'Confidential', $html );
		$this->assertStringContainsString( 'size:Letter', $html );
	}

	public function test_the_credit_line_is_off_unless_switched_on(): void {
		$content = $this->content( Profile::TECHNICAL );
		$this->assertStringNotContainsString( 'ESTASOFT TECHNOLOGIES', $this->html( $content ) );
		$this->assertStringContainsString( 'ESTASOFT TECHNOLOGIES', $this->html( $content, PackStyle::fromSettings( array( 'credit' => true ) ) ) );
	}

	public function test_a_colour_that_is_not_a_hex_colour_is_replaced(): void {
		$style = new PackStyle( brand_color: 'red;}</style><script>x</script>' );
		$html  = $this->html( $this->content( Profile::TECHNICAL ), $style );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '#2f4858', $html );
		$this->assertSame( '#2f4858', PackStyle::fromSettings( array(), 'blue' )->brand_color );
		$this->assertSame( '#aabbcc', PackStyle::fromSettings( array(), '#AABBCC' )->brand_color );
	}

	public function test_only_an_image_data_address_is_accepted_as_a_logo(): void {
		$this->assertSame( '', PackStyle::fromSettings( array(), '', 'https://evil.example/logo.png' )->logo_data_uri );
		$this->assertSame( '', PackStyle::fromSettings( array(), '', 'javascript:alert(1)' )->logo_data_uri );
		$this->assertSame( 'data:image/png;base64,AAAA', PackStyle::fromSettings( array(), '', 'data:image/png;base64,AAAA' )->logo_data_uri );
	}

	public function test_text_that_runs_right_to_left_is_marked_so_in_a_left_to_right_page(): void {
		$records = array( ExportFixture::record( 1, 'host', 'استضافة الموقع', array( 'provider' => 'استضافة الموقع', 'plan' => 'Premium' ) ) );
		$html    = $this->html( $this->content( Profile::TECHNICAL, $this->data( array( 'records' => $records ) ) ) );
		$this->assertStringContainsString( 'dir="rtl"', $html );
		$this->assertStringContainsString( 'استضافة الموقع', $html );
		$this->assertStringContainsString( '<html lang="en" dir="ltr">', $html );
	}

	public function test_latin_text_is_marked_left_to_right_in_a_right_to_left_page(): void {
		$records = array( ExportFixture::record( 1, 'host', 'Host Co', array( 'provider' => 'Host Co', 'plan' => 'Premium' ) ) );
		$style   = PackStyle::fromSettings( array(), '', '', 'ar', true );
		$html    = $this->html( $this->content( Profile::TECHNICAL, $this->data( array( 'records' => $records ) ) ), $style );
		$this->assertStringContainsString( '<html lang="ar" dir="rtl">', $html );
		$this->assertStringContainsString( '<td dir="ltr"><strong>Host Co</strong>', $html );
	}

	public function test_the_first_strong_letter_decides_the_direction(): void {
		$this->assertSame( 'rtl', Layout::direction( 'مرحبا hello' ) );
		$this->assertSame( 'ltr', Layout::direction( 'hello مرحبا' ) );
		$this->assertSame( 'rtl', Layout::direction( '123 שלום' ) );
		$this->assertSame( 'ltr', Layout::direction( '12345' ) );
		$this->assertSame( 'ltr', Layout::direction( 'Привет' ) );
		$this->assertSame( 'ltr', Layout::direction( '你好' ) );
	}

	public function test_the_closing_list_of_sensitive_records_says_none_or_names_them(): void {
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $this->html( $this->content( Profile::CLIENT ) ) );
		$ticked = $this->html( $this->content( Profile::TECHNICAL, null, new ExportOptions( true, array( 2 ), 1 ) ) );
		$this->assertStringContainsString( 'This pack includes this Sensitive record', $ticked );
		$this->assertStringContainsString( '<span class="tag">Sensitive</span>', $ticked );
	}

	public function test_the_notes_use_a_small_markdown_subset_and_cannot_add_markup(): void {
		$html = SmallMarkdown::toHtml( "First **do** this\n\n- one\n- two with `code`\n\n<img src=x onerror=alert(1)> and [a link](https://example.test/a?b=1&c=2) and [bad](javascript:alert(1))" );
		$this->assertStringContainsString( '<strong>do</strong>', $html );
		$this->assertStringContainsString( '<ul><li>one</li><li>two with <code>code</code></li></ul>', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( '<a href="https://example.test/a?b=1&amp;c=2">a link</a>', $html );
		$this->assertStringNotContainsString( 'href="javascript', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_the_notes_are_shown_in_a_pack_that_carries_them(): void {
		$html = $this->html( $this->content( Profile::TECHNICAL, $this->data( array( 'notes' => 'Watch the **cron** job' ) ) ) );
		$this->assertStringContainsString( 'Notes from the sender', $html );
		$this->assertStringContainsString( 'Watch the <strong>cron</strong> job', $html );
	}

	public function test_the_footer_names_the_pack_the_date_and_a_fingerprint_of_the_content(): void {
		$content = $this->content( Profile::TECHNICAL );
		$hash    = hash( 'sha256', Json::canonical( $content ) );
		$html    = $this->html( $content );
		$this->assertStringContainsString( 'Test pack', $html );
		$this->assertStringContainsString( 'Fingerprint ' . substr( $hash, 0, 16 ), $html );
	}

	public function test_the_pdf_page_has_no_footer_of_its_own_because_the_library_adds_one_to_every_page(): void {
		$html = $this->html( $this->content( Profile::TECHNICAL ), null, HtmlRenderer::MODE_PDF );
		$this->assertStringNotContainsString( 'Fingerprint', $html );
		$this->assertStringContainsString( '<tocentry', $html );
	}

	public function test_the_print_view_says_how_to_save_a_pdf(): void {
		$this->assertStringContainsString( 'Save as PDF', $this->html( $this->content( Profile::TECHNICAL ), null, HtmlRenderer::MODE_PRINT ) );
		$this->assertStringNotContainsString( 'Save as PDF', $this->html( $this->content( Profile::TECHNICAL ) ) );
	}

	public function test_a_column_nobody_fills_in_is_left_out_and_wide_groups_become_cards(): void {
		$content = $this->content( Profile::TECHNICAL );
		$domain  = null;
		foreach ( $content['sections'] as $section ) {
			foreach ( $section['groups'] as $group ) {
				if ( 'domain' === $group['key'] ) {
					$domain = $group;
				}
			}
		}
		$this->assertNotNull( $domain );
		$labels = array_column( Layout::columns( $domain ), 'label' );
		$this->assertContains( 'Registrar', $labels );
		$this->assertNotContains( 'DKIM selector', $labels );
		$this->assertNotContains( 'Domain', $labels, 'A column that only repeats the title is left out.' );
	}

	public function test_markdown_escapes_marks_tags_and_table_breaks(): void {
		$md = ( new MarkdownRenderer() )->render( $this->content( Profile::TECHNICAL ), new PackStyle(), 'abc', 'Test pack' );
		$this->assertStringNotContainsString( '<script>', $md );
		$this->assertStringContainsString( '&lt;script&gt;', $md );
		$this->assertStringContainsString( 'a \\| b', $md );
		$this->assertStringContainsString( '# Fixture Site', $md );
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $md );
	}

	public function test_json_is_the_content_with_its_schema_version(): void {
		$content = $this->content( Profile::TECHNICAL );
		$json    = ( new JsonRenderer() )->render( $content, new PackStyle(), 'abc', 'x' );
		$back    = json_decode( $json, true );
		$this->assertSame( 'estasoft-handover-passport', $back['schema'] );
		$this->assertSame( '1', $back['schema_version'] );
		$this->assertSame( Json::canonical( $content ), Json::canonical( $back ) );
	}

	public function test_json_does_not_change_with_the_look_and_wording_settings(): void {
		$content = $this->content( Profile::TECHNICAL );
		$a       = ( new JsonRenderer() )->render( $content, new PackStyle(), 'abc', 'x' );
		$b       = ( new JsonRenderer() )->render( $content, PackStyle::fromSettings( array( 'title' => 'Other', 'credit' => true ) ), 'def', 'y' );
		$this->assertSame( $a, $b );
	}

	public function test_csv_makes_one_file_per_group_with_a_readme(): void {
		$files = ( new CsvRenderer() )->files( $this->content( Profile::TECHNICAL ), new PackStyle(), 'abc', 'Test pack' );
		$names = array_keys( $files );
		$this->assertContains( 'README.txt', $names );
		$this->assertTrue( 0 < count( array_filter( $names, static fn( string $n ): bool => 1 === preg_match( '/^\d\d-domains-domain\.csv$/', $n ) ) ) );
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $files['README.txt'] );
		foreach ( $names as $name ) {
			$this->assertSame( 1, preg_match( '/^[A-Za-z0-9._-]+$/', $name ), 'File names are plain: ' . $name );
		}
	}

	public function test_csv_cells_that_a_spreadsheet_would_read_as_formulas_get_a_quote(): void {
		$files = ( new CsvRenderer() )->files( $this->content( Profile::TECHNICAL ), new PackStyle(), 'abc', 'x' );
		$text  = '';
		foreach ( $files as $name => $body ) {
			if ( str_ends_with( $name, '.csv' ) ) {
				$text .= $body;
			}
		}
		$this->assertStringContainsString( "'=HYPERLINK", $text );
		$this->assertStringContainsString( "'@SUM(1)", $text );
		$this->assertSame( 0, preg_match( '/(^|,|")=HYPERLINK/m', $text ), 'No cell starts with the formula itself.' );
		$this->assertSame( "'=1+1", CsvRenderer::safe( '=1+1' ) );
		$this->assertSame( "'+1", CsvRenderer::safe( '+1' ) );
		$this->assertSame( "'-1", CsvRenderer::safe( '-1' ) );
		$this->assertSame( "'@x", CsvRenderer::safe( '@x' ) );
		$this->assertSame( "'\tx", CsvRenderer::safe( "\tx" ) );
		$this->assertSame( 'plain', CsvRenderer::safe( 'plain' ) );
		$this->assertSame( '', CsvRenderer::safe( '' ) );
	}

	public function test_csv_is_utf_8_with_a_byte_order_mark_and_quotes_commas(): void {
		$files = ( new CsvRenderer() )->files( $this->content( Profile::TECHNICAL ), new PackStyle(), 'abc', 'x' );
		$domain = '';
		foreach ( $files as $name => $body ) {
			if ( str_contains( $name, '-domain.csv' ) ) {
				$domain = $body;
			}
		}
		$this->assertTrue( str_starts_with( $domain, "\xEF\xBB\xBF" ) );
		$this->assertStringContainsString( '"Reg, Co; ""Quoted"""', $domain );
		$this->assertStringContainsString( '2027-01-01', $domain, 'Dates are written in ISO form.' );
	}

	public function test_calendar_has_an_event_for_each_dated_item_and_valid_lines(): void {
		$ics = ( new IcsRenderer() )->render( $this->content( Profile::TECHNICAL ), new PackStyle(), 'abc', 'x' );
		$this->assertTrue( str_starts_with( $ics, "BEGIN:VCALENDAR\r\n" ) );
		$this->assertTrue( str_ends_with( $ics, "END:VCALENDAR\r\n" ) );
		$this->assertSame( 2, substr_count( $ics, 'BEGIN:VEVENT' ), 'Two items have a date in the Client-safe data set.' );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20270101', $ics );
		$this->assertStringContainsString( 'DTEND;VALUE=DATE:20270102', $ics );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertTrue( strlen( $line ) <= 75, 'Line over 75 bytes: ' . $line );
		}
	}

	public function test_calendar_text_is_escaped_and_long_lines_are_folded_without_splitting_a_character(): void {
		$long    = str_repeat( 'é', 90 );
		$records = array( ExportFixture::record( 1, 'domain', $long . '; a, b', array( 'domain' => $long, 'expires_on' => '2027-01-01' ), array( 'due_date' => '2027-01-01' ) ) );
		$ics     = ( new IcsRenderer() )->render( $this->content( Profile::TECHNICAL, $this->data( array( 'records' => $records ) ) ), new PackStyle(), 'abc', 'x' );
		$this->assertStringContainsString( '\;', $ics );
		$this->assertStringContainsString( '\\,', $ics );
		$unfolded = str_replace( "\r\n ", '', $ics );
		$this->assertStringContainsString( str_repeat( 'é', 90 ), $unfolded );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertTrue( strlen( $line ) <= 75 );
			$this->assertSame( 1, preg_match( '//u', $line ), 'Every line is valid UTF-8.' );
		}
	}

	public function test_calendar_event_ids_stay_the_same_between_runs(): void {
		$content = $this->content( Profile::TECHNICAL );
		$a       = ( new IcsRenderer() )->render( $content, new PackStyle(), 'abc', 'x' );
		$b       = ( new IcsRenderer() )->render( $content, new PackStyle(), 'abc', 'x' );
		$this->assertSame( $a, $b );
		$this->assertStringContainsString( '@fixture.example', $a );
	}

	public function test_no_client_pack_format_holds_a_sensitive_value(): void {
		$content = $this->content( Profile::CLIENT );
		$outputs = array(
			'html' => $this->html( $content ),
			'md'   => ( new MarkdownRenderer() )->render( $content, new PackStyle(), 'abc', 'x' ),
			'json' => ( new JsonRenderer() )->render( $content, new PackStyle(), 'abc', 'x' ),
			'ics'  => ( new IcsRenderer() )->render( $content, new PackStyle(), 'abc', 'x' ),
			'csv'  => implode( "\n", ( new CsvRenderer() )->files( $content, new PackStyle(), 'abc', 'x' ) ),
		);
		foreach ( $outputs as $format => $text ) {
			$this->assertStringNotContainsString( self::SENTINEL_DOMAIN, $text, $format );
			$this->assertStringNotContainsString( self::SENTINEL_NOTE, $text, $format );
			$this->assertStringNotContainsString( self::SENTINEL_EMAIL, $text, $format );
		}
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $outputs['html'] );
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $outputs['md'] );
		$this->assertStringContainsString( 'This pack includes no Sensitive records.', $outputs['csv'] );
		$this->assertSame( array(), json_decode( $outputs['json'], true )['sensitive_included'] );
	}
}
