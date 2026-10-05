<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use justinholtweb\blackhole\records\BotRecord;
use Throwable;
use Twig\Markup;
use yii\base\Component;

/**
 * The honeypot itself: the link, the URL it points at, and what happens to whoever follows it.
 */
class Trap extends Component
{
    /** @var string|null Why the last `spring()` let someone through. */
    public ?string $lastExemption = null;

    /**
     * The trap path, with no slashes on either end — the form Craft's URL rules want.
     */
    public function path(): string
    {
        return Plugin::getInstance()->getSettings()->normalizedTrapPath();
    }

    /**
     * The trap's absolute URL, in whatever URL format the site is configured for.
     */
    public function url(?int $siteId = null): string
    {
        return UrlHelper::siteUrl($this->path(), null, null, $siteId);
    }

    /**
     * The path a robots.txt directive should name.
     *
     * Built from the site's base path rather than by parsing `url()`, because `url()` honours the
     * site's URL format — and on a site with `omitScriptNameInUrls` off that is
     * `/index.php?p=blackhole`, which would put `Disallow: /index.php` in robots.txt and take the
     * entire site out of the index. Not a subtle failure, but a very quiet one.
     *
     * No trailing slash, deliberately. A robots.txt `Disallow` is a prefix match, so
     * `Disallow: /blackhole` covers `/blackhole`, `/blackhole/` and `/blackhole/anything` — every
     * form a crawler might mangle the link into. A trailing slash would cover only the last.
     */
    public function robotsPath(?int $siteId = null): string
    {
        $sites = Craft::$app->getSites();
        $site = $siteId !== null ? $sites->getSiteById($siteId) : $sites->getCurrentSite();
        $base = '';

        if ($site !== null) {
            $path = parse_url((string)$site->getBaseUrl(), PHP_URL_PATH);

            if (is_string($path)) {
                $base = rtrim($path, '/');
            }
        }

        return $base . '/' . $this->path();
    }

    /**
     * The hidden anchor.
     *
     * Three attributes are doing work beyond `display:none`. `rel="nofollow"` is the instruction
     * a crawler is meant to honour. `aria-hidden` and `tabindex="-1"` are the ones the WordPress
     * original leaves out: without them the link is in the accessibility tree and in the tab
     * order, so a screen reader announces the trap and a keyboard user can walk straight into it.
     * A honeypot that catches people is a bug.
     *
     * @param array<string, mixed> $options
     */
    public function linkHtml(array $options = []): Markup
    {
        $settings = Plugin::getInstance()->getSettings();

        $attributes = [
            'rel' => 'nofollow',
            'href' => $options['url'] ?? $this->url($options['siteId'] ?? null),
            'title' => $options['title'] ?? $settings->linkTitle,
            'style' => 'display:none;',
            'aria-hidden' => 'true',
            'tabindex' => '-1',
        ];

        if (isset($options['class'])) {
            $attributes['class'] = $options['class'];
        }

        $text = $options['text'] ?? '&nbsp;';

        return Template::raw(Html::tag('a', $text, $attributes));
    }

    // ------------------------------------------------------------------ catching

    /**
     * Whether this request is one the trap is allowed to act on at all.
     *
     * The control panel is never trapped, console requests have no visitor, and a disabled plugin
     * does nothing. Being logged in is checked here too — it is the setting that stops a staff
     * machine with an over-eager link prefetcher from locking out the office.
     */
    public function requestIsEligible(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return false;
        }

        if ($settings->ignoreLoggedIn && !Craft::$app->getUser()->getIsGuest()) {
            return false;
        }

        return true;
    }

    /**
     * Why a request that a browser describes this way must not count, or null if it may.
     *
     * A browser says where a request came from in `Sec-Fetch-*`. A hit counts only as a page load
     * the browser made from this site — `document`, `same-origin`/`same-site`/`none` — or when
     * the headers are absent, as they are from the crawlers this trap exists for. Before 5.0.1 it
     * counted anything, so an `<img src>` pointing at the trap on a busy page elsewhere put every
     * one of that page's visitors on this site's blocklist; a cross-site page could equally send
     * them here by navigation. A bot that renders pages and follows the hidden link on *this*
     * site still arrives as a same-origin document, and is still caught.
     */
    public static function fetchExemption(?string $site, ?string $dest): ?string
    {
        $site = $site !== null ? strtolower(trim($site)) : null;
        $dest = $dest !== null ? strtolower(trim($dest)) : null;

        if ($site === null && $dest === null) {
            return null;
        }

        if ($site === 'cross-site') {
            return Craft::t('blackhole', 'the request came from another site');
        }

        if ($dest !== null && $dest !== 'document') {
            return Craft::t('blackhole', 'the request was for an embedded resource, not a page');
        }

        return null;
    }

    /**
     * Springs the trap on a visitor, unless they are exempt.
     *
     * Returns the row they landed in, or null if they were let through — in which case why they
     * were let through is in `$this->lastExemption`, which is what the log line says.
     */
    public function spring(Visitor $visitor): ?BotRecord
    {
        $plugin = Plugin::getInstance();

        if (!$visitor->hasIp()) {
            $this->lastExemption = Craft::t('blackhole', 'no usable IP address');
            return null;
        }

        $request = Craft::$app->getRequest();

        if ($request instanceof \craft\web\Request) {
            $fetchExemption = self::fetchExemption(
                $request->getHeaders()->get('Sec-Fetch-Site'),
                $request->getHeaders()->get('Sec-Fetch-Dest'),
            );

            if ($fetchExemption !== null) {
                $this->lastExemption = $fetchExemption;
                return null;
            }

            if (Ip::isOnlyClaimed($request)) {
                $this->lastExemption = Craft::t('blackhole', 'the address only comes from a forwarding header nobody has said to trust — set trustedHosts to your proxy');
                return null;
            }
        }

        $reason = $plugin->whitelist->reason($visitor);

        if ($reason !== null) {
            $this->lastExemption = $reason;

            Craft::info(
                sprintf('Let %s through the trap: %s', $visitor->ip, $reason),
                Plugin::LOG_CATEGORY
            );

            return null;
        }

        $this->lastExemption = null;

        $record = $plugin->bots->catchVisitor($visitor);

        Craft::warning(
            sprintf(
                'Caught %s (%s) at %s — %s',
                $record->ip,
                $record->userAgent ?: 'no user agent',
                $record->requestUri,
                $record->status
            ),
            Plugin::LOG_CATEGORY
        );

        if ($record->status === Settings::STATUS_BLOCKED) {
            try {
                $plugin->notifier->announce($record);
            } catch (Throwable $e) {
                // A mail server having a bad day is not a reason to fail the block.
                Craft::error('Could not send the Black Hole alert: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return $record;
    }
}
