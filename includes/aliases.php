<?php
/**
 * Old class names for the classes that live in the WSSF namespace.
 *
 * Stage 1 of the migration (5.5.0): no class has moved yet, so this file
 * registers the resolver and creates nothing. Every old class is still
 * declared by its own includes/class-*.php file, exactly as before.
 *
 * From 5.5.1 on, when WSSF\Foo_Bar is moved to includes/class-foo-bar.php and
 * entered into WSSF_Autoloader::$MAP, the old name WSSF_Foo_Bar keeps working:
 * the resolver below is called by PHP only when that name is requested, loads
 * the namespaced class through the map and aliases it. Nothing is loaded
 * eagerly, so a class whose old name is never used costs nothing.
 *
 * Alias, not a copy: the old and the new name are the same class, so method
 * signatures, static state and type checks stay exactly as they were.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $old ): void {
		WSSF_Autoloader::alias_old_name( $old );
	}
);
