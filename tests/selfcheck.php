<?php
// Run in the container (needs network): php -d zend.assertions=1 -d assert.exception=1 wp-content/plugins/git-plugin/tests/selfcheck.php
require dirname(__DIR__, 4) . '/wp-load.php';

$v = Git_Plugin::remote_version(['repo' => 'https://git.web9.live/pcgraphics/Git-Plugin', 'ref' => ''], 'git-plugin.php');
assert(preg_match('/^\d+\.\d+/', $v) === 1, "Forgejo default branch version: '$v'");
$v = Git_Plugin::remote_version(['repo' => 'https://github.com/WordPress/hello-dolly', 'ref' => 'trunk'], 'hello.php');
assert(preg_match('/^\d+\.\d+/', $v) === 1, "GitHub branch version: '$v'");
assert(Git_Plugin::remote_version(['repo' => 'https://github.com/WordPress/hello-dolly', 'ref' => ''], 'nope.php') === '');

// A tracked plugin with its own Update URI is left to its own updater.
update_option(Git_Plugin::SOURCES, ['backinator/backinator.php' => ['repo' => 'https://git.web9.live/pcgraphics/Backinator', 'ref' => '']] + Git_Plugin::sources(), false);
$t = Git_Plugin::check_updates((object)['checked' => ['backinator/backinator.php' => '1.0.0'], 'response' => [], 'no_update' => []]);
assert(!isset($t->response['backinator/backinator.php']) && !isset($t->no_update['backinator/backinator.php']));
$s = Git_Plugin::sources(); unset($s['backinator/backinator.php']); update_option(Git_Plugin::SOURCES, $s, false);
echo "ALL OK\n";
