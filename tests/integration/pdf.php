<?php
/**
 * Integration test for the PDF pack. Run on a WordPress test site with the GD and mbstring extensions:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/pdf.php
 *
 * It writes files to the uploads folder and removes them again.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Export\ContentBuilder;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Export\Profiles;
use Estasoft\HandoverPassport\Export\Render\Pdf\NoNetwork;
use Estasoft\HandoverPassport\Export\Render\Pdf\PluginFilesOnly;
use Estasoft\HandoverPassport\Export\Render\PackStyle;
use Estasoft\HandoverPassport\Export\Render\PdfRenderer;
use Estasoft\HandoverPassport\Export\Render\RenderFailed;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Json;
use Estasoft\HandoverPassport\Support\TempFiles;
use Estasoft\HandoverPassport\Tests\Unit\ExportFixture;

require_once ESTASOFT_HP_DIR . 'tests/Unit/ExportFixture.php';

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$why = PdfRenderer::unavailable();
$check( 'PDF is available here', '' === $why, $why );
if ( '' !== $why ) {
	echo "\nSkipped: $why\n";
	exit( 1 );
}

$sentinel = 'SENTINEL-SECRET-NOTE-77';
$records  = array(
	ExportFixture::record( 1, 'domain', 'plain.example', array( 'domain' => 'plain.example', 'registrar' => 'Реестр Доменов', 'expires_on' => '2027-01-01' ), array( 'due_date' => '2027-01-01' ) ),
	ExportFixture::record( 2, 'domain', 'سري.example', array( 'domain' => 'سري.example', 'note' => $sentinel, 'expires_on' => '2027-02-01' ), array( 'sensitive' => true, 'due_date' => '2027-02-01' ) ),
	ExportFixture::record( 3, 'host', 'שרת אירוח', array( 'provider' => 'שרת אירוח', 'plan' => 'Πακέτο Α', 'support_address' => 'help@host.example' ) ),
	ExportFixture::record( 4, 'host', 'استضافة الموقع', array( 'provider' => 'استضافة الموقع', 'plan' => 'الخطة الأساسية', 'support_address' => 'help@host.example' ) ),
);
$data     = ExportFixture::data( array( 'records' => $records, 'notes' => "Заметки для преемника.\nSecond line." ) );
$build    = static function ( string $profile, bool $sensitive = false ) use ( $data ): array {
	return ( new ContentBuilder( new SecretGuard(), '1.0.0' ) )->build( ( new Profiles() )->get( $profile ), $data, new ExportOptions( $sensitive, array(), 1 ) );
};
$hash_of  = static fn( array $content ): string => hash( 'sha256', Json::canonical( $content ) );
$text_of  = static function ( string $pdf ): string {
	$in = TempFiles::create( '.pdf' );
	file_put_contents( $in, $pdf );
	$text = (string) shell_exec( 'pdftotext -enc UTF-8 -layout ' . escapeshellarg( $in ) . ' - 2>/dev/null' );
	TempFiles::discard( $in );
	return $text;
};
$norm     = static fn( string $s ): string => class_exists( 'Normalizer' ) ? (string) Normalizer::normalize( $s, Normalizer::FORM_KC ) : $s;

$before = glob( TempFiles::dir() . '/*' ) ?: array();

foreach ( array( Profile::CLIENT, Profile::TECHNICAL, Profile::EMERGENCY ) as $profile ) {
	$content = $build( $profile );
	$started = microtime( true );
	try {
		$pdf = ( new PdfRenderer() )->render( $content, new PackStyle(), $hash_of( $content ), 'Test pack' );
	} catch ( Throwable $e ) {
		$check( "$profile: renders", false, $e->getMessage() . ' / ' . ( $e->getPrevious() ? $e->getPrevious()->getMessage() : '' ) );
		continue;
	}
	$secs = microtime( true ) - $started;
	$check( "$profile: starts with the PDF header", str_starts_with( $pdf, '%PDF-' ) );
	$check( "$profile: ends with EOF marker", str_contains( substr( $pdf, -16 ), '%%EOF' ) );
	$check( "$profile: finishes in a sensible time (" . round( $secs, 2 ) . 's, ' . round( strlen( $pdf ) / 1024 ) . ' KB)', $secs < 30 );
	$text = $text_of( $pdf );
	$check( "$profile: holds the site name", str_contains( $text, 'Fixture Site' ) );
	$check( "$profile: holds Cyrillic", str_contains( $text, 'Реестр Доменов' ) || str_contains( $text, 'Заметки' ) || 'client' !== $profile );
	$check( "$profile: holds no Sensitive value", ! str_contains( $text, $sentinel ) && ! str_contains( $norm( $text ), 'سري' ) );
	$check( "$profile: holds no network address of the library", ! preg_match( '#/URI\s*\(https?://#', $pdf ) || str_contains( $pdf, 'fixture.example' ) );
	if ( Profile::TECHNICAL === $profile ) {
		$norm_text = $norm( $text );
		$check( 'technical: Hebrew present', str_contains( $text, 'שרת' ) || str_contains( $text, 'תוחא' ) || str_contains( $norm_text, 'אירוח' ) );
		$check( 'technical: Arabic letters present (any form)', str_contains( $norm_text, 'استضافة' ) || str_contains( $norm_text, 'ةفاضتسا' ) );
		$check( 'technical: Greek present', str_contains( $text, 'Πακέτο' ) );
		file_put_contents( '/tmp/hp_technical.pdf', $pdf );
	}
	if ( Profile::EMERGENCY === $profile ) {
		file_put_contents( '/tmp/hp_emergency.pdf', $pdf );
		$pages = (int) trim( (string) shell_exec( 'pdfinfo /tmp/hp_emergency.pdf 2>/dev/null | awk \'/^Pages:/{print $2}\'' ) );
		$check( 'emergency: one page', 1 === $pages, (string) $pages );
	}
	if ( Profile::CLIENT === $profile ) {
		file_put_contents( '/tmp/hp_client.pdf', $pdf );
	}
}

// The full pack with Sensitive records, to see the Arabic Sensitive title is only there when allowed.
$full = $build( Profile::FULL, true );
$pdf  = ( new PdfRenderer() )->render( $full, new PackStyle(), $hash_of( $full ), 'Full pack' );
$text = $norm( $text_of( $pdf ) );
$check( 'full: Sensitive record shown when allowed', str_contains( $text, $sentinel ) );

// Chinese, Japanese and Korean text needs a font the plugin does not ship; the person is told.
$cjk = $build( Profile::TECHNICAL );
$cjk['notes'] = '東京ホスティング';
$check( 'a warning is given for CJK text when no font is installed', \Estasoft\HandoverPassport\Export\Render\Pdf\Fonts::hasExtras() || array() !== PdfRenderer::warnings( $cjk ) );
$check( 'no warning for Arabic, Hebrew, Cyrillic or Greek', array() === PdfRenderer::warnings( $build( Profile::TECHNICAL ) ) );

// The library may not reach the network or read files outside the plugin.
$answer = ( new NoNetwork() )->sendRequest( new \Estasoft\HandoverPassport\Vendor\Mpdf\PsrHttpMessageShim\Request( 'GET', 'http://127.0.0.1:1/' ) );
$check( 'network client answers 403 and sends nothing', 403 === $answer->getStatusCode() );

$loader = new PluginFilesOnly( array( ESTASOFT_HP_DIR . 'lib/pdf' ) );
$check( 'loader reads a file inside the library', is_string( $loader->load( ESTASOFT_HP_DIR . 'lib/pdf/README.md' ) ) );
$check( 'loader refuses /etc/passwd', null === $loader->load( '/etc/passwd' ) );
$check( 'loader refuses a path that climbs out', null === $loader->load( ESTASOFT_HP_DIR . 'lib/pdf/../../readme.txt' ) );
$check( 'loader refuses a missing file', null === $loader->load( ESTASOFT_HP_DIR . 'lib/pdf/none.txt' ) );

// Text that looks like markup must not make the library fetch anything: a local server logs every request.
$log  = TempFiles::create( '.log' );
$port = 18700 + random_int( 0, 99 );
$srv  = TempFiles::create( '.php' );
file_put_contents( $srv, '<?php file_put_contents(' . var_export( $log, true ) . ', $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND); echo "x";' );
$proc = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $srv ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
usleep( 400000 );
$base  = 'http://127.0.0.1:' . $port;
$trap  = '<img src="' . $base . '/img"> ![a](' . $base . '/md) <link rel="stylesheet" href="' . $base . '/css"> <iframe src="' . $base . '/frame"></iframe> '
	. '<img src="file:///etc/passwd"> <style>@import url(' . $base . '/import); body{background:url(' . $base . '/bg)}</style> [x](' . $base . '/link)';
$hostile = ExportFixture::data(
	array(
		'records' => array( ExportFixture::record( 1, 'domain', 'trap.example', array( 'domain' => 'trap.example', 'registrar' => $trap ), array( 'due_date' => '2027-01-01' ) ) ),
		'notes'   => $trap,
		'runbooks' => array(),
	)
);
$content = ( new ContentBuilder( new SecretGuard(), '1.0.0' ) )->build( ( new Profiles() )->get( Profile::TECHNICAL ), $hostile, new ExportOptions( false, array(), 1 ) );
try {
	$pdf = ( new PdfRenderer() )->render( $content, new PackStyle(), $hash_of( $content ), 'Trap' );
	$check( 'markup-like text renders', str_starts_with( $pdf, '%PDF-' ) );
} catch ( Throwable $e ) {
	$check( 'markup-like text renders', false, $e->getMessage() );
}
usleep( 200000 );
$hits = is_file( $log ) ? trim( (string) file_get_contents( $log ) ) : '';
$check( 'no request reached the local server', '' === $hits, $hits );
if ( is_resource( $proc ) ) {
	proc_terminate( $proc );
	proc_close( $proc );
}
TempFiles::discard( $log );
TempFiles::discard( $srv );

// The settings filter cannot turn the limits off.
$probe = '/tmp/hp_filter_probe_' . bin2hex( random_bytes( 4 ) );
add_filter(
	'estasoft_hp_pdf_config',
	static function ( array $config ) use ( $probe ): array {
		$config['tempDir']       = $probe;
		$config['enableImports'] = true;
		return $config;
	}
);
$content = $build( Profile::CLIENT );
$pdf     = ( new PdfRenderer() )->render( $content, new PackStyle(), $hash_of( $content ), 'Filtered' );
$check( 'a filter cannot move the working folder or enable imports', str_starts_with( $pdf, '%PDF-' ) && ! file_exists( $probe ) );
remove_all_filters( 'estasoft_hp_pdf_config' );

// Working folders are removed, even after a failure.
$after = glob( TempFiles::dir() . '/*' ) ?: array();
$check( 'no working folder is left behind', count( $after ) === count( $before ), implode( ',', array_diff( $after, $before ) ) );

// A budget that is too small stops the build with a message instead of a crash.
$old = ini_get( 'memory_limit' );
$big = array();
for ( $i = 1; $i <= 300; $i++ ) {
	$big[] = ExportFixture::record( $i, 'domain', "bulk-$i.example", array( 'domain' => "bulk-$i.example", 'registrar' => str_repeat( 'Registrar ', 30 ) ), array( 'due_date' => '2027-01-01' ) );
}
$large   = ExportFixture::data( array( 'records' => $big ) );
$content = ( new ContentBuilder( new SecretGuard(), '1.0.0' ) )->build( ( new Profiles() )->get( Profile::TECHNICAL ), $large, new ExportOptions( false, array(), 1 ) );
$t       = microtime( true );
$pdf     = ( new PdfRenderer() )->render( $content, new PackStyle(), $hash_of( $content ), 'Large' );
file_put_contents( '/tmp/hp_large.pdf', $pdf );
$pages = (int) trim( (string) shell_exec( "pdfinfo /tmp/hp_large.pdf 2>/dev/null | awk '/^Pages:/{print \$2}'" ) );
$check( 'large pack (300 records) renders: ' . $pages . ' pages, ' . round( strlen( $pdf ) / 1024 ) . ' KB, ' . round( microtime( true ) - $t, 1 ) . ' s, peak ' . round( memory_get_peak_usage( true ) / 1048576 ) . ' MB', $pages > 5 );

echo "\n$checks checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
