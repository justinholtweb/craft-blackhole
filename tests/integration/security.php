<?php
/**
 * Whether somebody else can get an innocent address banned — checked in the plugin-testing
 * harness, in-process.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-blackhole/tests/integration/security.php
 *
 * Until 5.0.1 any request that reached the trap counted. So an `<img src>` pointing at it on a busy
 * page elsewhere put every one of that page's visitors on this site's blocklist — their browsers
 * fetched it, with no cookies, so "never catch logged-in users" could not help. And under Craft's
 * default `trustedHosts`, a request could simply *claim* a victim's address in `X-Forwarded-For`.
 *
 * In-process on purpose. Over HTTP the visitor would be the harness itself, and with a threshold of
 * one and bans that never expire, one wrong answer would lock every other session out. Every
 * visitor here is a TEST-NET documentation address, and each one caught is deleted.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\web\Request as WebRequest;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use justinholtweb\blackhole\records\BotRecord;
use justinholtweb\blackhole\services\Trap;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$plugin->getSettings()->enabled = true;
$plugin->getSettings()->whitelistIps = [];
$consoleRequest = Craft::$app->getRequest();
$caught = [];

register_shutdown_function(function() use (&$caught, $consoleRequest) {
    Craft::$app->set('request', $consoleRequest);
    foreach ($caught as $ip) {
        BotRecord::deleteAll(['ip' => $ip]);
    }
});

/**
 * Springs the trap for a TEST-NET visitor, inside a web request carrying these headers. Returns
 * whether they were caught, and why not if they were not.
 *
 * @param array<string, string> $headers
 * @return array{0: bool, 1: ?string}
 */
$spring = static function(string $ip, array $headers = [], string $remote = '', array $trustedHosts = ['any']) use ($plugin, &$caught): array {
    $_SERVER['REMOTE_ADDR'] = $remote !== '' ? $remote : $ip;
    $request = Craft::createObject(['class' => WebRequest::class, 'cookieValidationKey' => 'blackhole-security']);
    $request->trustedHosts = $trustedHosts;
    foreach ($headers as $name => $value) {
        $request->getHeaders()->set($name, $value);
    }
    Craft::$app->set('request', $request);

    $visitor = new Visitor();
    $visitor->ip = $ip;
    $visitor->userAgent = 'blackhole-security-check';
    $visitor->requestUri = '/trap';
    $caught[] = $ip;

    $record = $plugin->trap->spring($visitor);

    return [$record !== null, $plugin->trap->lastExemption];
};

echo "\nWhat a browser says about the request\n";

check('a crawler with no Sec-Fetch headers is caught', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.10');

    return $hit ?: "let through: $why";
});

check('a page load from this site is caught', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.11', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document']);

    return $hit ?: "let through: $why";
});

check('an <img> on another site is not', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.12', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image']);

    return !$hit && str_contains((string)$why, 'another site') ?: 'caught';
});

check('nor a navigation sent from another site', function() use ($spring) {
    [$hit] = $spring('203.0.113.13', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document']);

    return !$hit ?: 'caught';
});

check('nor an embedded resource on this site — a posted comment’s <img>, say', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.14', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'image']);

    return !$hit && str_contains((string)$why, 'embedded') ?: 'caught';
});

check('the rule, as a table', function() {
    $cases = [
        [null, null, true],
        ['none', 'document', true],
        ['same-site', 'document', true],
        ['cross-site', 'document', false],
        ['cross-site', null, false],
        ['same-origin', 'script', false],
        ['same-origin', 'iframe', false],
        ['CROSS-SITE', 'Image', false],
        ['Cross-Site', 'Document', false],
    ];

    foreach ($cases as [$site, $dest, $counts]) {
        if ((Trap::fetchExemption($site, $dest) === null) !== $counts) {
            return 'wrong for ' . json_encode([$site, $dest]);
        }
    }

    return true;
});

echo "\nWhose address it is\n";

check('an address only claimed in X-Forwarded-For is not banned', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.20', ['X-Forwarded-For' => '203.0.113.20'], '192.0.2.50');

    return !$hit && str_contains((string)$why, 'forwarding header') ?: 'caught: ' . var_export($why, true);
});

check('…but it is once trustedHosts names the proxy it came through', function() use ($spring) {
    [$hit, $why] = $spring('203.0.113.21', ['X-Forwarded-For' => '203.0.113.21'], '192.0.2.50', ['192.0.2.50']);

    return $hit ?: "let through: $why";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
