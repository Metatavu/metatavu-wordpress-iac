<?php
/**
 * Updates of whitelisted releases, through WordPress's own updater.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * On WP Engine, PHP in a REST request can't create or delete `.php` files,
 * so the API can't install anything (D18). A release's declared version is
 * staged here instead, as an encrypted zip in the storage directory (D23),
 * which is served over HTTP like everything web PHP can write, and offered
 * to WordPress as the package's update (D19). WordPress's automatic updater
 * installs it on the next WP-Cron run, where it may write `.php` files.
 *
 * Each declared release is kept in its own option, so concurrent requests
 * for different packages can't overwrite each other: its version and
 * SHA-256, the staged zip until that version is installed, and the last
 * failed attempt.
 */
final class Release_Updates {

	// Followed by `<type>_<slug>`.
	const OPTION_PREFIX = 'metatavu_iac_release_';

	// WordPress's automatic updater holds this lock until it has settled its updates.
	const UPDATER_LOCK = 'auto_updater.lock';

	// A staged zip is encrypted in chunks of this size, so it never has to fit in memory (D23).
	const CHUNK = 1048576;

	// An offer's package names the staged zip, never its path (D20).
	const SCHEME = 'metatavu-iac://';

	/**
	 * Takes the whitelist and the storage directory.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( private readonly Config $config ) {}

	/**
	 * Hooks into WordPress's update checks, its automatic updater and its
	 * upgraders, in every request: WP-Cron and wp-admin run them, not the API.
	 */
	public function hook(): void {
		// Last, so no other updater's offer for a managed release survives (D19).
		add_filter( 'site_transient_update_plugins', fn( $value ) => $this->offer( Package_Store::PLUGIN, $value ), PHP_INT_MAX );
		add_filter( 'site_transient_update_themes', fn( $value ) => $this->offer( Package_Store::THEME, $value ), PHP_INT_MAX );
		add_filter( 'auto_update_plugin', fn( $update, $item ) => $this->auto_update( Package_Store::PLUGIN, $update, $item ), PHP_INT_MAX, 2 );
		add_filter( 'auto_update_theme', fn( $update, $item ) => $this->auto_update( Package_Store::THEME, $update, $item ), PHP_INT_MAX, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'resolve_package' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'upgraded' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'updated_automatically' ) );
		add_action( Write_Log::CLEANUP_HOOK, array( $this, 'tidy' ) );
	}

	/**
	 * Declares a release's version. When it isn't the installed one, the
	 * checked zip is staged and WordPress's automatic updater is scheduled
	 * to run at once.
	 *
	 * @param string      $type      Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string      $slug      A whitelisted slug.
	 * @param string      $version   The declared version.
	 * @param string      $sha256    The zip's SHA-256.
	 * @param string      $zip       The checked upload.
	 * @param string|null $installed The installed version.
	 * @return true|\WP_Error
	 */
	public function declare_release( string $type, string $slug, string $version, string $sha256, string $zip, ?string $installed ) {
		$previous = $this->record( $type, $slug );
		$record   = array(
			'version' => $version,
			'sha256'  => strtolower( $sha256 ),
			'staged'  => null,
			'error'   => null,
		);
		if ( $installed !== $version ) {
			// A random name and key: the storage directory is served over HTTP (D23).
			$name = bin2hex( random_bytes( 16 ) ) . '.bin';
			$key  = sodium_crypto_secretstream_xchacha20poly1305_keygen();
			if ( ! wp_mkdir_p( $this->storage() ) || ! self::encrypt( $zip, $this->storage() . $name, $key ) ) {
				wp_delete_file( $this->storage() . $name );
				return new \WP_Error( 'metatavu_iac_staging_failed', 'The zip could not be stored in ' . $this->config->storage . '.', array( 'status' => 500 ) );
			}
			$record['staged'] = $name;
			$record['key']    = base64_encode( $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A binary key in an option.
		}
		$this->save( $type, $slug, $record );
		// Only once the new declaration is stored: a failure above keeps the previous one.
		if ( null !== $previous && $previous['staged'] !== $record['staged'] ) {
			$this->delete_staged( $previous );
		}
		if ( null !== $record['staged'] ) {
			// WordPress's own hook. An identical event due within ten minutes is kept instead.
			wp_schedule_single_event( time(), 'wp_maybe_auto_update' );
		}
		return true;
	}

	/**
	 * Stops managing a package's releases, deleting a staged zip: for
	 * example when it's declared from wordpress.org instead.
	 *
	 * @param string $type Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string $slug The slug.
	 */
	public function forget( string $type, string $slug ): void {
		$record = $this->record( $type, $slug );
		if ( null === $record ) {
			return;
		}
		delete_option( self::OPTION_PREFIX . $type . '_' . $slug );
		$this->delete_staged( $record );
	}

	/**
	 * The declared release that isn't installed yet, with the last failed
	 * attempt, or null. While WordPress's automatic updater is running, a
	 * staged version that's already in place is still pending: the updater
	 * may restore the previous version after its fatal-error check.
	 *
	 * @param string      $type      Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string      $slug      The slug.
	 * @param string|null $installed The installed version.
	 */
	public function pending( string $type, string $slug, ?string $installed ): ?array {
		$record = $this->record( $type, $slug );
		if ( null === $record ) {
			return null;
		}
		if ( $record['version'] === $installed && ( null === $record['staged'] || ! self::updater_running() ) ) {
			return null;
		}
		return array(
			'version' => $record['version'],
			'error'   => $record['error'],
		);
	}

	/**
	 * Filters WordPress's update offers: a managed release is offered its
	 * staged version, and nothing else (D19).
	 *
	 * @param string $type  Package_Store::PLUGIN or Package_Store::THEME.
	 * @param mixed  $value The update_plugins or update_themes site transient.
	 * @return mixed
	 */
	public function offer( string $type, $value ) {
		$records = $this->managed( $type );
		if ( ! $records ) {
			return $value;
		}
		// Without an offer cached, WordPress checks for updates as usual: no last_checked is set.
		$value = is_object( $value ) ? $value : (object) array();
		foreach ( array( 'response', 'no_update' ) as $key ) {
			$value->$key = isset( $value->$key ) && is_array( $value->$key ) ? $value->$key : array();
		}
		foreach ( $records as $slug => $record ) {
			$key = Package_Store::PLUGIN === $type ? Package_Store::plugin_file( $slug ) : $slug;
			if ( null === $key ) {
				continue;
			}
			unset( $value->response[ $key ] );
			if ( null === $record['staged'] || Package_Store::installed_version( $type, $slug ) === $record['version'] ) {
				continue;
			}
			unset( $value->no_update[ $key ] );
			$offer = array(
				'slug'        => $slug,
				'new_version' => $record['version'],
				'url'         => '',
				'package'     => self::SCHEME . $type . '/' . $slug . '/' . $record['version'],
				'autoupdate'  => true,
			);
			// WordPress keeps plugin offers as objects and theme offers as arrays.
			$value->response[ $key ] = Package_Store::PLUGIN === $type
				? (object) ( $offer + array(
					'id'     => self::SCHEME . $type . '/' . $slug,
					'plugin' => $key,
				) )
				: $offer + array( 'theme' => $slug );
		}
		return $value;
	}

	/**
	 * Lets WordPress's automatic updater install exactly the offers made
	 * here for managed releases (D19). Other packages are left as they are.
	 *
	 * @param string    $type   Package_Store::PLUGIN or Package_Store::THEME.
	 * @param bool|null $update Whether WordPress would update it.
	 * @param object    $item   The offer.
	 * @return bool|null
	 */
	public function auto_update( string $type, $update, $item ) {
		$item = (object) $item;
		$slug = Package_Store::PLUGIN === $type ? dirname( (string) ( $item->plugin ?? '' ) ) : (string) ( $item->theme ?? '' );
		if ( ! isset( $this->managed( $type )[ $slug ] ) ) {
			return $update;
		}
		return str_starts_with( (string) ( $item->package ?? '' ), self::SCHEME );
	}

	/**
	 * Resolves an offer's package to a decrypted copy of its staged zip, after
	 * checking the zip's SHA-256 again right before WordPress unpacks it (D20,
	 * D23). The staged zip stays until its version is installed, so a failed
	 * attempt can be tried again.
	 *
	 * @param mixed  $reply   A download result so far: false to download.
	 * @param string $package The package.
	 * @return mixed The copy's path, or a \WP_Error.
	 */
	public function resolve_package( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! str_starts_with( $package, self::SCHEME ) ) {
			return $reply;
		}
		$parts  = explode( '/', substr( $package, strlen( self::SCHEME ) ) );
		$record = 3 === count( $parts ) ? ( $this->managed( $parts[0] )[ $parts[1] ] ?? null ) : null;
		if ( null === $record || $record['version'] !== $parts[2] || null === $record['staged'] ) {
			return new \WP_Error( 'metatavu_iac_not_staged', 'No staged zip for ' . $package . '.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// WordPress deletes the package it's given once unpacked; it gets a decrypted copy.
		$copy = wp_tempnam( $parts[1] . '.zip' );
		if ( ! $copy ) {
			return $this->refuse( $parts[0], $parts[1], $record, new \WP_Error( 'metatavu_iac_staging_failed', 'The staged zip of ' . $parts[1] . ' could not be read.' ) );
		}
		$key = base64_decode( (string) ( $record['key'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A binary key in an option.
		if ( false === $key || ! self::decrypt( $this->storage() . $record['staged'], $copy, $key ) || ! hash_equals( $record['sha256'], (string) hash_file( 'sha256', $copy ) ) ) {
			wp_delete_file( $copy );
			return $this->refuse( $parts[0], $parts[1], $record, new \WP_Error( 'metatavu_iac_checksum_mismatch', 'The staged zip of ' . $parts[1] . ' ' . $parts[2] . ' isn\'t the declared one.' ) );
		}
		return $copy;
	}

	/**
	 * Records why a staged zip was refused, and returns the error: WordPress
	 * doesn't pass a download's error on to the automatic updater's results.
	 *
	 * @param string    $type   Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string    $slug   The slug.
	 * @param array     $record The record.
	 * @param \WP_Error $error  Why.
	 */
	private function refuse( string $type, string $slug, array $record, \WP_Error $error ): \WP_Error {
		$record['error'] = $error->get_error_message();
		$this->save( $type, $slug, $record );
		return $error;
	}

	/**
	 * Settles the managed releases updated from wp-admin. The automatic
	 * updater's own updates are settled by updated_automatically(): after
	 * this hook, it still checks the site for a fatal error and may restore
	 * the previous version.
	 *
	 * @param object $upgrader   The upgrader.
	 * @param array  $hook_extra What it worked on.
	 */
	public function upgraded( $upgrader, $hook_extra ): void {
		// Exactly the automatic updater's skin: wp-admin's AJAX skin extends it.
		$skin = $upgrader->skin ?? null;
		if ( is_object( $skin ) && \Automatic_Upgrader_Skin::class === get_class( $skin ) ) {
			return;
		}
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		$result     = $upgrader->result ?? null;
		$error      = $result instanceof \WP_Error ? $result->get_error_message() : null;
		foreach ( array( Package_Store::PLUGIN, Package_Store::THEME ) as $type ) {
			$items = array_merge( (array) ( $hook_extra[ $type ] ?? array() ), (array) ( $hook_extra[ $type . 's' ] ?? array() ) );
			foreach ( $items as $item ) {
				$this->settle( $type, Package_Store::PLUGIN === $type ? dirname( (string) $item ) : (string) $item, $error );
			}
		}
	}

	/**
	 * Settles the managed releases WordPress's automatic updater tried,
	 * including those whose package couldn't be prepared.
	 *
	 * @param array $results The results by type: each has the offer as `item` and `result`.
	 */
	public function updated_automatically( $results ): void {
		foreach ( array( Package_Store::PLUGIN, Package_Store::THEME ) as $type ) {
			foreach ( (array) ( $results[ $type ] ?? array() ) as $outcome ) {
				$item   = (object) ( $outcome->item ?? array() );
				$slug   = Package_Store::PLUGIN === $type ? dirname( (string) ( $item->plugin ?? '' ) ) : (string) ( $item->theme ?? '' );
				$result = $outcome->result ?? null;
				$this->settle( $type, $slug, $result instanceof \WP_Error ? $result->get_error_message() : null );
			}
		}
	}

	/**
	 * Deletes the staged zip once its version is installed, or records why
	 * it isn't.
	 *
	 * @param string      $type  Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string      $slug  The slug.
	 * @param string|null $error The upgrader's error, if it reported one.
	 */
	private function settle( string $type, string $slug, ?string $error ): void {
		$record = $this->managed( $type )[ $slug ] ?? null;
		if ( null === $record || null === $record['staged'] ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		Package_Store::PLUGIN === $type ? wp_clean_plugins_cache( false ) : wp_clean_themes_cache( false );
		if ( Package_Store::installed_version( $type, $slug ) === $record['version'] ) {
			$this->delete_staged( $record );
			$record['staged'] = null;
			$record['error']  = null;
			// The key is needed only while a zip is staged.
			unset( $record['key'] );
		} else {
			// A refused download recorded its own reason (refuse()).
			$record['error'] = $error ?? $record['error'] ?? 'WordPress didn\'t install version ' . $record['version'] . '.';
		}
		$this->save( $type, $slug, $record );
	}

	/**
	 * Deletes the staged zips of versions that are installed by now, outside
	 * a run of the automatic updater: for example installed over SSH.
	 * Daily, with the log's cleanup.
	 */
	public function tidy(): void {
		if ( self::updater_running() ) {
			return;
		}
		foreach ( array( Package_Store::PLUGIN, Package_Store::THEME ) as $type ) {
			foreach ( $this->managed( $type ) as $slug => $record ) {
				if ( null !== $record['staged'] && Package_Store::installed_version( $type, $slug ) === $record['version'] ) {
					$this->delete_staged( $record );
					$record['staged'] = null;
					$record['error']  = null;
					// The key is needed only while a zip is staged.
					unset( $record['key'] );
					$this->save( $type, $slug, $record );
				}
			}
		}
	}

	/**
	 * The declared releases of whitelisted packages of a type, by slug.
	 *
	 * @param string $type Package_Store::PLUGIN or Package_Store::THEME.
	 * @return array<string, array>
	 */
	private function managed( string $type ): array {
		$managed = array();
		foreach ( Package_Store::PLUGIN === $type ? $this->config->plugins : $this->config->themes as $slug ) {
			$record = Package_Store::OWN_SLUG === $slug ? null : $this->record( $type, $slug );
			if ( null !== $record ) {
				$managed[ $slug ] = $record;
			}
		}
		return $managed;
	}

	/**
	 * A package's declared release, or null.
	 *
	 * @param string $type Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string $slug The slug.
	 */
	private function record( string $type, string $slug ): ?array {
		$record = get_option( self::OPTION_PREFIX . $type . '_' . $slug, null );
		if ( ! is_array( $record ) ) {
			return null;
		}
		// Staged by 0.3.0, unencrypted and without a key: not offered, and the
		// next apply uploads it again, as for any pending update.
		if ( null !== ( $record['staged'] ?? null ) && empty( $record['key'] ) ) {
			$record['staged'] = null;
			$record['error']  = 'Staged by an earlier version of the IaC plugin; the next apply uploads it again.';
		}
		return $record;
	}

	/**
	 * Saves a package's declared release.
	 *
	 * @param string $type   Package_Store::PLUGIN or Package_Store::THEME.
	 * @param string $slug   The slug.
	 * @param array  $record The record.
	 */
	private function save( string $type, string $slug, array $record ): void {
		// Autoloaded: WordPress reads the update offers on most admin and cron requests.
		update_option( self::OPTION_PREFIX . $type . '_' . $slug, $record, true );
	}

	/**
	 * Tells whether WordPress's automatic updater is running.
	 */
	private static function updater_running(): bool {
		return false !== get_option( self::UPDATER_LOCK );
	}

	/**
	 * Deletes a record's staged zip, if it has one.
	 *
	 * @param array $record The record.
	 */
	private function delete_staged( array $record ): void {
		if ( null !== ( $record['staged'] ?? null ) ) {
			wp_delete_file( $this->storage() . $record['staged'] );
		}
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams: WP_Filesystem reads and writes whole files only.

	/**
	 * Encrypts a file in chunks with libsodium's secretstream
	 * (XChaCha20-Poly1305): its header, then each chunk with its tag, the
	 * last one marked final.
	 *
	 * @param string $from The plain file.
	 * @param string $to   The encrypted file.
	 * @param string $key  The key.
	 */
	private static function encrypt( string $from, string $to, string $key ): bool {
		$in  = fopen( $from, 'rb' );
		$out = $in ? fopen( $to, 'wb' ) : false;
		if ( ! $in || ! $out ) {
			$in && fclose( $in );
			return false;
		}
		list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		$ok                     = self::write( $out, $header );
		do {
			$chunk = self::read( $in, self::CHUNK );
			$last  = feof( $in );
			$tag   = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
			$ok    = $ok && self::write( $out, sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, '', $tag ) );
		} while ( $ok && ! $last );
		fclose( $in );
		return fclose( $out ) && $ok;
	}

	/**
	 * Decrypts what encrypt() wrote. A changed, cut or extended file fails.
	 *
	 * @param string $from The encrypted file.
	 * @param string $to   The plain file.
	 * @param string $key  The key.
	 */
	private static function decrypt( string $from, string $to, string $key ): bool {
		$in  = is_file( $from ) ? fopen( $from, 'rb' ) : false;
		$out = $in ? fopen( $to, 'wb' ) : false;
		if ( ! $in || ! $out ) {
			$in && fclose( $in );
			return false;
		}
		$header = self::read( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
		$ok     = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES === strlen( $header ) && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $key );
		$state  = $ok ? sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key ) : '';
		$final  = false;
		while ( $ok && ! $final ) {
			$chunk  = self::read( $in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES );
			$pulled = '' === $chunk ? false : sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $chunk );
			$ok     = false !== $pulled && self::write( $out, $pulled[0] );
			$final  = $ok && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $pulled[1];
		}
		// Nothing may follow the final chunk.
		$ok = $ok && '' === (string) fread( $in, 1 );
		fclose( $in );
		return fclose( $out ) && $ok;
	}

	/**
	 * Reads up to length bytes, fewer only at the end of the file: fread()
	 * may return less, and the chunks' boundaries must be the same both ways.
	 *
	 * @param resource $handle The file.
	 * @param int      $length How much.
	 */
	private static function read( $handle, int $length ): string {
		$data = '';
		$left = $length;
		while ( $left > 0 && ! feof( $handle ) ) {
			$part = fread( $handle, $left );
			if ( false === $part || '' === $part ) {
				break;
			}
			$data .= $part;
			$left -= strlen( $part );
		}
		return $data;
	}

	/**
	 * Writes all of the data, or fails: fwrite() may write less, for example
	 * when the disk is full.
	 *
	 * @param resource $handle The file.
	 * @param string   $data   The data.
	 */
	private static function write( $handle, string $data ): bool {
		$left = strlen( $data );
		while ( $left > 0 ) {
			$written = fwrite( $handle, substr( $data, -$left ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$left -= $written;
		}
		return true;
	}

	// phpcs:enable WordPress.WP.AlternativeFunctions

	/**
	 * The storage directory's path, with a trailing slash.
	 */
	private function storage(): string {
		return trailingslashit( ABSPATH . $this->config->storage );
	}
}
