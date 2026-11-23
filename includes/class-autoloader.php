<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Loader for the classes of this plugin that live in the WSSF namespace.
 *
 * The plugin is self-contained: it ships this loader and needs nothing outside
 * its own directory. There is no Composer, no vendor directory and no shared
 * file — a site installed from git, from a zip, or by copying the directory
 * behaves exactly the same.
 *
 * Convention, one class per file:
 *
 *     WSSF\Foo_Bar  ->  includes/class-foo-bar.php
 *
 * The map is implicit, so adding a class needs no registration and the loader
 * cannot go stale. Only files inside WSSF_PATH are ever included, the relative
 * path is validated, and a class that is not ours returns without including
 * anything — this loader never interferes with another plugin's autoloader.
 */
final class WSSF_Autoloader {

    private const PREFIX = 'WSSF\\';

    private const DIRECTORY = 'includes/';

    /** Registered once, so a second call cannot stack loaders. */
    private static bool $registered = false;

    public static function register(): void {
        if ( self::$registered ) { return; }

        self::$registered = true;

        spl_autoload_register( [ self::class, 'autoload' ] );
    }

    /**
     * Resolves a WSSF\... class from its file inside the plugin.
     */
    public static function autoload( string $class ): void {
        $relative = self::class_to_file( $class );

        if ( $relative === null ) { return; }

        $path = WSSF_PATH . self::DIRECTORY . $relative;

        if ( ! is_readable( $path ) ) { return; }

        require_once $path; // phpcs:ignore -- the path is derived from the class name, validated against ^[a-z0-9-]+\.php$, and resolved inside WSSF_PATH.
    }

    /**
     * Creates the old global name of a moved class on demand.
     *
     * Called from includes/aliases.php when code asks for WSSF_Foo_Bar: the
     * namespaced class WSSF\Foo_Bar is resolved through the loader and then
     * aliased, so both names are the same class and the old one costs nothing
     * until it is actually used.
     */
    public static function alias_old_name( string $old ): void {
        if ( class_exists( $old, false ) ) { return; }

        $new = self::namespaced_name( $old );

        if ( $new === null ) { return; }

        if ( self::class_to_file( $new ) === null ) { return; }

        if ( ! class_exists( $new ) ) { return; }

        class_alias( $new, $old );
    }

    /**
     * WSSF_Foo_Bar => WSSF\Foo_Bar, or null when the name is not ours.
     */
    public static function namespaced_name( string $old ): ?string {
        if ( strncmp( $old, 'WSSF_', 5 ) !== 0 || strlen( $old ) <= 5 ) {
            return null;
        }

        $short = substr( $old, 5 );

        if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $short ) ) {
            return null;
        }

        return self::PREFIX . $short;
    }

    /**
     * WSSF\Foo_Bar => class-foo-bar.php, or null when the name is not ours.
     */
    private static function class_to_file( string $class ): ?string {
        if ( strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) !== 0 ) {
            return null;
        }

        $short = substr( $class, strlen( self::PREFIX ) );

        if ( $short === '' || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $short ) ) {
            return null;
        }

        $slug = strtolower( str_replace( '_', '-', $short ) );

        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
            return null;
        }

        return 'class-' . $slug . '.php';
    }
}
