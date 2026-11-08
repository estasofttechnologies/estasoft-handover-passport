<?php
/**
 * Builds lib/pdf: the PDF library with its own namespace prefix, so it cannot clash with another plugin that ships the
 * same library (EXP-2).
 *
 * Usage:
 *     composer install --working-dir=bin/pdf-lib --no-dev --prefer-dist
 *     php bin/build-pdf-lib.php --vendor=bin/pdf-lib/vendor --out=lib/pdf
 *
 * The vendor folder must hold mpdf/mpdf and its packages as Composer lays them out. Only the font files listed in
 * FONTS are copied. Nothing in the result is edited by hand: run this script again to change it. The script makes one
 * fix to the library's code (the border of links inside the document) and stops if the text it expects is not there.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const PREFIX = 'Estasoft\\HandoverPassport\\Vendor';

/** Fonts shipped in lib/pdf/fonts. Latin, Cyrillic, Greek, Hebrew and Arabic are covered by these two files. */
const FONTS = array( 'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf' );

/** Namespace roots to prefix. */
const ROOTS = array( 'Mpdf', 'Psr', 'DeepCopy', 'setasign' );

/**
 * Where each package goes under lib/pdf/src. The folder path equals the namespace path after the prefix, so one
 * autoload rule serves them all. "skip" names files left out: the library's own polyfills and debug helpers (this plugin
 * needs PHP 8.1, and a global dd() function must not be defined) and the loader that comes with FPDI.
 */
const PACKAGES = array(
	'mpdf/mpdf'                    => array( 'from' => 'src', 'to' => 'Mpdf', 'skip' => array( 'functions.php', 'functions-dev.php' ) ),
	'mpdf/psr-http-message-shim'   => array( 'from' => 'src', 'to' => 'Mpdf/PsrHttpMessageShim' ),
	'mpdf/psr-log-aware-trait'     => array( 'from' => 'src', 'to' => 'Mpdf/PsrLogAwareTrait' ),
	'myclabs/deep-copy'            => array( 'from' => 'src/DeepCopy', 'to' => 'DeepCopy' ),
	'psr/http-message'             => array( 'from' => 'src', 'to' => 'Psr/Http/Message' ),
	'psr/log'                      => array( 'from' => 'src', 'to' => 'Psr/Log' ),
	'setasign/fpdi'                => array( 'from' => 'src', 'to' => 'setasign/Fpdi', 'skip' => array( 'autoload.php' ) ),
);

$options = getopt( '', array( 'vendor:', 'out:' ) );
$vendor  = rtrim( (string) ( $options['vendor'] ?? '' ), '/' );
$out     = rtrim( (string) ( $options['out'] ?? '' ), '/' );
if ( '' === $vendor || '' === $out || ! is_dir( $vendor . '/mpdf/mpdf/src' ) ) {
	fwrite( STDERR, "Usage: php bin/build-pdf-lib.php --vendor=<vendor folder> --out=<lib/pdf>\n" );
	exit( 1 );
}

/**
 * Delete a folder and everything in it.
 */
function remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Copy a folder, passing PHP files through the namespace rewrite.
 *
 * @param list<string> $skip Paths relative to the folder that are not copied.
 */
function copy_tree( string $from, string $to, bool $rewrite, array $skip = array() ): int {
	$count = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$relative = substr( $file->getPathname(), strlen( $from ) + 1 );
		if ( in_array( $relative, $skip, true ) ) {
			continue;
		}
		$target = $to . '/' . $relative;
		if ( ! is_dir( dirname( $target ) ) ) {
			mkdir( dirname( $target ), 0755, true );
		}
		if ( $rewrite && 'php' === strtolower( $file->getExtension() ) ) {
			file_put_contents( $target, prefix_code( (string) file_get_contents( $file->getPathname() ) ) );
		} else {
			copy( $file->getPathname(), $target );
		}
		++$count;
	}
	return $count;
}

/**
 * Put the prefix in front of every namespace root: in `namespace` and `use` lines, in fully qualified names and in
 * the few strings that name a class or a function.
 */
function prefix_code( string $code ): string {
	$roots = implode( '|', array_map( 'preg_quote', ROOTS ) );
	// A root followed by a backslash: Mpdf\Foo, \Mpdf\Foo, 'Mpdf\Tag\\', Psr\Log\LoggerInterface.
	$code = (string) preg_replace_callback(
		'/(?<![A-Za-z0-9_])(\\\\?)(' . $roots . ')\\\\(?=[A-Za-z_])/',
		static fn( array $m ): string => $m[1] . PREFIX . '\\' . $m[2] . '\\',
		$code
	);
	// A namespace that is only the root: `namespace Mpdf;`, `namespace DeepCopy;`.
	return (string) preg_replace_callback(
		'/(\bnamespace\s+)(' . $roots . ')(\s*[;{])/',
		static fn( array $m ): string => $m[1] . PREFIX . '\\' . $m[2] . $m[3],
		$code
	);
}

remove_tree( $out );
mkdir( $out . '/src', 0755, true );

$files = 0;
foreach ( PACKAGES as $package => $map ) {
	$from = $vendor . '/' . $package . '/' . $map['from'];
	if ( ! is_dir( $from ) ) {
		fwrite( STDERR, "Missing package folder: {$from}\n" );
		exit( 1 );
	}
	$files += copy_tree( $from, $out . '/src/' . $map['to'], true, $map['skip'] ?? array() );
}

// Data files the library reads while it runs. It looks for them in a folder next to its code, so they go in src/data.
$files += copy_tree( $vendor . '/mpdf/mpdf/data', $out . '/src/data', true );

// One fix to the library. For links inside the document it writes the "no border" setting after the end of the link's
// dictionary, where it has no effect, so every contents-list entry gets a visible box in PDF viewers. Move it inside.
// The build stops if the text it expects is not there, so a newer library version cannot slip through unchecked.
$writer = $out . '/src/Mpdf/Writer/MetadataWriter.php';
$code   = (string) file_get_contents( $writer );
$code   = (string) preg_replace(
	'/\(\' \/Dest (\[%d 0 R \/XYZ 0 %\.3F null\])>>\'([^;]*;)\s*\$this->writer->write\(\' \/Border \[0 0 0\]\', false\);/',
	'(\' /Border [0 0 0] /Dest $1>>\'$2',
	$code,
	-1,
	$replaced
);
if ( 2 !== $replaced ) {
	fwrite( STDERR, "The link border fix matched {$replaced} places instead of 2. The library version changed; check the fix.\n" );
	exit( 1 );
}
file_put_contents( $writer, $code );

// Fonts.
mkdir( $out . '/fonts', 0755, true );
foreach ( FONTS as $font ) {
	$path = $vendor . '/mpdf/mpdf/ttfonts/' . $font;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing font: {$path}\n" );
		exit( 1 );
	}
	copy( $path, $out . '/fonts/' . $font );
	++$files;
}

// Licences and credits travel with the files they cover.
mkdir( $out . '/licenses', 0755, true );
$licences = array(
	'mpdf/mpdf/LICENSE.txt'           => 'mpdf-LICENSE.txt',
	'mpdf/mpdf/CREDITS.txt'           => 'mpdf-CREDITS.txt',
	'mpdf/mpdf/ttfonts/DejaVuinfo.txt' => 'DejaVu-fonts-LICENSE.txt',
	'setasign/fpdi/LICENSE.txt'       => 'fpdi-LICENSE.txt',
	'myclabs/deep-copy/LICENSE'       => 'deep-copy-LICENSE.txt',
	'psr/log/LICENSE'                 => 'psr-log-LICENSE.txt',
	'psr/http-message/LICENSE'        => 'psr-http-message-LICENSE.txt',
);
foreach ( $licences as $from => $name ) {
	if ( is_file( $vendor . '/' . $from ) ) {
		copy( $vendor . '/' . $from, $out . '/licenses/' . $name );
	} else {
		fwrite( STDERR, "Note: no licence file at {$from}\n" );
	}
}

// One autoload rule, and the two files of plain functions.
$autoload = <<<'PHP'
<?php
/**
 * Loads the bundled PDF library. Generated by bin/build-pdf-lib.php. Do not edit.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Estasoft\\HandoverPassport\\Vendor\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

require_once __DIR__ . '/src/DeepCopy/deep_copy.php';
PHP;
file_put_contents( $out . '/autoload.php', $autoload . "\n" );

$versions = array();
foreach ( array_keys( PACKAGES ) as $package ) {
	$json = json_decode( (string) @file_get_contents( $vendor . '/' . $package . '/composer.json' ), true );
	$versions[ $package ] = is_array( $json ) && isset( $json['version'] ) ? (string) $json['version'] : '';
}
// Without Composer's list of installed packages, read the two version numbers the library itself carries.
foreach ( array(
	'mpdf/mpdf'      => array( 'src/Mpdf.php', 'mpdf/mpdf' ),
	'setasign/fpdi'  => array( 'src/Fpdi.php', 'setasign/fpdi' ),
) as $package => $source ) {
	$text = (string) @file_get_contents( $vendor . '/' . $source[1] . '/' . $source[0] );
	if ( 1 === preg_match( "/const VERSION = '([0-9][^']*)'/", $text, $found ) ) {
		$versions[ $package ] = $found[1];
	}
}
$installed = $vendor . '/composer/installed.json';
if ( is_file( $installed ) ) {
	$list = json_decode( (string) file_get_contents( $installed ), true );
	foreach ( (array) ( $list['packages'] ?? $list ) as $pkg ) {
		if ( isset( $versions[ $pkg['name'] ] ) ) {
			$versions[ $pkg['name'] ] = (string) $pkg['version'];
		}
	}
}
$readme  = "# PDF library\n\nThis folder is generated by `bin/build-pdf-lib.php`. Do not edit it by hand.\n\n";
$readme .= "It holds mPDF and the packages it needs, with every namespace prefixed by `" . PREFIX . "` so it cannot clash with another plugin that ships the same library. Licences are in `licenses/`. The fonts are DejaVu Sans, regular and bold.\n\n";
$readme .= "| Package | Version |\n| --- | --- |\n";
foreach ( $versions as $package => $version ) {
	$readme .= '| ' . $package . ' | ' . ( '' === $version ? 'see composer.lock' : $version ) . " |\n";
}
file_put_contents( $out . '/README.md', $readme );

printf( "Wrote %d files to %s\n", $files, $out );
