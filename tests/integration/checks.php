<?php
/**
 * Black Hole integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-blackhole/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real rows through Craft's database, the ban cache and its
 * invalidation, the retention sweep, and the pieces of the request path that can be exercised
 * without a request.
 *
 * Idempotent and self-cleaning — every address it touches is documentation-range (TEST-NET-3,
 * 203.0.113.0/24, and 2001:db8::/32) so it can never collide with a real caught bot, and all of
 * it is deleted at the end, including strays from a run that died mid-way.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\migrations\Install;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use justinholtweb\blackhole\records\BotRecord;
use justinholtweb\blackhole\records\HitRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Black Hole is not installed in this site.\n";
    exit(1);
}

/** @var Settings $settings */
$settings = $plugin->getSettings();

// Everything the checks change gets put back, so a run leaves the site exactly as it found it.
$original = [
    'blockThreshold' => $settings->blockThreshold,
    'banDays' => $settings->banDays,
    'whitelistIps' => $settings->whitelistIps,
    'whitelistUserAgents' => $settings->whitelistUserAgents,
    'verifyGoodBots' => $settings->verifyGoodBots,
    'resolveHostnames' => $settings->resolveHostnames,
    'logRetentionDays' => $settings->logRetentionDays,
    'redirectUrl' => $settings->redirectUrl,
    'trapPath' => $settings->trapPath,
    'emailAlerts' => $settings->emailAlerts,
];

// Documentation ranges — reserved by RFC 5737 and RFC 3849 precisely so they can never be a real
// visitor, which is what makes it safe to delete every row matching them.
const TEST_IPS = ['203.0.113.11', '203.0.113.12', '203.0.113.13', '203.0.113.14', '2001:db8::1'];

$cleanup = function() use ($plugin) {
    Craft::$app->getDb()->createCommand()
        ->delete(Install::TABLE_BOTS, ['ip' => TEST_IPS])
        ->execute();
    $plugin->bots->invalidateCache();
};

// Sweep up anything a previous run left behind before starting.
$cleanup();

$visitor = function(string $ip, string $ua = 'EvilScraper/1.0', string $uri = '/blackhole'): Visitor {
    $v = new Visitor();
    $v->ip = $ip;
    $v->userAgent = $ua;
    $v->requestUri = $uri;
    $v->referrer = 'https://example.com/';
    $v->method = 'GET';
    // Pre-resolved so nothing in the suite makes a DNS call it did not ask for.
    $v->setHostname(null);

    return $v;
};

// ---------------------------------------------------------------------------- addresses

section('Addresses — matching');

check('an exact address matches itself', function() {
    return Ip::matches('203.0.113.7', '203.0.113.7') ?: 'no match';
});

check('a different address does not', function() {
    return !Ip::matches('203.0.113.7', '203.0.113.8') ?: 'matched';
});

check('a dotted prefix matches everything under it', function() {
    return Ip::matches('203.0.113.7', '203.0.113.') && !Ip::matches('203.0.114.7', '203.0.113.')
        ?: 'prefix matching is wrong';
});

check('a v4 CIDR range includes its members and excludes its neighbours', function() {
    return Ip::inRange('203.0.113.7', '203.0.113.0/24')
        && !Ip::inRange('203.0.114.7', '203.0.113.0/24')
        ?: 'CIDR v4 is wrong';
});

check('a CIDR boundary that is not on a byte works', function() {
    // /28 is four bits into the last byte, which is the case a whole-byte comparison gets wrong.
    return Ip::inRange('203.0.113.14', '203.0.113.0/28')
        && !Ip::inRange('203.0.113.16', '203.0.113.0/28')
        ?: '/28 boundary is wrong';
});

check('a /32 is one address and a /0 is all of them', function() {
    return Ip::inRange('203.0.113.7', '203.0.113.7/32')
        && !Ip::inRange('203.0.113.8', '203.0.113.7/32')
        && Ip::inRange('198.51.100.1', '0.0.0.0/0')
        ?: 'edge prefixes are wrong';
});

check('a v6 CIDR range works', function() {
    return Ip::inRange('2001:db8::1', '2001:db8::/32')
        && !Ip::inRange('2001:db9::1', '2001:db8::/32')
        ?: 'CIDR v6 is wrong';
});

check('the same v6 address written three ways is one address', function() {
    // A string comparison gets this wrong all three times, which is why matching is done on
    // packed bytes.
    return Ip::matches('2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1')
        && Ip::matches('2001:db8:0:0:0:0:0:1', '2001:db8::1')
        ?: 'v6 spellings are not being normalised';
});

check('a v4 address is never inside a v6 range', function() {
    return !Ip::inRange('203.0.113.7', '2001:db8::/32')
        && !Ip::inRange('2001:db8::1', '203.0.113.0/24')
        ?: 'families are being mixed';
});

check('a malformed pattern fails closed', function() {
    // The dangerous failure here is a garbage entry that matches everything.
    return !Ip::matches('203.0.113.7', 'not-an-address')
        && !Ip::inRange('203.0.113.7', 'nonsense/24')
        ?: 'a malformed pattern matched';
});

check('an empty pattern matches nothing', function() {
    return !Ip::matches('203.0.113.7', '') && !Ip::matchesAny('203.0.113.7', ['', '  '])
        ?: 'an empty pattern matched';
});

check('a bracketed address with no port still parses', function() {
    return Ip::isValid('[2001:db8::1]') ?: 'brackets broke it';
});

check('normalising is idempotent and leaves junk alone', function() {
    return Ip::normalize('2001:0db8::0001') === '2001:db8::1'
        && Ip::normalize('nonsense') === 'nonsense'
        ?: 'normalise: ' . Ip::normalize('2001:0db8::0001');
});

// ---------------------------------------------------------------------------- settings

section('Settings — what comes back from the control panel');

check('an editable table posts rows, and the list comes back flat', function() {
    $model = new Settings();
    $model->whitelistIps = [['value' => '203.0.113.0/24'], ['value' => '198.51.100.7']];
    $model->validate();

    return $model->whitelistIps === ['203.0.113.0/24', '198.51.100.7']
        ?: var_export($model->whitelistIps, true);
});

check('blanks and duplicates are dropped', function() {
    $model = new Settings();
    $model->whitelistUserAgents = [['value' => 'googlebot'], ['value' => ' '], ['value' => 'googlebot']];
    $model->validate();

    return $model->whitelistUserAgents === ['googlebot'] ?: var_export($model->whitelistUserAgents, true);
});

check('clearing a table clears the setting', function() {
    // Yii skips inline validators on empty attributes, and an empty array is empty — so without
    // `skipOnEmpty => false` this is the one case the normaliser never sees.
    $model = new Settings();
    $model->whitelistIps = [];
    $model->validate();

    return $model->whitelistIps === [] ?: var_export($model->whitelistIps, true);
});

check('a flat list from a config file survives untouched', function() {
    $model = new Settings();
    $model->whitelistIps = ['203.0.113.0/24'];
    $model->validate();

    return $model->whitelistIps === ['203.0.113.0/24'] ?: var_export($model->whitelistIps, true);
});

check('the trap path loses its slashes', function() {
    $model = new Settings();
    $model->trapPath = '/trap/door/';

    return $model->normalizedTrapPath() === 'trap/door' ?: $model->normalizedTrapPath();
});

check('an empty trap path is an error, not a crash', function() {
    $model = new Settings();
    $model->trapPath = '  ';

    return !$model->validate() && $model->hasErrors('trapPath') ?: 'no error raised';
});

check('a trap path inside the control panel is refused', function() {
    $model = new Settings();
    $model->trapPath = (Craft::$app->getConfig()->getGeneral()->cpTrigger ?: 'admin') . '/blackhole';

    return !$model->validate() && $model->hasErrors('trapPath') ?: 'the CP path was accepted';
});

check('a nonsense status code is refused', function() {
    $model = new Settings();
    $model->blockedStatusCode = 200;

    return !$model->validate() && $model->hasErrors('blockedStatusCode') ?: '200 was accepted';
});

check('the defaults are valid, which is what a fresh install saves', function() {
    return (new Settings())->validate() ?: var_export((new Settings())->getErrors(), true);
});

// ---------------------------------------------------------------------------- whitelist

section('Whitelist — claims versus facts');

check('an address on the list is exempt', function() use ($plugin, $settings, $visitor) {
    $settings->whitelistIps = ['203.0.113.0/24'];

    return $plugin->whitelist->reason($visitor('203.0.113.11')) !== null ?: 'not exempt';
});

check('an address outside the list is not', function() use ($plugin, $settings, $visitor) {
    $settings->whitelistIps = ['203.0.113.0/24'];

    return $plugin->whitelist->reason($visitor('198.51.100.1')) === null ?: 'exempt anyway';
});

check('a user agent is matched as a case-insensitive substring', function() use ($plugin) {
    return $plugin->whitelist->matchedUserAgent('Mozilla/5.0 (compatible; Slackbot 1.0)', ['slackbot']) === 'slackbot'
        ?: 'no match';
});

check('a whitelisted agent nobody can verify is simply allowed', function() use ($plugin, $settings, $visitor) {
    $settings->whitelistIps = [];
    $settings->whitelistUserAgents = ['slackbot'];
    $settings->verifyGoodBots = true;

    return $plugin->whitelist->reason($visitor('203.0.113.11', 'Slackbot-LinkExpanding 1.0')) !== null
        ?: 'a plain whitelisted agent was refused';
});

check('a spoofed Googlebot is NOT whitelisted', function() use ($plugin, $settings, $visitor) {
    // The headline case. A documentation-range address has no PTR record, so forward-confirmed
    // reverse DNS fails, so the claim is worthless — which is the entire point of checking.
    $settings->whitelistIps = [];
    $settings->whitelistUserAgents = ['googlebot'];
    $settings->verifyGoodBots = true;

    return $plugin->whitelist->reason($visitor('203.0.113.11', 'Googlebot/2.1')) === null
        ?: 'a spoofed Googlebot walked through the allowlist';
});

check('turning verification off lets the claim through', function() use ($plugin, $settings, $visitor) {
    $settings->whitelistIps = [];
    $settings->whitelistUserAgents = ['googlebot'];
    $settings->verifyGoodBots = false;

    return $plugin->whitelist->reason($visitor('203.0.113.11', 'Googlebot/2.1')) !== null
        ?: 'verification is still being enforced';
});

check('only crawlers that publish reverse DNS are asked to prove anything', function() use ($plugin) {
    return $plugin->whitelist->isVerifiable('googlebot')
        && $plugin->whitelist->isVerifiable('bingbot')
        && !$plugin->whitelist->isVerifiable('duckduckbot')
        && !$plugin->whitelist->isVerifiable('slackbot')
        ?: 'the verifiable set is wrong';
});

check('a hostname has to be under the domain, not merely contain it', function() use ($plugin) {
    return $plugin->whitelist->hostnameIsUnder('crawl-66-249-66-1.googlebot.com', ['googlebot.com'])
        && $plugin->whitelist->hostnameIsUnder('googlebot.com', ['googlebot.com'])
        && !$plugin->whitelist->hostnameIsUnder('googlebot.com.evil.example', ['googlebot.com'])
        && !$plugin->whitelist->hostnameIsUnder('notgooglebot.com', ['googlebot.com'])
        ?: 'suffix matching is wrong';
});

check('a trailing dot on a hostname does not defeat the check', function() use ($plugin) {
    return $plugin->whitelist->hostnameIsUnder('crawl-1.googlebot.com.', ['googlebot.com'])
        ?: 'the root dot broke it';
});

check('a visitor with no address is never exempt by accident', function() use ($plugin, $settings) {
    $settings->whitelistIps = [];
    $blank = new Visitor();

    return !$blank->hasIp() && !$plugin->whitelist->ipIsWhitelisted('') ?: 'a blank address was whitelisted';
});

// ---------------------------------------------------------------------------- the trap

section('The trap — link and paths');

check('the hidden link is hidden, unfollowable and unreachable', function() use ($plugin) {
    $html = (string)$plugin->trap->linkHtml();

    return str_contains($html, 'display: none')
        && str_contains($html, 'rel="nofollow"')
        && str_contains($html, 'aria-hidden="true"')
        && str_contains($html, 'tabindex="-1"')
        ?: $html;
});

check('the link carries the warning, which is what makes the ban defensible', function() use ($plugin, $settings) {
    return str_contains((string)$plugin->trap->linkHtml(), htmlspecialchars($settings->linkTitle, ENT_QUOTES))
        ?: 'no title attribute';
});

check('the link points at the trap', function() use ($plugin) {
    return str_contains((string)$plugin->trap->linkHtml(), $plugin->trap->url()) ?: 'wrong href';
});

check('the robots path is built from the site, not from the URL format', function() use ($plugin, $settings) {
    // On a site with `omitScriptNameInUrls` off, parsing `url()` would produce `/index.php`,
    // and `Disallow: /index.php` would take the whole site out of the index.
    $path = $plugin->trap->robotsPath();

    return !str_contains($path, 'index.php')
        && !str_contains($path, '?')
        && str_ends_with($path, '/' . $settings->normalizedTrapPath())
        ?: $path;
});

check('the robots path has no trailing slash, so it covers every mangling of the link', function() use ($plugin) {
    // `Disallow` is a prefix match: `/blackhole` covers `/blackhole`, `/blackhole/` and
    // `/blackhole/anything`. A trailing slash would cover only the last.
    return !str_ends_with($plugin->trap->robotsPath(), '/') ?: $plugin->trap->robotsPath();
});

check('the trap is not armed for a console request', function() use ($plugin) {
    return !$plugin->trap->requestIsEligible() ?: 'the console counts as eligible';
});

// ---------------------------------------------------------------------------- robots.txt

section('robots.txt — the other half of the trap');

check('the directives name every trap path on the install', function() use ($plugin) {
    $directives = $plugin->robots->directives();

    foreach ($plugin->robots->paths() as $path) {
        if (!str_contains($directives, 'Disallow: ' . $path)) {
            return "missing $path";
        }
    }

    return str_contains($directives, 'User-agent: *') ?: 'no user-agent line';
});

check('a file that disallows the trap is recognised', function() use ($plugin) {
    $path = $plugin->robots->filePath();

    if ($path === '') {
        return 'no web root found';
    }

    $backup = is_file($path) ? file_get_contents($path) : null;

    try {
        file_put_contents($path, $plugin->robots->directives());
        $covered = $plugin->robots->fileCoversTrap();

        file_put_contents($path, "User-agent: *\nDisallow: /nothing-to-do-with-us\n");
        $notCovered = $plugin->robots->fileCoversTrap();

        return ($covered && !$notCovered) ?: "covered=" . var_export($covered, true) . " notCovered=" . var_export($notCovered, true);
    } finally {
        if ($backup === null) {
            @unlink($path);
        } else {
            file_put_contents($path, $backup);
        }
    }
});

check('writing the directives is idempotent and does not eat what was there', function() use ($plugin) {
    $path = $plugin->robots->filePath();
    $backup = is_file($path) ? file_get_contents($path) : null;

    try {
        file_put_contents($path, "# somebody else's file\nUser-agent: *\nDisallow: /private\n");

        $first = $plugin->robots->write();
        $second = $plugin->robots->write();
        $contents = file_get_contents($path);

        return $first['written']
            && !$second['written']
            && str_contains($contents, "somebody else's file")
            && str_contains($contents, 'Disallow: /private')
            && substr_count($contents, 'Disallow: ' . $plugin->trap->robotsPath()) === 1
            ?: $contents;
    } finally {
        if ($backup === null) {
            @unlink($path);
        } else {
            file_put_contents($path, $backup);
        }
    }
});

check('the plugin refuses to shadow a robots.txt that exists', function() use ($plugin, $settings) {
    $path = $plugin->robots->filePath();
    $backup = is_file($path) ? file_get_contents($path) : null;
    $wasServing = $settings->serveRobotsTxt;

    try {
        $settings->serveRobotsTxt = true;

        file_put_contents($path, "User-agent: *\n");
        $withFile = $plugin->robots->shouldServe();

        @unlink($path);
        $withoutFile = $plugin->robots->shouldServe();

        return (!$withFile && $withoutFile) ?: "withFile=" . var_export($withFile, true);
    } finally {
        $settings->serveRobotsTxt = $wasServing;

        if ($backup !== null) {
            file_put_contents($path, $backup);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
});

// ---------------------------------------------------------------------------- the ledger

section('The ledger — catching');

check('one trip is enough by default', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->banDays = 0;
    $settings->whitelistIps = [];
    $settings->whitelistUserAgents = [];
    $settings->resolveHostnames = false;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    return $record->status === Settings::STATUS_BLOCKED && (int)$record->hits === 1
        ?: "status={$record->status} hits={$record->hits}";
});

check('a threshold above one puts an address on notice first', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 3;

    $first = $plugin->bots->catchVisitor($visitor('203.0.113.12'));
    $second = $plugin->bots->catchVisitor($visitor('203.0.113.12'));
    $third = $plugin->bots->catchVisitor($visitor('203.0.113.12'));

    return $first->status === Settings::STATUS_WARNED
        && $second->status === Settings::STATUS_WARNED
        && $third->status === Settings::STATUS_BLOCKED
        && (int)$third->hits === 3
        ?: "{$first->status}/{$second->status}/{$third->status}";
});

check('coming back updates the one row rather than starting another', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    $rows = (new Query())->from(Install::TABLE_BOTS)->where(['ip' => '203.0.113.11'])->count();

    return (int)$rows === 1 ?: "$rows rows";
});

check('a v6 address is stored in one canonical spelling', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $plugin->bots->catchVisitor($visitor('2001:0db8:0000::0001'));
    $found = $plugin->bots->findByIp('2001:db8::1');

    return $found !== null && $found->ip === '2001:db8::1' ?: 'stored as ' . ($found->ip ?? 'nothing');
});

check('a trip writes a history row', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11', 'EvilScraper/1.0', '/blackhole/wp-admin'));
    $hits = $plugin->bots->hits((int)$record->id);

    return count($hits) === 1
        && $hits[0]['kind'] === HitRecord::KIND_TRAP
        && $hits[0]['requestUri'] === '/blackhole/wp-admin'
        ?: var_export($hits, true);
});

check('a ban with a length on it gets an expiry, and a permanent one does not', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $settings->banDays = 7;
    $temporary = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    $settings->banDays = 0;
    $permanent = $plugin->bots->catchVisitor($visitor('203.0.113.12'));

    return $temporary->dateExpires !== null && $permanent->dateExpires === null
        ?: "temporary={$temporary->dateExpires} permanent=" . var_export($permanent->dateExpires, true);
});

section('The ledger — blocking');

check('a caught address is blocked', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->banDays = 0;

    $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    return $plugin->bots->isBlocked('203.0.113.11') ?: 'not blocked';
});

check('an address nobody has heard of is not', function() use ($plugin) {
    return !$plugin->bots->isBlocked('203.0.113.99') ?: 'blocked anyway';
});

check('an address on notice is not blocked yet', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 5;

    $plugin->bots->catchVisitor($visitor('203.0.113.13'));

    return !$plugin->bots->isBlocked('203.0.113.13') ?: 'blocked too early';
});

check('a lapsed ban stops blocking without losing the row', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->banDays = 0;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    // Backdate the expiry rather than waiting a week for it.
    $record->dateExpires = Db::prepareDateForDb(new DateTime('-1 hour', new DateTimeZone('UTC')));
    $record->save(false);
    $plugin->bots->invalidateCache();

    $stillThere = $plugin->bots->findByIp('203.0.113.11') !== null;

    return !$plugin->bots->isBlocked('203.0.113.11') && $stillThere
        ?: 'a lapsed ban is still blocking, or the row vanished';
});

check('the ban cache is dropped when the ledger changes', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->banDays = 0;

    // Warm the cache with a "no" for this address, then catch it. Without invalidation the guard
    // would keep answering from the stale map and the very next request would sail through.
    $plugin->bots->isBlocked('203.0.113.14');
    $plugin->bots->catchVisitor($visitor('203.0.113.14'));

    return $plugin->bots->isBlocked('203.0.113.14') ?: 'the cache went stale';
});

check('a blocked attempt is counted without a read-modify-write', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    $plugin->bots->noteBlockedAttempt('203.0.113.11', $visitor('203.0.113.11', 'EvilScraper/1.0', '/pricing'));
    $plugin->bots->noteBlockedAttempt('203.0.113.11', $visitor('203.0.113.11', 'EvilScraper/1.0', '/about'));

    $fresh = $plugin->bots->findById((int)$record->id);

    return (int)$fresh->blockedHits === 2 ?: "blockedHits={$fresh->blockedHits}";
});

check('a flood leaves a readable trail rather than a row per request', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    for ($i = 0; $i < 20; $i++) {
        $plugin->bots->noteBlockedAttempt('203.0.113.11', $visitor('203.0.113.11'));
    }

    $fresh = $plugin->bots->findById((int)$record->id);
    $hits = $plugin->bots->hits((int)$record->id);

    // Every attempt counted; at most the trap row plus one throttled history row written.
    return (int)$fresh->blockedHits === 20 && count($hits) <= 2
        ?: "blockedHits={$fresh->blockedHits} rows=" . count($hits);
});

check('noting an attempt for an address that is not in the ledger does nothing', function() use ($plugin, $visitor, $cleanup) {
    $cleanup();

    $plugin->bots->noteBlockedAttempt('203.0.113.99', $visitor('203.0.113.99'));

    return (new Query())->from(Install::TABLE_BOTS)->where(['ip' => '203.0.113.99'])->count() === '0'
        || (int)(new Query())->from(Install::TABLE_BOTS)->where(['ip' => '203.0.113.99'])->count() === 0
        ?: 'a row was invented';
});

section('The ledger — managing');

check('an address can be blocked by hand without ever tripping the trap', function() use ($plugin, $settings, $cleanup) {
    $cleanup();
    $settings->banDays = 0;

    $record = $plugin->bots->block('203.0.113.11', 'by hand');

    return $record !== null
        && $plugin->bots->isBlocked('203.0.113.11')
        && $record->note === 'by hand'
        ?: 'manual block failed';
});

check('blocking something that is not an address is refused', function() use ($plugin) {
    return $plugin->bots->block('not-an-address') === null ?: 'a non-address was blocked';
});

check('releasing keeps the row and resets the count', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 3;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->release($record);

    $fresh = $plugin->bots->findByIp('203.0.113.11');

    // The reset is the point: a second chance should be a real one, not a ban on the next trip
    // because an old count was still sitting there.
    return $fresh !== null
        && $fresh->status === Settings::STATUS_RELEASED
        && (int)$fresh->hits === 0
        && !$plugin->bots->isBlocked('203.0.113.11')
        ?: "status={$fresh->status} hits={$fresh->hits}";
});

check('a released address gets the full run-up if it comes back', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 3;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->release($record);

    $again = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    return $again->status === Settings::STATUS_WARNED && (int)$again->hits === 1
        ?: "status={$again->status} hits={$again->hits}";
});

check('deleting a bot takes its history with it', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $id = (int)$record->id;

    $plugin->bots->delete($record);

    $orphans = (new Query())->from(Install::TABLE_HITS)->where(['botId' => $id])->count();

    return (int)$orphans === 0 && $plugin->bots->findByIp('203.0.113.11') === null
        ?: "$orphans orphaned hits";
});

check('the counts add up', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $before = $plugin->bots->counts();

    $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->catchVisitor($visitor('203.0.113.12'));

    $after = $plugin->bots->counts();

    return $after[Settings::STATUS_BLOCKED] === $before[Settings::STATUS_BLOCKED] + 2
        && $after['all'] === $before['all'] + 2
        ?: var_export($after, true);
});

check('the index query filters by status and searches the useful columns', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;

    $plugin->bots->catchVisitor($visitor('203.0.113.11', 'NeedleBot/9.9'));
    $plugin->bots->catchVisitor($visitor('203.0.113.12', 'OtherBot/1.0'));

    $byAgent = $plugin->bots->query(null, 'NeedleBot')->count();
    $byAddress = $plugin->bots->query(Settings::STATUS_BLOCKED, '203.0.113.12')->count();
    $wrongStatus = $plugin->bots->query(Settings::STATUS_RELEASED, 'NeedleBot')->count();

    return (int)$byAgent === 1 && (int)$byAddress === 1 && (int)$wrongStatus === 0
        ?: "agent=$byAgent address=$byAddress wrongStatus=$wrongStatus";
});

check('pruning drops old history and keeps the ban', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->logRetentionDays = 30;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    Craft::$app->getDb()->createCommand()
        ->update(
            Install::TABLE_HITS,
            ['dateCreated' => Db::prepareDateForDb(new DateTime('-90 days', new DateTimeZone('UTC')))],
            ['botId' => $record->id]
        )
        ->execute();

    $plugin->bots->prune();

    return count($plugin->bots->hits((int)$record->id)) === 0
        && $plugin->bots->findByIp('203.0.113.11') !== null
        ?: 'pruning took the wrong thing';
});

check('pruning eventually forgets a released address, and never a blocked one', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->logRetentionDays = 30;

    $released = $plugin->bots->catchVisitor($visitor('203.0.113.11'));
    $plugin->bots->release($released);

    $blocked = $plugin->bots->catchVisitor($visitor('203.0.113.12'));

    $old = Db::prepareDateForDb(new DateTime('-90 days', new DateTimeZone('UTC')));

    Craft::$app->getDb()->createCommand()
        ->update(Install::TABLE_BOTS, ['dateLastSeen' => $old], ['id' => [$released->id, $blocked->id]])
        ->execute();

    $plugin->bots->prune();

    return $plugin->bots->findByIp('203.0.113.11') === null
        && $plugin->bots->findByIp('203.0.113.12') !== null
        ?: 'the retention sweep took the wrong rows';
});

check('a retention of zero keeps everything', function() use ($plugin, $settings, $visitor, $cleanup) {
    $cleanup();
    $settings->blockThreshold = 1;
    $settings->logRetentionDays = 0;

    $record = $plugin->bots->catchVisitor($visitor('203.0.113.11'));

    Craft::$app->getDb()->createCommand()
        ->update(
            Install::TABLE_HITS,
            ['dateCreated' => Db::prepareDateForDb(new DateTime('-3650 days', new DateTimeZone('UTC')))],
            ['botId' => $record->id]
        )
        ->execute();

    $plugin->bots->prune();

    return count($plugin->bots->hits((int)$record->id)) === 1 ?: 'history was pruned with retention off';
});

// ---------------------------------------------------------------------------- the guard

section('The guard — turning requests away');

check('an on-site redirect is refused, because it would loop', function() use ($plugin, $settings) {
    $settings->redirectUrl = 'https://' . (Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '');

    return !$plugin->guard->redirectIsSafe('/somewhere-on-this-site')
        ?: 'a relative redirect was accepted';
});

check('an off-site redirect is fine', function() use ($plugin) {
    return $plugin->guard->redirectIsSafe('https://example.com/go-away') ?: 'an off-site redirect was refused';
});

check('the block page says which address was blocked', function() use ($plugin, $settings) {
    $v = new Visitor();
    $v->ip = '203.0.113.11';
    $v->userAgent = 'EvilScraper/1.0';

    $html = $plugin->guard->renderBlockedPage($v);

    return str_contains($html, '203.0.113.11')
        && str_contains($html, $settings->blockedMessage)
        && str_contains($html, 'noindex')
        ?: substr($html, 0, 200);
});

check('the guard has not fired on a console request', function() use ($plugin) {
    return !$plugin->guard->hasTriggered() ?: 'the guard fired with nobody there';
});

// ---------------------------------------------------------------------------- injection

section('The hidden link — getting it onto the page');

check('the link goes in before the last closing body tag', function() use ($plugin) {
    $html = '<html><body><p>hello</p></body></html>';
    $spliced = $plugin->spliceIntoBody($html, '<a id="trap"></a>');

    return $spliced === '<html><body><p>hello</p><a id="trap"></a></body></html>' ?: $spliced;
});

check('the LAST closing body tag, not one quoted in the page', function() use ($plugin) {
    // A code sample, an escaped snippet, a textarea holding markup somebody is editing.
    $html = '<html><body><code>&lt;/body&gt;</code><pre></body></pre></body></html>';
    $spliced = $plugin->spliceIntoBody($html, '<a id="trap"></a>');

    return str_ends_with($spliced, '<a id="trap"></a></body></html>') ?: $spliced;
});

check('a page with no body is left exactly as it was', function() use ($plugin) {
    return $plugin->spliceIntoBody('{"json":true}', '<a></a>') === null ?: 'spliced into a non-page';
});

check('only text/html is decorated', function() use ($plugin) {
    // Craft renders front-end templates through its own `template` format and takes the MIME type
    // from the file extension, so `feed.rss.twig` is a template response that must be left alone.
    return $plugin->isHtmlResponse('text/html; charset=UTF-8')
        && $plugin->isHtmlResponse('TEXT/HTML')
        && !$plugin->isHtmlResponse('application/rss+xml')
        && !$plugin->isHtmlResponse('application/json')
        ?: 'content-type detection is wrong';
});

check('a link the author placed themselves wins', function() use ($plugin) {
    $mine = '<html><body>' . $plugin->trap->linkHtml() . '</body></html>';

    return $plugin->alreadyLinked($mine) && !$plugin->alreadyLinked('<html><body></body></html>')
        ?: 'duplicate detection is wrong';
});

// ---------------------------------------------------------------------------- variable

section('Twig');

check('craft.blackhole hands templates the link, the URL and the directives', function() use ($plugin) {
    $variable = new justinholtweb\blackhole\twig\BlackholeVariable();

    return str_contains((string)$variable->link(), 'nofollow')
        && $variable->trapUrl() === $plugin->trap->url()
        && str_contains((string)$variable->robots(), 'Disallow:')
        && is_array($variable->counts())
        ?: 'the variable is missing something';
});

check('link options override the defaults', function() use ($plugin) {
    $variable = new justinholtweb\blackhole\twig\BlackholeVariable();
    $html = (string)$variable->link(['text' => 'nope', 'title' => 'Stay out']);

    return str_contains($html, '>nope<') && str_contains($html, 'Stay out') ?: $html;
});

// ---------------------------------------------------------------------------- done

$cleanup();

foreach ($original as $key => $value) {
    $settings->$key = $value;
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
