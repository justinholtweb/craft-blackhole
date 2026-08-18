<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
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
