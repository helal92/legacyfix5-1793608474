<?php
$out = [];
$out[] = '=== FIX5 ' . date('c') . ' ===';

$root = '/var/www/vhosts/bahman.ir/httpdocs';
$index = "$root/index.php";
$legacy = "$root/templates/bahman/legacy_redirects.php";
$user = 'bmcars.bahman.ir_vzb4o368qoo';
$fm = '/usr/local/psa/admin/bin/filemng';

$out[] = '--- index 125-145 ---';
$lines = file($index);
for ($i = 124; $i <= 144 && $i < count($lines); $i++) {
    $out[] = ($i + 1) . ':' . rtrim($lines[$i]);
}

$out[] = '--- vhost ---';
$out[] = trim((string) shell_exec('plesk bin site --info bahman.ir 2>/dev/null | grep -iE "WWW root|Name|Status|IP address|Forwarding" | head -30'));
$out[] = 'realpath=' . realpath($root);

$out[] = '--- htaccess product rules ---';
$out[] = trim((string) shell_exec('grep -n "product\\|RewriteEngine\\|RewriteRule" ' . escapeshellarg("$root/.htaccess") . ' | head -40'));

$out[] = '--- direct index.php qs ---';
$out[] = trim((string) shell_exec('curl -skI --connect-timeout 5 --max-time 15 -H "Host: bahman.ir" "https://172.20.14.76/index.php?include=show_product&product_id=231" 2>&1 | head -20'));

$out[] = '--- rewritten path ---';
$out[] = trim((string) shell_exec('curl -skI --connect-timeout 5 --max-time 15 -H "Host: bahman.ir" "https://172.20.14.76/product/231" 2>&1 | head -20'));

// CLI simulation of redirect function
$php = <<<'PHP'
<?php
namespace PHPMaker2020\adoura_base_api;
$_GET = ['include'=>'show_product','product_id'=>'231'];
$_SERVER['REQUEST_URI']='/product/231';
$_SERVER['HTTP_HOST']='bahman.ir';
if (!defined('TEMPLATE_NAME')) define('TEMPLATE_NAME','bahman');
if (!defined('SITE_URL')) define('SITE_URL','https://bahman.ir');
require '/var/www/vhosts/bahman.ir/httpdocs/templates/bahman/legacy_redirects.php';
echo 'fn=' . (function_exists(__NAMESPACE__ . '\\bahman_apply_legacy_redirects') ? 'yes' : 'no') . "\n";
$map = bahman_legacy_product_redirects();
echo 'map231=' . ($map[231] ?? 'MISSING') . "\n";
bahman_apply_legacy_redirects();
echo "NO_REDIRECT\n";
PHP;
file_put_contents('/tmp/sim_legacy.php', $php);
$out[] = "--- cli sim ---\n" . trim((string) shell_exec('/usr/bin/php /tmp/sim_legacy.php 2>&1; echo EXIT:$?'));

// Web probe via filemng
$probe = <<<'PHP'
<?php
header('X-Probe: 1');
chdir('/var/www/vhosts/bahman.ir/httpdocs');
include 'libs/myconf.php';
header('X-Template: ' . TEMPLATE_NAME);
include 'templates/bahman/legacy_redirects.php';
$short = 'bahman_apply_legacy_redirects';
$fq = 'PHPMaker2020\\adoura_base_api\\bahman_apply_legacy_redirects';
header('X-FnShort: ' . (function_exists($short) ? 'yes' : 'no'));
header('X-FnFq: ' . (function_exists($fq) ? 'yes' : 'no'));
$_GET['include'] = 'show_product';
$_GET['product_id'] = '231';
$_SERVER['REQUEST_URI'] = '/product/231';
if (function_exists($fq)) {
    $fq();
}
header('X-Still: 1');
echo 'still-here';
PHP;
file_put_contents('/tmp/legacy_probe.php', $probe);
$dst = "$root/legacy_probe_tmp.php";
$out[] = 'filemng: ' . trim((string) shell_exec(
    escapeshellcmd($fm) . ' ' . escapeshellarg($user) . ' cp ' . escapeshellarg('/tmp/legacy_probe.php') . ' ' . escapeshellarg($dst) . ' 2>&1; echo EXIT:$?'
));
$out[] = 'probe_exists=' . (file_exists($dst) ? 'yes' : 'no');
if (file_exists($dst)) {
    $out[] = "--- probe ---\n" . trim((string) shell_exec(
        'curl -skI --connect-timeout 5 --max-time 15 -H "Host: bahman.ir" "https://172.20.14.76/legacy_probe_tmp.php" 2>&1 | head -30'
    ));
    shell_exec(escapeshellcmd($fm) . ' ' . escapeshellarg($user) . ' rm ' . escapeshellarg($dst) . ' 2>&1');
}

// If index still has split bug, fix with filemng
$raw = file_get_contents($index);
$needs = false;
if (preg_match('/\$legacyApplyFn\s*=\s*TEMPLATE_NAME\s*\./', $raw)) {
    $needs = true;
    $out[] = 'index still has non-FQN assign';
}
if (preg_match('/function_exists\(\s*__NAMESPACE__\s*\.\s*[\'"]\\\\\\\\[\'"]\s*\.\s*\$legacyApplyFn/', $raw)
    && preg_match('/\$legacyApplyFn\s*=\s*TEMPLATE_NAME/', $raw)) {
    $needs = true;
    $out[] = 'index has split exists/call bug';
}

if ($needs || true) {
    // Always normalize the legacy block to local known-good version
    $pattern = '/\/\/ Per-template legacy redirects.*?if \(function_exists\(\$legacyApplyFn\)\) \{\s*\$legacyApplyFn\(\);\s*\}/s';
    $replacement = <<<'PHP'
// Per-template legacy redirects (optional). Example: templates/bahman/legacy_redirects.php
$legacyRedirectsFile = __DIR__ . '/templates/' . TEMPLATE_NAME . '/legacy_redirects.php';
if (is_file($legacyRedirectsFile)) {
	include_once $legacyRedirectsFile;
	$legacyApplyFn = __NAMESPACE__ . '\\' . TEMPLATE_NAME . '_apply_legacy_redirects';
	if (function_exists($legacyApplyFn)) {
		$legacyApplyFn();
	}
PHP;
    $raw2 = preg_replace($pattern, $replacement, $raw, 1, $count);
    $out[] = 'normalize_count=' . $count;
    if ($count > 0 && $raw2 !== null && $raw2 !== $raw) {
        $tmp = '/tmp/index.php.normalized';
        file_put_contents($tmp, $raw2);
        $bak = '/tmp/index.php.bak.' . date('YmdHis');
        shell_exec(escapeshellcmd($fm) . ' ' . escapeshellarg($user) . ' cp ' . escapeshellarg($index) . ' ' . escapeshellarg($bak) . ' 2>&1');
        $out[] = 'deploy: ' . trim((string) shell_exec(
            escapeshellcmd($fm) . ' ' . escapeshellarg($user) . ' cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($index) . ' 2>&1; echo EXIT:$?'
        ));
    } else {
        $out[] = 'no normalize applied (already good or pattern miss)';
        // show current invoke
        if (preg_match('/\$legacyApplyFn\s*=.*;/', $raw, $m)) $out[] = 'assign: ' . $m[0];
        if (preg_match('/if \(function_exists\([^)]+\)\)/', $raw, $m)) $out[] = 'exists: ' . $m[0];
    }
}

// Restart PHP to clear opcache
$out[] = '--- restart php ---';
$out[] = trim((string) shell_exec('plesk bin php_handler --list 2>/dev/null | head -20'));
$out[] = trim((string) shell_exec('systemctl list-units --type=service 2>/dev/null | grep -iE "php|fpm" | head -20'));
foreach (['plesk-php82-fpm', 'plesk-php83-fpm', 'plesk-php84-fpm', 'plesk-php74-fpm', 'php-fpm'] as $svc) {
    $r = shell_exec('systemctl try-reload-or-restart ' . escapeshellarg($svc) . ' 2>&1; echo EXIT:$?');
    if (strpos((string)$r, 'EXIT:0') !== false || stripos((string)$r, 'not found') === false) {
        $out[] = "$svc: " . trim((string)$r);
    }
}
// Also kill opcache via cachetool if any
$out[] = trim((string) shell_exec('killall -USR2 php-fpm 2>&1; ls /opt/plesk/php/*/etc/php.d/*opcache* 2>/dev/null | head'));

$out[] = '--- retest after ---';
foreach (['/product/231', '/product/231/FORCE-F38B', '/index.php?include=show_product&product_id=231', '/product/716'] as $path) {
    $out[] = $path . "\n" . trim((string) shell_exec(
        'curl -skI --connect-timeout 5 --max-time 15 -H "Host: bahman.ir" "https://172.20.14.76' . $path . '" 2>&1 | head -15'
    ));
}

echo implode("\n", $out) . "\n";
