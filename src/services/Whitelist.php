<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\helpers\StringHelper;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Who never gets caught.
 *
 * Two lists and one lock. The address list is cheap and absolute. The user-agent list is neither:
 * a user agent is a string the client picked, so "Googlebot" costs a spammer nothing to type, and
 * an allowlist matched on it alone is a door with a sign on it.
 *
 * Which is why the crawlers that publish reverse DNS get checked against it. Google, Bing,
 * Yahoo, Yandex, Baidu and Apple all document the same procedure — resolve the address to a
 * hostname, check the hostname's domain, resolve the hostname back and see the address again —
 * and a claim that fails it is not whitelisted at all.
 */
class Whitelist extends Component
{
    /**
     * User-agent fragments whose owners publish verifiable reverse DNS, and the domains a genuine
     * one resolves inside.
     *
     * A crawler is only listed here if its operator actually documents forward-confirmed reverse
     * DNS. DuckDuckBot, for one, publishes a fixed address list instead — putting it here would
     * fail every genuine visit it ever made.
     */
    private const VERIFIABLE = [
        'googlebot' => ['googlebot.com', 'google.com', 'googleusercontent.com'],
        'adsbot-google' => ['googlebot.com', 'google.com'],
        'apis-google' => ['google.com', 'googlebot.com'],
        'mediapartners-google' => ['googlebot.com', 'google.com'],
        'feedfetcher-google' => ['google.com', 'googlebot.com'],
        'google-inspectiontool' => ['googlebot.com', 'google.com'],
        'google page speed' => ['google.com', 'googleusercontent.com'],
        'bingbot' => ['search.msn.com'],
        'bingpreview' => ['search.msn.com'],
        'msnbot' => ['search.msn.com'],
        'adidxbot' => ['search.msn.com'],
        'slurp' => ['crawl.yahoo.net'],
        'yandex' => ['yandex.ru', 'yandex.net', 'yandex.com'],
        'baidu' => ['baidu.com', 'baidu.jp'],
        'applebot' => ['applebot.apple.com'],
    ];

    private const CACHE_PREFIX = 'blackhole:verified:';
    private const CACHE_TTL = 86400;

    /**
     * Why this visitor is exempt, or null if they are not.
     *
     * The return is a reason rather than a boolean because it ends up in the log line and in the
     * console output, and "why did this not get caught" is the first question anyone asks.
     */
    public function reason(Visitor $visitor): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->ipIsWhitelisted($visitor->ip)) {
            return Craft::t('blackhole', 'whitelisted address');
        }

        $agent = $this->matchedUserAgent($visitor->userAgent, $settings->whitelistUserAgents);

        if ($agent === null) {
            return null;
        }

        if (!$settings->verifyGoodBots || !$this->isVerifiable($agent)) {
            return Craft::t('blackhole', 'whitelisted user agent ({agent})', ['agent' => $agent]);
        }

        if ($this->verify($visitor, $agent)) {
            return Craft::t('blackhole', 'verified {agent}', ['agent' => $agent]);
        }

        // Claimed to be a crawler that can prove it, and could not. That is not a near miss —
        // it is the single most common way a scraper tries to walk past an allowlist.
        return null;
    }

    public function allows(Visitor $visitor): bool
    {
        return $this->reason($visitor) !== null;
    }

    public function ipIsWhitelisted(?string $ip): bool
    {
        return Ip::matchesAny($ip, Plugin::getInstance()->getSettings()->whitelistIps);
    }

    /**
     * The whitelist entry a user agent matched, or null.
     *
     * @param string[] $patterns
     */
    public function matchedUserAgent(string $userAgent, array $patterns): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $haystack = StringHelper::toLowerCase($userAgent);

        foreach ($patterns as $pattern) {
            $needle = StringHelper::toLowerCase(trim((string)$pattern));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    public function isVerifiable(string $agent): bool
    {
        return isset(self::VERIFIABLE[StringHelper::toLowerCase($agent)]);
    }

    /**
     * Forward-confirmed reverse DNS.
     *
     * Cached for a day and keyed on address plus crawler, because the answer does not change and
     * two DNS round trips inside a request that is already unusual is enough of them.
     */
    public function verify(Visitor $visitor, string $agent): bool
    {
        $agent = StringHelper::toLowerCase($agent);
        $domains = self::VERIFIABLE[$agent] ?? null;

        if ($domains === null || !$visitor->hasIp()) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . $agent . ':' . $visitor->ip;
        $cached = $cache->get($key);

        if ($cached !== false) {
            return $cached === '1';
        }

        $verified = $this->resolveAndConfirm($visitor, $domains);

        $cache->set($key, $verified ? '1' : '0', self::CACHE_TTL);

        return $verified;
    }

    private function resolveAndConfirm(Visitor $visitor, array $domains): bool
    {
        $hostname = $visitor->getHostname();

        if ($hostname === null) {
            return false;
        }

        if (!$this->hostnameIsUnder($hostname, $domains)) {
            return false;
        }

        // The forward half. Without it the check is worthless: anyone who controls the reverse
        // zone for their own address block can point a PTR record at `googlebot.com` and there is
        // nothing Google can do about it — but they cannot make `googlebot.com`'s own DNS agree.
        foreach ($this->forwardAddresses($hostname) as $address) {
            if (Ip::pack($address) === Ip::pack($visitor->ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $domains
     */
    public function hostnameIsUnder(string $hostname, array $domains): bool
    {
        $hostname = StringHelper::toLowerCase(rtrim(trim($hostname), '.'));

        foreach ($domains as $domain) {
            $domain = StringHelper::toLowerCase(trim($domain, ". \t\n\r\0\x0B"));

            if ($hostname === $domain || str_ends_with($hostname, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address a hostname resolves to.
     *
     * `gethostbynamel()` only ever answers with IPv4, so an IPv6 crawler would fail confirmation
     * on a technicality. `dns_get_record()` covers both — and is the one that is disabled on some
     * shared hosts, hence the fallback rather than a choice between them.
     *
     * @return string[]
     */
    public function forwardAddresses(string $hostname): array
    {
        $addresses = [];

        if (function_exists('dns_get_record')) {
            try {
                $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);

                foreach ($records ?: [] as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            } catch (Throwable) {
                // Fall through to the IPv4-only route below.
            }
        }

        if (!$addresses) {
            $v4 = @gethostbynamel($hostname);

            if (is_array($v4)) {
                $addresses = $v4;
            }
        }

        return $addresses;
    }
}
