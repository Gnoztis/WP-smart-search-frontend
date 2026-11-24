<?php
/**
 * Autoloading bootstrap of the plugin.
 *
 * The plugin is self-contained: it ships its own loader and needs nothing
 * outside its own directory. There is no Composer, no vendor directory and no
 * shared file — a site installed from git, from a zip, or by copying the
 * directory behaves exactly the same.
 *
 * Loaded from the main file right after the constants, before any class is
 * used. The loader resolves `WSSF\Foo_Bar` from `includes/class-foo-bar.php`
 * and is silent for every other class name.
 *
 * Since 6.0.0 there are no aliases: the old global names (WSSF_Foo_Bar) were
 * removed together with includes/aliases.php. Code that still asks for one
 * gets "Class not found", which is the breaking change 6.0.0 announces.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WSSF_PATH' ) ) {
	return;
}

require_once __DIR__ . '/class-autoloader.php';

WSSF_Autoloader::register();
