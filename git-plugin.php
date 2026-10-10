<?php
/**
 * Plugin Name: Git Plugin
 * Description: Install plugins straight from a Git repository (GitHub, GitLab, Bitbucket, Gitea/Forgejo), and download any installed plugin as a zip.
 * Version: 1.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Web9SEO
 * Author URI: https://web9seo.com
 * License: GPLv2 or later
 * Update URI: https://git.web9.live/pcgraphics/Git-Plugin
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/web9-updater.php';

class Git_Plugin
{
    const ACTION = 'git-plugin-install';

    public static function init(): void
    {
        if (!is_admin() || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) {
            return;
        }
        // ?tab=git is a valid (menu-less) tab, so the panel can be linked to directly.
        add_filter('install_plugins_nonmenu_tabs', function ($tabs) {
            $tabs[] = 'git';
            return $tabs;
        });
        add_action('admin_footer-plugin-install.php', [__CLASS__, 'panel']);
        // update.php fires update-custom_{action} for actions it doesn't know: the same screen Upload Plugin uses.
        add_action('update-custom_' . self::ACTION, [__CLASS__, 'install']);
        add_filter('plugin_action_links', [__CLASS__, 'download_link'], 10, 2);
        add_filter('network_admin_plugin_action_links', [__CLASS__, 'download_link'], 10, 2);
        add_action('admin_post_git_plugin_download', [__CLASS__, 'download_zip']);
    }

    public static function download_link(array $links, string $file): array
    {
        if (current_user_can('install_plugins') && file_exists(WP_PLUGIN_DIR . '/' . $file)) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=git_plugin_download&plugin=' . rawurlencode($file)), 'git_plugin_download_' . $file);
            $links['git_plugin_download'] = '<a href="' . esc_url($url) . '">Download</a>';
        }
        return $links;
    }

    /** Zip an installed plugin with its folder as the top level, so Upload Plugin re-installs it as-is. */
    public static function download_zip(): void
    {
        $file = wp_unslash($_GET['plugin'] ?? '');
        if (!current_user_can('install_plugins')) {
            wp_die(__('Sorry, you are not allowed to do that.'), 403);
        }
        check_admin_referer('git_plugin_download_' . $file);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!isset(get_plugins()[$file])) { // only real installed plugins: no paths from the query string
            wp_die('Plugin not found.', 404);
        }
        $version = get_plugins()[$file]['Version'];
        $folder = dirname($file);
        $single = $folder === '.'; // e.g. hello.php lives directly in wp-content/plugins
        $slug = $single ? basename($file, '.php') : $folder;
        @set_time_limit(0);

        $tmp = wp_tempnam('git-plugin-dl-' . $slug . '.zip');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            wp_die('Could not create the zip file.');
        }
        if ($single) {
            $zip->addFile(WP_PLUGIN_DIR . '/' . $file, basename($file));
        } else {
            $root = WP_PLUGIN_DIR . '/' . $folder;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $f) {
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if (!$f->isFile() || preg_match('#(^|/)\.(git|svn|hg)(/|$)#', $rel)) {
                    continue; // version-control data stays out of the download
                }
                $zip->addFile($f->getPathname(), $folder . '/' . $rel);
            }
        }
        if (!$zip->close()) {
            @unlink($tmp);
            wp_die('Could not write the zip file.');
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . ($version !== '' ? '-' . sanitize_file_name($version) : '') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** The button and the form, moved into place next to "Upload Plugin" by a few lines of JS. */
    public static function panel(): void
    {
        if (!current_user_can('install_plugins')) {
            return;
        }
        ?>
        <?php // Own classes on purpose: core JS disables the submit of any .wp-upload-form until a file is picked. ?>
        <div class="git-plugin-panel" hidden>
            <p class="install-help">Install a plugin from a Git repository: GitHub, GitLab, Bitbucket, Gitea or Forgejo.</p>
            <form method="post" class="git-plugin-form" action="<?php echo esc_url(self_admin_url('update.php?action=' . self::ACTION)); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <div class="git-plugin-row">
                    <label class="git-plugin-repo">Repository URL
                        <input type="text" id="git-plugin-repo" name="repo" class="code" required
                               placeholder="https://github.com/owner/repo  ·  git@host:owner/repo.git  ·  owner/repo"></label>
                    <label class="git-plugin-ref">Branch / tag
                        <input type="text" id="git-plugin-ref" name="ref" class="code" placeholder="default"></label>
                </div>
                <details>
                    <summary>Private repository</summary>
                    <p><label for="git-plugin-token">Access token</label><br>
                        <input type="password" id="git-plugin-token" name="token" class="regular-text code" autocomplete="off">
                        <br><span class="description">Used for this download only, never stored.</span></p>
                </details>
                <p><input type="submit" class="button button-primary" value="Install Now"></p>
            </form>
        </div>
        <style>
            .git-plugin-panel { display: none; text-align: center; margin: 30px 0; }
            .show-git-plugin-view .git-plugin-panel { display: block; }
            .show-git-plugin-view .wp-filter, .show-git-plugin-view #plugin-filter { display: none; }
            .git-plugin-panel .install-help { color: #50575e; font-size: 18px; margin: 0; }
            .git-plugin-form { display: inline-block; width: min(880px, 100%); box-sizing: border-box; margin: 30px 0 0; padding: 24px 30px 12px;
                border: 1px solid #c3c4c7; background: #f6f7f7; text-align: left; }
            .git-plugin-form p { margin: 0 0 12px; }
            .git-plugin-row { display: flex; gap: 12px; margin-bottom: 12px; }
            .git-plugin-row label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; min-width: 0; }
            .git-plugin-row input { width: 100%; padding: 6px 10px; font-size: 14px; }
            .git-plugin-repo { flex: 4; } /* URLs are long, ref names short: 4:1 */
            .git-plugin-ref { flex: 1; }
            .git-plugin-form details { margin-bottom: 12px; }
            @media (max-width: 600px) { .git-plugin-row { flex-direction: column; } }
        </style>
        <script>
        (function () {
            var panel = document.querySelector('.git-plugin-panel');
            var anchor = document.querySelector('.upload-view-toggle') || document.querySelector('.wrap > h1');
            if (!panel || !anchor) { return; }
            var btn = document.createElement('a');
            btn.href = <?php echo wp_json_encode(self_admin_url('plugin-install.php?tab=git')); ?>;
            btn.className = 'page-title-action git-plugin-toggle';
            btn.textContent = 'Git Plugin';
            btn.setAttribute('aria-expanded', 'false');
            anchor.insertAdjacentElement('afterend', btn);
            document.querySelector('.wp-header-end').insertAdjacentElement('afterend', panel);
            panel.hidden = false;
            var body = document.body;
            function show(on) {
                body.classList.toggle('show-git-plugin-view', on);
                btn.setAttribute('aria-expanded', String(on));
                if (on) { body.classList.remove('show-upload-view'); document.getElementById('git-plugin-repo').focus(); }
            }
            btn.addEventListener('click', function (e) { e.preventDefault(); show(!body.classList.contains('show-git-plugin-view')); });
            var up = document.querySelector('.upload-view-toggle');
            if (up) { up.addEventListener('click', function () { show(false); }); }
            if (/[?&]tab=git(&|$)/.test(location.search)) { show(true); }
        })();
        </script>
        <?php
    }

    /** Download the repo archive and hand it to WordPress's own plugin installer. */
    public static function install(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(__('Sorry, you are not allowed to install plugins on this site.'));
        }
        // A second pass comes from the installer's "Replace current with uploaded" button.
        $key = sanitize_key($_GET['package'] ?? '');
        $overwrite = in_array($_GET['overwrite'] ?? '', ['update-plugin', 'downgrade-plugin'], true) ? $_GET['overwrite'] : '';
        if ($key !== '') {
            check_admin_referer('plugin-upload');
            $job = get_transient('git_plugin_' . $key);
            if (!$job || !is_file($job['zip'])) {
                wp_die('That download has expired. Install the plugin from Git again.', '', ['back_link' => true]);
            }
        } else {
            check_admin_referer(self::ACTION);
            try {
                $job = self::download(wp_unslash($_POST['repo'] ?? ''), wp_unslash($_POST['ref'] ?? ''), wp_unslash($_POST['token'] ?? ''));
            } catch (Exception $e) {
                wp_die(esc_html($e->getMessage()), 'Git Plugin', ['back_link' => true]);
            }
            $key = strtolower(wp_generate_password(20, false));
            set_transient('git_plugin_' . $key, $job, HOUR_IN_SECONDS);
        }

        global $title, $parent_file, $submenu_file;
        $title = 'Install Plugin from Git';
        $parent_file = 'plugins.php';
        $submenu_file = 'plugin-install.php';
        require_once ABSPATH . 'wp-admin/admin-header.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $folder = function ($source, $remote_source) use ($job) {
            // Archives unpack as "repo-main/" etc.; install under the repo's name so the folder is stable.
            if (is_wp_error($source) || basename($source) === $job['slug']) {
                return $source;
            }
            global $wp_filesystem;
            $target = trailingslashit($remote_source) . $job['slug'] . '/';
            return $wp_filesystem->move($source, $target) ? $target : new WP_Error('git_plugin_rename', 'Could not rename the plugin folder.');
        };
        add_filter('upgrader_source_selection', $folder, 10, 2);

        $upgrader = new Plugin_Upgrader(new Plugin_Installer_Skin([
            'type' => 'upload', // gives the "already installed: replace?" comparison screen
            'title' => sprintf('Installing %s from Git', $job['label']),
            'url' => add_query_arg(['action' => self::ACTION, 'package' => $key], self_admin_url('update.php')),
            'overwrite' => $overwrite,
        ]));
        $upgrader->install($job['zip'], ['overwrite_package' => (bool)$overwrite]);
        remove_filter('upgrader_source_selection', $folder, 10);

        // Keep the zip only while the "replace?" choice is pending. (install() returns nothing useful on
        // failure; the skin holds the actual WP_Error.)
        $result = $upgrader->skin->result;
        if (!is_wp_error($result) || $result->get_error_code() !== 'folder_exists') {
            @unlink($job['zip']);
            delete_transient('git_plugin_' . $key);
        }
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }

    /** @return array{zip: string, slug: string, label: string} */
    public static function download(string $repo, string $ref, string $token): array
    {
        $r = self::parse($repo);
        $ref = trim($ref);
        if ($ref !== '' && !preg_match('#^[\w./-]{1,200}$#', $ref)) {
            throw new Exception('That branch/tag name has characters Git Plugin does not accept.');
        }
        $token = trim($token);
        $path = $r['owner'] . '/' . $r['repo'];
        $headers = ['Accept' => 'application/octet-stream, application/zip, */*'];

        if ($r['host'] === 'github.com') {
            $url = 'https://api.github.com/repos/' . $path . '/zipball' . ($ref !== '' ? '/' . rawurlencode($ref) : '');
            $token && $headers['Authorization'] = 'Bearer ' . $token;
        } elseif ($r['host'] === 'bitbucket.org') {
            $url = 'https://bitbucket.org/' . $path . '/get/' . rawurlencode($ref !== '' ? $ref : 'HEAD') . '.zip';
            $token && $headers['Authorization'] = 'Bearer ' . $token;
        } elseif (strpos($r['host'], 'gitlab') !== false) {
            $url = $r['base'] . '/api/v4/projects/' . rawurlencode($path) . '/repository/archive.zip' . ($ref !== '' ? '?sha=' . rawurlencode($ref) : '');
            $token && $headers['PRIVATE-TOKEN'] = $token;
        } else {
            // Gitea / Forgejo (self-hosted). Their archive endpoint needs an explicit ref.
            $auth = $token ? ['Authorization' => 'token ' . $token] : [];
            if ($ref === '') {
                $res = wp_remote_get($r['base'] . '/api/v1/repos/' . $path, ['timeout' => 20, 'headers' => $auth + ['Accept' => 'application/json']]);
                $info = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
                if (empty($info['default_branch'])) {
                    throw new Exception(self::why($res, $r['base'] . '/' . $path));
                }
                $ref = $info['default_branch'];
            }
            $url = $r['base'] . '/api/v1/repos/' . $path . '/archive/' . rawurlencode($ref) . '.zip';
            $headers += $auth;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        // Downloads left behind by a cancelled "replace?" screen are swept after an hour.
        foreach (glob(get_temp_dir() . 'git-plugin-*') ?: [] as $old) {
            if (filemtime($old) < time() - HOUR_IN_SECONDS) {
                @unlink($old);
            }
        }
        $zip = wp_tempnam('git-plugin-' . $r['repo'] . '.zip');
        $res = wp_remote_get($url, ['timeout' => 300, 'stream' => true, 'filename' => $zip, 'headers' => $headers]);
        $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
        if ($code !== 200 || file_get_contents($zip, false, null, 0, 2) !== 'PK') {
            @unlink($zip);
            throw new Exception(self::why($res, $r['base'] . '/' . $path, $ref));
        }
        return [
            'zip' => $zip,
            'slug' => sanitize_title($r['repo']),
            'label' => $path . ($ref !== '' ? '@' . $ref : ''),
        ];
    }

    /** Accepts https://host/owner/repo(.git), ssh://git@host:port/owner/repo.git, git@host:owner/repo.git and owner/repo (GitHub). */
    public static function parse(string $in): array
    {
        $in = trim($in);
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $in)) {
            $in = 'https://github.com/' . $in;
        } elseif (preg_match('#^[\w.-]+@([^:/]+):(?!\d+/)(.+)$#', $in, $m)) {
            $in = 'https://' . $m[1] . '/' . $m[2]; // scp-style git@host:owner/repo.git
        }
        $u = wp_parse_url($in);
        if (empty($u['host']) || !in_array($u['scheme'] ?? '', ['https', 'http', 'ssh', 'git'], true)) {
            throw new Exception('Enter a repository URL like https://github.com/owner/repo');
        }
        $parts = array_values(array_filter(explode('/', preg_replace('#\.git/?$#', '', $u['path'] ?? ''))));
        // GitLab allows nested groups (group/sub/repo); everywhere else it's owner/repo, maybe followed by /tree/branch etc.
        if (strpos($u['host'], 'gitlab') !== false) {
            $dash = array_search('-', $parts, true); // group/sub/repo/-/tree/main
            $nested = $dash === false ? $parts : array_slice($parts, 0, $dash);
        } else {
            $nested = array_slice($parts, 0, 2); // owner/repo[/tree/branch…]
        }
        if (count($nested) < 2) {
            throw new Exception('That URL does not include both an owner and a repository name.');
        }
        $host = strtolower($u['host']);
        // SSH ports don't carry over to the web; an explicit http(s) port does.
        $scheme = $u['scheme'] === 'http' ? 'http' : 'https';
        $port = in_array($u['scheme'], ['http', 'https'], true) && !empty($u['port']) ? ':' . $u['port'] : '';
        return [
            'host' => $host,
            'base' => $scheme . '://' . $host . $port,
            'owner' => implode('/', array_slice($nested, 0, -1)),
            'repo' => end($nested),
        ];
    }

    private static function why($res, string $where, string $ref = ''): string
    {
        if (is_wp_error($res)) {
            return 'Could not reach ' . $where . ': ' . $res->get_error_message();
        }
        $code = wp_remote_retrieve_response_code($res);
        if (in_array($code, [401, 403, 404], true)) {
            return 'Repository' . ($ref !== '' ? ' or branch "' . $ref . '"' : '') . ' not found at ' . $where
                . ' (HTTP ' . $code . '). If it is private, add an access token.';
        }
        return 'Download from ' . $where . ' failed (HTTP ' . $code . ').';
    }
}

Git_Plugin::init();
