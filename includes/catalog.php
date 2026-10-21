<?php
/**
 * "Web9SEO" tab on Add Plugins: every public repo of the catalog owner that holds a WordPress plugin,
 * with Install / Activate / Update buttons. Repos without a plugin header (templates, tools…) are left out.
 */

defined('ABSPATH') || exit;

class Git_Plugin_Catalog
{
    const TAB = 'web9';
    const CACHE = 'git_plugin_catalog_2'; // bumped when the cached shape changes

    public static function init(): void
    {
        add_filter('install_plugins_tabs', function ($tabs) {
            $tabs[self::TAB] = 'Web9SEO';
            return $tabs;
        });
        add_action('install_plugins_' . self::TAB, [__CLASS__, 'render']);
        add_action('admin_post_git_plugin_catalog_refresh', [__CLASS__, 'refresh']);
    }

    /** "https://host/owner" whose public repos are listed. */
    public static function owner(): string
    {
        return untrailingslashit(apply_filters('git_plugin_catalog_owner', 'https://git.web9.live/pcgraphics'));
    }

    /** @return array<int, array{repo: string, url: string, name: string, description: string, version: string, main: string, icon: string}>|null */
    public static function plugins(): ?array
    {
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }
        $u = wp_parse_url(self::owner());
        $base = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $owner = trim($u['path'] ?? '', '/');
        $api = function (string $path) use ($base) {
            $res = wp_remote_get($base . '/api/v1/' . $path, ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
            return is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200 ? null : json_decode(wp_remote_retrieve_body($res), true);
        };

        $repos = [];
        for ($page = 1; $page <= 10; $page++) {
            $batch = $api('users/' . rawurlencode($owner) . '/repos?limit=50&page=' . $page);
            if (!is_array($batch)) {
                if ($page === 1) {
                    return null; // unreachable: not cached, so the next visit tries again
                }
                break;
            }
            $repos = array_merge($repos, $batch);
            if (count($batch) < 50) {
                break;
            }
        }

        $found = [];
        foreach ($repos as $r) {
            if (!empty($r['private']) || !empty($r['archived']) || !empty($r['fork']) || !empty($r['empty'])) {
                continue;
            }
            $plugin = self::find_plugin($api, $base, $r);
            if ($plugin) {
                $found[] = $plugin;
            }
        }
        usort($found, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        set_site_transient(self::CACHE, $found, 12 * HOUR_IN_SECONDS);
        return $found;
    }

    /** The repo's plugin header, from the root .php file that has one; null for repos that aren't plugins. */
    private static function find_plugin(callable $api, string $base, array $r): ?array
    {
        $files = [];
        $root = (array)$api('repos/' . $r['full_name'] . '/contents/?ref=' . rawurlencode($r['default_branch']));
        foreach ($root as $f) {
            if (($f['type'] ?? '') === 'file' && substr($f['name'], -4) === '.php') {
                $files[] = $f['name'];
            }
        }
        // The main file is usually named after the repo; try those first so big plugins need one request.
        $guess = strtolower($r['name']);
        usort($files, function ($a, $b) use ($guess) {
            $score = function ($f) use ($guess) {
                $f = strtolower(basename($f, '.php'));
                return $f === $guess || $f === str_replace('_', '-', $guess) ? 0 : 1;
            };
            return $score($a) <=> $score($b);
        });
        foreach (array_slice($files, 0, 8) as $file) {
            $res = wp_remote_get($base . '/api/v1/repos/' . $r['full_name'] . '/raw/' . rawurlencode($file) . '?ref=' . rawurlencode($r['default_branch']),
                ['timeout' => 10, 'headers' => ['Range' => 'bytes=0-8191']]);
            $head = is_wp_error($res) ? '' : wp_remote_retrieve_body($res);
            $field = function ($name) use ($head) {
                // Same header pattern get_file_data() uses.
                return preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $head, $m) ? trim(_cleanup_header_comment($m[1])) : '';
            };
            if ($field('Plugin Name') !== '') {
                return [
                    'repo' => $r['full_name'],
                    'url' => $r['html_url'],
                    'name' => $field('Plugin Name'),
                    'description' => $field('Description') !== '' ? $field('Description') : (string)$r['description'],
                    'version' => $field('Version'),
                    'main' => $file,
                    'icon' => self::icon($api, $base, $r, $root),
                ];
            }
        }
        return null;
    }

    /**
     * Card icon: the repo's avatar set in the Git host, else an icon file named the wordpress.org way
     * (root, assets/ or .wordpress-org/), else '' for a generated tile.
     */
    private static function icon(callable $api, string $base, array $r, array $root): string
    {
        if (!empty($r['avatar_url'])) {
            return $r['avatar_url'];
        }
        $names = ['icon-256x256.png', 'icon-256x256.jpg', 'icon-128x128.png', 'icon-128x128.jpg', 'icon.svg', 'icon.png'];
        $dirs = ['' => $root];
        foreach ($root as $f) {
            if (($f['type'] ?? '') === 'dir' && in_array($f['name'], ['assets', '.wordpress-org'], true)) {
                $dirs[$f['name'] . '/'] = (array)$api('repos/' . $r['full_name'] . '/contents/' . $f['name'] . '?ref=' . rawurlencode($r['default_branch']));
            }
        }
        foreach ($names as $name) {
            foreach ($dirs as $dir => $list) {
                if (in_array($name, array_column($list, 'name'), true)) {
                    return $base . '/' . $r['full_name'] . '/raw/branch/' . rawurlencode($r['default_branch']) . '/' . $dir . $name;
                }
            }
        }
        return '';
    }

    /** A 128px tile with the plugin's initials, its color picked from the name so it stays the same everywhere. */
    private static function tile(string $name): string
    {
        $words = preg_split('/[\s_-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
        $hue = crc32($name) % 360;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><rect width="128" height="128" fill="hsl(' . $hue . ',55%,45%)"/>'
            . '<text x="64" y="64" dy=".35em" text-anchor="middle" font-family="-apple-system,Segoe UI,Roboto,sans-serif" font-size="' . (strlen($initials) > 1 ? 52 : 64)
            . '" font-weight="600" fill="#fff">' . esc_html($initials) . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Installed plugin file for a catalog repo: matched by Update URI or by the repo Git Plugin installed it from. */
    private static function installed(array $item, array $installed): string
    {
        $want = strtolower(self::owner() . '/' . basename($item['repo']));
        $by_source = array_flip(array_map(function ($s) {
            return strtolower($s['repo'] ?? '');
        }, Git_Plugin::sources()));
        foreach ($installed as $file => $p) {
            if (strtolower(untrailingslashit($p['UpdateURI'] ?? '')) === $want) {
                return $file;
            }
        }
        return isset($by_source[$want]) && isset($installed[$by_source[$want]]) ? $by_source[$want] : '';
    }

    public static function render(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = self::plugins();
        $installed = get_plugins();
        $refresh = wp_nonce_url(admin_url('admin-post.php?action=git_plugin_catalog_refresh'), 'git_plugin_catalog_refresh');
        ?>
        <p class="git-plugin-catalog-intro">
            Plugins published at <a href="<?php echo esc_url(self::owner()); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', self::owner())); ?></a>.
            <a href="<?php echo esc_url($refresh); ?>">Refresh list</a>
        </p>
        <?php
        if ($plugins === null) {
            echo '<div class="notice notice-error inline"><p>Could not reach ' . esc_html(self::owner()) . '. Try again in a moment.</p></div>';
            return;
        }
        if (!$plugins) {
            echo '<p>No plugins found.</p>';
            return;
        }
        echo '<div class="git-plugin-catalog">';
        foreach ($plugins as $item) {
            $file = self::installed($item, $installed);
            ?>
            <div class="plugin-card">
                <div class="plugin-card-top">
                    <div class="name column-name">
                        <h3><a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($item['name']); ?>
                            <img src="<?php echo esc_url(($item['icon'] ?? '') !== '' ? $item['icon'] : self::tile($item['name']), ['https', 'http', 'data']); ?>" class="plugin-icon" alt=""></a></h3>
                    </div>
                    <div class="action-links">
                        <ul class="plugin-action-buttons"><li><?php self::button($item, $file, $installed); ?></li></ul>
                    </div>
                    <div class="desc column-description">
                        <p><?php echo esc_html($item['description']); ?></p>
                    </div>
                </div>
                <div class="plugin-card-bottom">
                    <div class="column-updated">
                        <?php echo $item['version'] !== '' ? 'Version ' . esc_html($item['version']) : ''; ?>
                        <?php if ($file && $installed[$file]['Version'] !== $item['version']) : ?>
                            <br><span class="description">Installed: <?php echo esc_html($installed[$file]['Version']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="column-downloaded">
                        <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener">Repository</a>
                        <?php if ($file && dirname($file) !== '.') : ?>
                            · <a class="thickbox open-plugin-details-modal" href="<?php echo esc_url(self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . rawurlencode(dirname($file)) . '&section=changelog&TB_iframe=true&width=772&height=600')); ?>">Changelog</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
        echo '</div>';
        ?>
        <style>
            .git-plugin-catalog-intro { margin: 1em 0; }
            .git-plugin-catalog { display: flex; flex-wrap: wrap; }
            .git-plugin-catalog form { display: inline; }
        </style>
        <?php
    }

    private static function button(array $item, string $file, array $installed): void
    {
        if ($file === '') {
            if (!current_user_can('install_plugins')) {
                return;
            }
            ?>
            <form method="post" action="<?php echo esc_url(self_admin_url('update.php?action=' . Git_Plugin::ACTION)); ?>">
                <?php wp_nonce_field(Git_Plugin::ACTION); ?>
                <input type="hidden" name="repo" value="<?php echo esc_attr(self::owner() . '/' . basename($item['repo'])); ?>">
                <button type="submit" class="button">Install Now</button>
            </form>
            <?php
        } elseif ($item['version'] !== '' && version_compare($item['version'], $installed[$file]['Version'], '>') && current_user_can('update_plugins')) {
            $url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($file)), 'upgrade-plugin_' . $file);
            echo '<a class="button" href="' . esc_url($url) . '">Update Now</a>';
        } elseif (is_plugin_active($file)) {
            echo '<button type="button" class="button button-disabled" disabled>Active</button>';
        } elseif (current_user_can('activate_plugin', $file)) {
            $url = wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($file)), 'activate-plugin_' . $file);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Activate</a>';
        }
    }

    public static function refresh(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(__('Sorry, you are not allowed to do that.'), 403);
        }
        check_admin_referer('git_plugin_catalog_refresh');
        delete_site_transient(self::CACHE);
        wp_safe_redirect(self_admin_url('plugin-install.php?tab=' . self::TAB));
        exit;
    }
}
