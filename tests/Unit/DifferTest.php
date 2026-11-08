<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Scan\Differ;
use PHPUnit\Framework\TestCase;

final class DifferTest extends TestCase {

	private function facts( array $over = array() ): array {
		return array_replace_recursive(
			array(
				'core'    => array( 'wp_version' => '6.5', 'php_version' => '8.2.1', 'site_url' => 'https://example.com', 'home_url' => 'https://example.com' ),
				'plugins' => array(
					'akismet' => array( 'name' => 'Akismet', 'version' => '5.0', 'active' => true ),
					'forms'   => array( 'name' => 'Forms', 'version' => '2.1', 'active' => false ),
				),
				'themes'  => array(
					'one' => array( 'name' => 'One', 'version' => '1.0', 'active' => true ),
					'two' => array( 'name' => 'Two', 'version' => '1.0', 'active' => false ),
				),
				'users'   => array( 'admins' => array( array( 'id' => 1, 'name' => 'Ana' ) ) ),
			),
			$over
		);
	}

	private function kinds( array $diffs ): array {
		return array_map( static fn( $d ) => $d['kind'] . ':' . $d['label'], $diffs );
	}

	public function test_identical_scans_give_no_differences(): void {
		$this->assertSame( array(), Differ::diff( $this->facts(), $this->facts() ) );
	}

	public function test_a_plugin_update_is_reported_with_both_versions(): void {
		$new   = $this->facts( array( 'plugins' => array( 'akismet' => array( 'version' => '5.1' ) ) ) );
		$diffs = Differ::diff( $this->facts(), $new );
		$this->assertSame( array( 'plugin_updated:Akismet' ), $this->kinds( $diffs ) );
		$this->assertSame( '5.0', $diffs[0]['before']['version'] );
		$this->assertSame( '5.1', $diffs[0]['after']['version'] );
	}

	public function test_activation_and_deactivation(): void {
		$on  = Differ::diff( $this->facts(), $this->facts( array( 'plugins' => array( 'forms' => array( 'active' => true ) ) ) ) );
		$off = Differ::diff( $this->facts(), $this->facts( array( 'plugins' => array( 'akismet' => array( 'active' => false ) ) ) ) );
		$this->assertSame( array( 'plugin_activated:Forms' ), $this->kinds( $on ) );
		$this->assertSame( array( 'plugin_deactivated:Akismet' ), $this->kinds( $off ) );
	}

	public function test_added_and_removed_plugins(): void {
		$new = $this->facts();
		unset( $new['plugins']['forms'] );
		$new['plugins']['seo'] = array( 'name' => 'SEO', 'version' => '1', 'active' => true );
		$this->assertSame( array( 'plugin_added:SEO', 'plugin_removed:Forms' ), $this->kinds( Differ::diff( $this->facts(), $new ) ) );
	}

	public function test_core_php_and_address_changes(): void {
		$new   = $this->facts( array( 'core' => array( 'wp_version' => '6.6', 'php_version' => '8.3.0', 'site_url' => 'https://new.example.com' ) ) );
		$kinds = $this->kinds( Differ::diff( $this->facts(), $new ) );
		$this->assertContains( 'wordpress_version_changed:WordPress', $kinds );
		$this->assertContains( 'php_version_changed:PHP', $kinds );
		$this->assertContains( 'site_address_changed:Site address', $kinds );
		$this->assertNotContains( 'site_address_changed:Home address', $kinds );
	}

	public function test_switching_the_theme_is_one_change(): void {
		$new = $this->facts( array( 'themes' => array( 'one' => array( 'active' => false ), 'two' => array( 'active' => true ) ) ) );
		$this->assertSame( array( 'theme_switched:Two' ), $this->kinds( Differ::diff( $this->facts(), $new ) ) );
	}

	public function test_a_new_administrator_is_reported_without_an_email(): void {
		$new   = $this->facts();
		$new['users']['admins'][] = array( 'id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com' );
		$diffs = Differ::diff( $this->facts(), $new );
		$this->assertSame( array( 'administrator_added:Bob' ), $this->kinds( $diffs ) );
		$this->assertStringNotContainsString( 'bob@example.com', json_encode( $diffs ) );
	}

	public function test_a_missing_section_in_the_old_scan_does_not_invent_changes(): void {
		$old = $this->facts();
		unset( $old['users'] );
		$this->assertSame( array(), array_values( array_filter( $this->kinds( Differ::diff( $old, $this->facts() ) ), static fn( $k ) => 0 === strpos( $k, 'administrator' ) ) ) );
	}
}
