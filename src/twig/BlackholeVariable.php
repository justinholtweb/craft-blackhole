<?php

namespace justinholtweb\blackhole\twig;

use Craft;
use craft\helpers\Template;
use justinholtweb\blackhole\Plugin;
use Twig\Markup;
use yii\base\BaseObject;

/**
 * `craft.blackhole` — everything a template needs from the trap.
 */
class BlackholeVariable extends BaseObject
{
    /**
     * The hidden link.
     *
     * Only needed when auto-injection is off, or when the link should sit somewhere specific —
     * high in the markup, say, where a crawler that gives up halfway down a long page still
     * finds it.
     *
     * @param array<string, mixed> $options
     */
    public function link(array $options = []): Markup
    {
        return Plugin::getInstance()->trap->linkHtml($options);
    }

    /** The trap's URL. */
    public function trapUrl(?int $siteId = null): string
    {
        return Plugin::getInstance()->trap->url($siteId);
    }

    /**
     * The robots.txt directives, for sites that render robots.txt from a template.
     */
    public function robots(?int $siteId = null): Markup
    {
        return Template::raw(Plugin::getInstance()->robots->directives($siteId));
    }

    /**
     * Whether an address is banned. Defaults to whoever is asking.
     */
    public function isBlocked(?string $ip = null): bool
    {
        $ip ??= (string)Craft::$app->getRequest()->getUserIP();

        return $ip !== '' && Plugin::getInstance()->bots->isBlocked($ip);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return Plugin::getInstance()->bots->counts();
    }

    public function enabled(): bool
    {
        return Plugin::getInstance()->getSettings()->enabled;
    }
}
