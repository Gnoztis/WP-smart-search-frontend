<?php
declare( strict_types = 1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin updates through the native WordPress mechanism; the source is the
 * GitHub Releases and tags of self::REPO.
 *
 * The source of truth is the highest version of the GitHub Release and of the
 * tags: WordPress then shows the update under Plugins and installs the package
 * of the winning source. No third-party library, no write request to the
 * repository.
 *
 * Two traps this class works around:
 *
 * 1. The update key must be `plugin_basename( WSSF_PLUGIN_FILE )` computed at
 *    runtime. WordPress keys `update_plugins->response` by the basename of the
 *    installed main file, but counts *every* entry for the admin badge, so a
 *    hard-coded key (or a stale one after the folder was renamed) shows an
 *    "update available" badge with an empty update list.
 * 2. The installed version must be read from the plugin header on disk, not
 *    from WSSF_VERSION. During the upgrade request core rewrites the
 *    update_plugins transient while the constant still holds the *old*
 *    version (it was defined at the start of the request), so comparing with
 *    the constant injects an entry for the version that is already installed:
 *    "you are using 5.4.0, update to 5.4.0" and a second update is needed.
 */
final class WSSF_GitHub_Updater {

    public const REPO = 'Gnoztis/WP-smart-search-frontend';
    public const SLUG = 'wp-smart-search-frontend';

    private const TRANSIENT            = 'wssf_github_release';
    private const ERROR_TRANSIENT      = 'wssf_github_release_error';
    private const CACHE_TTL            = 21600; // 6 hours — a successful check.
    private const ERROR_TTL            = 900;   // 15 minutes — a failed check.
    private const API_TIMEOUT          = 5;
    private const FORCE_CHECK_ARG      = 'wssf-force-check';
    private const FORCE_CHECK_NONCE    = 'wssf_force_check';

    /** Version headers of the plugin file; read once per request. */
    private static ?array $header = null;

    public function register_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'rename_source' ], 10, 4 );
        add_action( 'upgrader_process_complete',             [ $this, 'after_upgrade' ], 10, 2 );
        add_action( 'load-plugins.php',                      [ $this, 'maybe_force_check' ] );
        add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
    }

    /**
     * Basename of the installed main plugin file, e.g.
     * "wp-smart-search-frontend/wp-smart-search-frontend.php".
     *
     * Never taken from a constant: a GitHub zipball unpacks into
     * "Gnoztis-WP-smart-search-frontend-<sha>", a manual upload may use any
     * folder name, and the key has to match the file that is really there.
     */
    public static function basename(): string {
        $basename = plugin_basename( WSSF_PLUGIN_FILE );

        if ( $basename === '' ) {
            $basename = self::SLUG . '/' . wp_basename( WSSF_PLUGIN_FILE );
        }

        return $basename;
    }

    /**
     * Installed version as WordPress sees it: the "Version" header of the
     * main file on disk.
     *
     * Falls back to WSSF_VERSION only when the file cannot be read. The
     * constant is *not* the source of truth during an upgrade request.
     */
    public static function installed_version(): string {
        $header = self::header_data()['version'];

        if ( $header !== '' ) {
            return $header;
        }

        return defined( 'WSSF_VERSION' ) ? (string) WSSF_VERSION : '0.0.0';
    }

    /* ============================================================
     *  Updates
     * ============================================================ */

    /**
     * Adds the entry to the update_plugins transient when GitHub has a
     * version newer than both the constant and the header on disk.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $basename = self::basename();

        // Both run before any early return: a dangling or phantom entry must
        // disappear even when GitHub is unreachable right now.
        $this->purge_foreign_keys( $transient, $basename );
        $this->demote_phantom_entry( $transient, $basename );

        $release = $this->get_release();
        if ( $release === null ) {
            return $transient;
        }

        $constant = defined( 'WSSF_VERSION' ) ? (string) WSSF_VERSION : '0.0.0';
        $header   = self::installed_version();

        if ( version_compare( $release['version'], $constant, '<=' )
            || version_compare( $release['version'], $header, '<=' )
        ) {
            return $transient;
        }

        $transient->response[ $basename ] = (object) [
            'id'            => $basename,
            'slug'          => self::SLUG,
            'plugin'        => $basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $release['package'],
            'tested'        => $release['tested'],
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new \stdClass(),
        ];

        if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
            unset( $transient->no_update[ $basename ] );
        }

        return $transient;
    }

    /**
     * Drops entries that point at our main file under a foreign key.
     *
     * Such entries are leftovers of a hard-coded key or of a renamed folder.
     * They make the admin badge count an update that get_plugin_updates()
     * cannot list, because the key matches no installed plugin file.
     *
     * @param mixed  $transient
     * @param string $basename Our real basename, kept as is.
     */
    private function purge_foreign_keys( $transient, string $basename ): void {
        $main = wp_basename( WSSF_PLUGIN_FILE );

        foreach ( [ 'response', 'no_update' ] as $bucket ) {
            if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
                continue;
            }

            foreach ( $transient->$bucket as $key => $entry ) {
                if ( (string) $key === $basename || ! is_object( $entry ) ) {
                    continue;
                }
                if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
                    continue;
                }

                unset( $transient->$bucket[ $key ] );
            }
        }
    }

    /**
     * Removes our own entry when it offers the version that is already on
     * disk.
     *
     * Core rewrites the transient inside the upgrade request, where the
     * constant still holds the previous version; the resulting entry says
     * "update to the version you already have" and survives until the next
     * check. Moved to no_update, never left in response.
     *
     * @param mixed  $transient
     * @param string $basename
     */
    private function demote_phantom_entry( $transient, string $basename ): void {
        if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
            return;
        }

        $entry        = $transient->response[ $basename ];
        $new_version  = isset( $entry->new_version ) ? (string) $entry->new_version : '';
        $installed    = self::installed_version();

        if ( $new_version === '' || version_compare( $new_version, $installed, '>' ) ) {
            return;
        }

        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = [];
        }

        unset( $transient->response[ $basename ] );
        $transient->no_update[ $basename ] = $entry;
    }

    /**
     * Data for the "View plugin details" modal.
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
            return $result;
        }
        if ( self::SLUG !== (string) $args->slug ) {
            return $result;
        }

        $release = $this->get_release();
        if ( $release === null ) {
            return $result;
        }

        $header = self::header_data();

        return (object) [
            'name'          => 'WP Smart Search (Frontend)',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $header['requires'],
            'tested'        => $this->tested_version( $header['tested'] ),
            'requires_php'  => $header['requires_php'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => __( 'AJAX-поиск с фасетными фильтрами, локальным расширением запроса и поддержкой IOC.', 'wp-smart-search-frontend' ),
                'changelog'   => $release['body'] !== ''
                    ? wpautop( $release['body'] )
                    : __( 'Описание изменений в релизе не заполнено.', 'wp-smart-search-frontend' ),
            ],
        ];
    }

    /**
     * GitHub archives unpack into a directory like
     * Gnoztis-WP-smart-search-frontend-1a2b3c4 — rename it to the directory
     * the site actually uses (the folder of the installed main file),
     * otherwise WordPress updates the wrong directory or adds a second copy.
     *
     * @param string $source
     * @param string $remote_source
     * @param mixed  $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
            return $source;
        }
        if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
            return $source;
        }
        if ( self::basename() !== (string) $hook_extra['plugin'] ) {
            return $source;
        }
        if ( ! is_dir( $source ) ) {
            return $source;
        }
        if ( ! is_file( trailingslashit( $source ) . wp_basename( WSSF_PLUGIN_FILE ) ) ) {
            return $source;
        }

        $folder = dirname( self::basename() );
        if ( $folder === '.' || $folder === '' ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . $folder;
        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( ! $wp_filesystem ) {
            return $source;
        }

        if ( $wp_filesystem->move( $source, $desired, true ) ) {
            return trailingslashit( $desired );
        }

        return $source;
    }

    /**
     * Forgets the cached check after an upgrade, so the next run does not
     * advertise the version that was just installed.
     *
     * @param mixed $upgrader
     * @param array $hook_extra
     */
    public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
        if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            if ( ! in_array( self::basename(), $hook_extra['plugins'], true ) ) {
                return;
            }
        } elseif ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) ) {
            if ( self::basename() !== (string) $hook_extra['plugin'] ) {
                return;
            }
        }

        $this->flush();
    }

    /**
     * "Check for updates" link in the plugin row.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_links( $links ): array {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return (array) $links;
        }

        $url = wp_nonce_url(
            add_query_arg( self::FORCE_CHECK_ARG, '1', admin_url( 'plugins.php' ) ),
            self::FORCE_CHECK_NONCE
        );

        $links = (array) $links;
        $links['wssf_check'] = '<a href="' . esc_url( $url ) . '">'
            . esc_html__( 'Проверить обновления', 'wp-smart-search-frontend' ) . '</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" click on plugins.php.
     */
    public function maybe_force_check(): void {
        if ( ! isset( $_GET[ self::FORCE_CHECK_ARG ] ) ) {
            return;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        check_admin_referer( self::FORCE_CHECK_NONCE );

        $this->flush();

        require_once ABSPATH . 'wp-admin/includes/update.php';
        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    private function flush(): void {
        delete_transient( self::TRANSIENT );
        delete_transient( self::ERROR_TRANSIENT );
        delete_site_transient( 'update_plugins' );
    }

    /* ============================================================
     *  GitHub API
     * ============================================================ */

    /**
     * Latest version data: 6 hour cache, 15 minute error cache (backoff).
     */
    private function get_release(): ?array {
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
            return $cached;
        }

        if ( get_transient( self::ERROR_TRANSIENT ) ) {
            return null;
        }

        $release = $this->fetch_release();

        if ( $release === null ) {
            set_transient( self::ERROR_TRANSIENT, 1, self::ERROR_TTL );
            return null;
        }

        set_transient( self::TRANSIENT, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * Highest version of both sources.
     *
     * Both are always queried. Taking the release and using the tag only when
     * there is no release hides a fresh tag behind an older GitHub Release:
     * the repository keeps release 1.6.3, the owner pushes tag 1.7.0, and the
     * update is never offered until a release is created by hand.
     *
     * Equal versions: the release wins, it carries the changelog.
     */
    private function fetch_release(): ?array {
        $release = $this->fetch_latest_release();
        $tag     = $this->fetch_latest_tag();

        if ( $release === null && $tag === null ) {
            return null;
        }
        if ( $release === null ) {
            return $tag;
        }
        if ( $tag === null ) {
            return $release;
        }

        return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
    }

    private function fetch_latest_release(): ?array {
        $body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ) );
        if ( $body === null ) {
            return null;
        }

        $version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
        if ( $version === '' ) {
            return null;
        }

        $package = $this->find_package( $body );
        if ( $package === '' ) {
            return null;
        }

        return $this->build_release(
            $version,
            $package,
            isset( $body['body'] ) ? (string) $body['body'] : '',
            isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
        );
    }

    /**
     * Highest semver tag of the repository; competes with the release in
     * fetch_release(). A tag without a GitHub Release is a normal case.
     */
    private function fetch_latest_tag(): ?array {
        $tags = $this->api_get(
            sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', self::REPO )
        );

        if ( ! is_array( $tags ) ) {
            return null;
        }

        $best_version = '';
        $best_tag     = '';

        foreach ( $tags as $tag ) {
            if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
                continue;
            }
            $version = $this->normalize_version( (string) $tag['name'] );
            if ( $version === '' ) {
                continue;
            }
            if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
                $best_version = $version;
                $best_tag     = (string) $tag['name'];
            }
        }

        if ( $best_version === '' || $best_tag === '' ) {
            return null;
        }

        $package = $this->safe_package_url(
            sprintf( 'https://api.github.com/repos/%s/zipball/%s', self::REPO, rawurlencode( $best_tag ) )
        );

        if ( $package === '' ) {
            return null;
        }

        return $this->build_release( $best_version, $package, '', '' );
    }

    private function build_release( string $version, string $package, string $body, string $published_at ): array {
        $header = self::header_data();

        return [
            'version'      => $version,
            'package'      => $package,
            'body'         => $body,
            'published_at' => $published_at,
            'tested'       => $this->tested_version( $header['tested'] ),
            'requires'     => $header['requires'],
            'requires_php' => $header['requires_php'],
        ];
    }

    /**
     * "Tested up to" turned into the value WordPress compares with.
     *
     * The header holds a branch claim ("7.1"), while the update screen
     * compares the stored value with the full running version ("7.1.5"):
     * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch
     * claim is always lower than a patch release, so a bare "7.1" still
     * renders "Compatibility with WordPress 7.1.5: Not tested".
     *
     * api.wordpress.org resolves this by serving the patch release of the
     * declared branch; the same expansion is done here.
     *
     * @param string $tested Header value, e.g. "7.1"; empty when absent.
     */
    private function tested_version( string $tested ): string {
        $tested = trim( $tested );
        if ( '' === $tested ) {
            return '';
        }

        $running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
        if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
            $running = (string) $GLOBALS['wp_version'];
        }
        $running = trim( (string) preg_replace( '/-.*/', '', $running ) );
        if ( '' === $running ) {
            return $tested;
        }

        $tested_parts  = explode( '.', $tested );
        $running_parts = explode( '.', $running );

        if ( count( $tested_parts ) >= count( $running_parts ) ) {
            return $tested;
        }

        if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
            return $running;
        }

        return $tested;
    }

    /**
     * GET to the GitHub API with our User-Agent and an optional token.
     *
     * @return array<string,mixed>|null
     */
    private function api_get( string $url ): ?array {
        $args = [
            'timeout'     => self::API_TIMEOUT,
            'redirection' => 2,
            'headers'     => [
                'Accept'       => 'application/vnd.github+json',
                'User-Agent'   => 'WP-Smart-Search-Frontend/' . ( defined( 'WSSF_VERSION' ) ? WSSF_VERSION : '0' ),
                'Content-Type' => 'application/json',
            ],
        ];

        $token = $this->token();
        if ( $token !== '' ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        // The URL is built from the repository constant, not from user input.
        $response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from the repository constant; scheme and host of the answer are validated below.
        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? $body : null;
    }

    /**
     * Archive URL: the release zip asset first, then the GitHub zipball.
     *
     * @param array $body
     */
    private function find_package( array $body ): string {
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }
                $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
                if ( ! preg_match( '/\.zip$/i', $name ) ) {
                    continue;
                }
                $url = $this->safe_package_url( (string) $asset['browser_download_url'] );
                if ( $url !== '' ) {
                    return $url;
                }
            }
        }

        if ( ! empty( $body['zipball_url'] ) ) {
            return $this->safe_package_url( (string) $body['zipball_url'] );
        }

        return '';
    }

    /**
     * https only and GitHub hosts only: the package must not come from a
     * field a third-party service could tamper with.
     */
    private function safe_package_url( string $url ): string {
        $url = esc_url_raw( trim( $url ) );
        if ( $url === '' ) {
            return '';
        }

        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        $host   = wp_parse_url( $url, PHP_URL_HOST );

        if ( 'https' !== strtolower( (string) $scheme ) ) {
            return '';
        }

        $allowed = [ 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];
        if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
            return '';
        }

        return $url;
    }

    /**
     * Strict semver: "1.7.0" and "v1.7.0" are equal, pre-releases and
     * four-part versions are dropped.
     */
    private function normalize_version( string $tag ): string {
        $tag = trim( $tag );
        if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
            return '';
        }
        return $m[1];
    }

    private function token(): string {
        $token = defined( 'WSSF_GITHUB_TOKEN' ) ? (string) WSSF_GITHUB_TOKEN : '';
        $token = (string) apply_filters( 'wssf_github_token', $token );
        return trim( $token );
    }

    /**
     * Version headers of the plugin file.
     *
     * `Tested up to` and `Requires at least` are different things: the first
     * is the newest WordPress the plugin was checked against, the second the
     * oldest it runs on. Feeding the minimum into `tested` makes the update
     * screen show "compatibility: unknown", so they are read separately and
     * `tested` stays empty when the header is absent.
     *
     * @return array{version:string,requires:string,requires_php:string,tested:string}
     */
    private static function header_data(): array {
        if ( self::$header === null ) {
            $data = function_exists( 'get_file_data' )
                ? get_file_data(
                    WSSF_PLUGIN_FILE,
                    [
                        'Version'           => 'Version',
                        'Requires at least' => 'Requires at least',
                        'Requires PHP'      => 'Requires PHP',
                        'Tested up to'      => 'Tested up to',
                    ],
                    'plugin'
                )
                : [];

            self::$header = [
                'version'      => isset( $data['Version'] ) ? (string) $data['Version'] : '',
                'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
                'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
                'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
            ];
        }

        return self::$header;
    }
}
