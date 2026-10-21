<?php
/**
 * Web9SEO updater: a shared drop-in, so keep every bundled copy identical.
 *
 * A plugin whose header says "Update URI: https://git.web9.live/<owner>/<repo>" updates from that public Forgejo repo:
 * from the latest Release (a .zip asset wins over the source archive), else from the highest vX.Y.Z tag.
 * The changelog is built from the version tags' messages, so `git tag -a v1.2.3 -m "1.2.3: what changed"` writes it.
 * Git checkouts (dev copies) never self-update. Several plugins may bundle this file; the first one loaded
 * registers the hooks for all of them.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'web9_updater_repo' ) ) {

	/** "owner/repo" from an Update URI, or '' when it isn't ours. */
	function web9_updater_repo( $uri ) {
		return preg_match( '#^https://git\.web9\.live/([\w.-]+/[\w.-]+?)/?$#', (string) $uri, $m ) ? $m[1] : '';
	}

	/** Repo of an installed plugin ("dir/file.php"), or ''. */
	function web9_updater_plugin_repo( $plugin ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $plugin ] ) ? web9_updater_repo( $all[ $plugin ]['UpdateURI'] ?? '' ) : '';
	}

	/** Changelog HTML from version tag messages, newest first; tags without a message are left out. */
	function web9_updater_changelog( $tags ) {
		$entries = array();
		foreach ( $tags as $t ) {
			$v = ltrim( $t['name'] ?? '', 'vV' );
			if ( ! preg_match( '/^\d+(\.\d+)*$/', $v ) ) {
				continue;
			}
			// Drop trailers (Co-Authored-By: …) and the "Name 1.2.3:" the first line opens with.
			$msg = trim( preg_replace( '/^[\w-]+-by:.*$/mi', '', (string) ( $t['message'] ?? '' ) ) );
			$msg = trim( preg_replace( '/^[^\n]*?v?' . preg_quote( $v, '/' ) . '\s*[:\x{2013}\x{2014}-]?\s*/u', '', $msg, 1 ) );
			if ( '' !== $msg ) {
				$entries[ $v ] = $msg;
			}
		}
		uksort( $entries, function ( $a, $b ) {
			return version_compare( $b, $a );
		} );
		$html = '';
		foreach ( $entries as $v => $msg ) {
			$html .= '<h4>' . esc_html( $v ) . '</h4>' . wpautop( esc_html( $msg ) );
		}
		return $html;
	}

	function web9_updater_release( $repo ) {
		$key    = 'web9_release_' . md5( $repo );
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$api = function ( $path ) use ( $repo ) {
			$res = wp_remote_get( "https://git.web9.live/api/v1/repos/$repo$path", array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );
			return is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		};

		$tags = (array) $api( '/tags?limit=50' );
		$data = $api( '/releases/latest' );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			// No published Release: a pushed version tag is enough.
			$best = null;
			foreach ( $tags as $t ) {
				$v = ltrim( $t['name'] ?? '', 'vV' );
				if ( preg_match( '/^\d+(\.\d+)*$/', $v ) && ( ! $best || version_compare( $v, ltrim( $best['name'], 'vV' ), '>' ) ) ) {
					$best = $t;
				}
			}
			$data = $best ? array(
				'tag_name'    => $best['name'],
				'zipball_url' => $best['zipball_url'] ?? '',
				'html_url'    => "https://git.web9.live/$repo/src/tag/" . rawurlencode( $best['name'] ),
			) : null;
		}

		$release = null;
		if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
			$package = $data['zipball_url'] ?? '';
			foreach ( $data['assets'] ?? array() as $a ) {
				if ( '.zip' === substr( $a['name'], -4 ) ) {
					$package = $a['browser_download_url'];
					break;
				}
			}
			$release = array(
				'version'   => ltrim( $data['tag_name'], 'vV' ),
				'package'   => $package,
				'url'       => $data['html_url'] ?? "https://git.web9.live/$repo",
				'notes'     => (string) ( $data['body'] ?? '' ),
				'changelog' => web9_updater_changelog( $tags ),
				'date'      => $data['published_at'] ?? '',
			);
		}
		// Failures are cached too (as []), so an unreachable server doesn't slow every admin page.
		set_site_transient( $key, $release ? $release : array(), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	add_filter(
		'update_plugins_git.web9.live',
		function ( $update, $data, $file ) {
			$repo = web9_updater_repo( $data['UpdateURI'] ?? '' );
			if ( $update || ! $repo || '.' === dirname( $file ) || file_exists( WP_PLUGIN_DIR . '/' . dirname( $file ) . '/.git' ) ) {
				return $update;
			}
			$r = web9_updater_release( $repo );
			// Up-to-date plugins are reported too: core files them under no_update, which gives the row its "View details" link.
			if ( ! $r || ( ! $r['package'] && version_compare( $r['version'], $data['Version'], '>' ) ) ) {
				return $update;
			}
			return array(
				'id'      => "git.web9.live/$repo", // wp_update_plugins() drops updates without an id.
				'slug'    => dirname( $file ),
				'version' => $r['version'],
				'url'     => $r['url'],
				'package' => $r['package'],
			);
		},
		10,
		3
	);

	// "View details" in the update notice.
	add_filter(
		'plugins_api',
		function ( $result, $action, $args ) {
			if ( false !== $result || 'plugin_information' !== $action || empty( $args->slug ) ) {
				return $result;
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $p ) {
				$repo = web9_updater_repo( $p['UpdateURI'] ?? '' );
				if ( dirname( $file ) !== $args->slug || ! $repo || ! ( $r = web9_updater_release( $repo ) ) ) {
					continue;
				}
				return (object) array(
					'name'          => $p['Name'],
					'slug'          => $args->slug,
					'version'       => $r['version'],
					'author'        => $p['Author'],
					'homepage'      => $r['url'],
					'last_updated'  => $r['date'],
					'download_link' => $r['package'],
					'sections'      => array(
						'description' => wpautop( esc_html( wp_strip_all_tags( $p['Description'] ) ) ),
						'changelog'   => ! empty( $r['changelog'] ) ? $r['changelog'] : wpautop( esc_html( $r['notes'] ? $r['notes'] : 'See ' . $r['url'] ) ),
					),
				);
			}
			return $result;
		},
		10,
		3
	);

	// Source archives unpack as "Repo/" or "repo-<sha>/"; WordPress needs the installed folder name.
	add_filter(
		'upgrader_source_selection',
		function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			$plugin = $hook_extra['plugin'] ?? '';
			if ( is_wp_error( $source ) || ! $plugin || '.' === dirname( $plugin ) || ! web9_updater_plugin_repo( $plugin ) ) {
				return $source;
			}
			$wanted = trailingslashit( $remote_source ) . dirname( $plugin ) . '/';
			if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
				return $source;
			}
			global $wp_filesystem;
			return $wp_filesystem->move( $source, $wanted ) ? $wanted : new WP_Error( 'web9_updater_rename', 'Could not rename the update folder.' );
		},
		10,
		4
	);
}
