<?php
/**
 * Autoloading bootstrap of the plugin (stage 1 of the namespace migration).
 *
 * The plugin is self-contained: it ships its own loader and needs nothing
 * outside its own directory. There is no Composer, no vendor directory and no
 * shared file — a site installed from git, from a zip, or by copying the
 * directory behaves exactly the same.
 *
 * Loaded from the main file right after the constants, before any class is
 * used. The namespaced loader is registered first, then includes/aliases.php
 * registers the resolver for the old global class names.
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

require_once __DIR__ . '/aliases.php';
