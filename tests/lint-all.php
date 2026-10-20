<?php
/**
 * بررسی syntax همهٔ فایل‌های PHP افزونه (بدون اجرای آن‌ها).
 *
 * اجرا روی سرور:
 *   php tests/lint-all.php
 *
 * یا با تعیین ریشه:
 *   FALNIC_LINT_ROOT=/path/to/plugin php tests/lint-all.php
 *
 * @package Falnic\SecureHLS
 */

$root = getenv( 'FALNIC_LINT_ROOT' );

if ( ! $root || ! is_dir( $root ) ) {
	$root = dirname( __DIR__ );
}

$root  = rtrim( str_replace( '\\', '/', $root ), '/' );
$files = 0;
$bad   = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	// پوشه‌های وابستگی و حافظه‌های موقت را نادیده بگیر.
	$path = str_replace( '\\', '/', $file->getPathname() );

	if ( preg_match( '#/(node_modules|vendor|\.git|dist|bin)/#', $path ) ) {
		continue;
	}

	$files++;

	try {
		// TOKEN_PARSE فقط تجزیه می‌کند و کد را اجرا نمی‌کند.
		token_get_all( (string) file_get_contents( $path ), TOKEN_PARSE );
	} catch ( Throwable $error ) {
		$bad++;
		echo 'FAIL ' . str_replace( $root . '/', '', $path ) . ' :: ' . $error->getMessage() . "\n";
	}
}

echo 'PHP ' . PHP_VERSION . ' — ' . $files . ' files, ' . $bad . " failures\n";

exit( $bad > 0 ? 1 : 0 );
