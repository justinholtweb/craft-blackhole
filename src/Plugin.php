<?php

namespace justinholtweb\blackhole;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\services\Bots;
use justinholtweb\blackhole\services\Guard;
use justinholtweb\blackhole\services\Notifier;
use justinholtweb\blackhole\services\Robots;
use justinholtweb\blackhole\services\Trap;
use justinholtweb\blackhole\services\Whitelist;
use justinholtweb\blackhole\twig\BlackholeVariable;
use Throwable;
use yii\base\Event;

/**
 * Black Hole — a honeypot for bad bots.
 *
 * The mechanism is one sentence long. robots.txt tells every crawler to stay out of one path, an
 * invisible link on every page points at that path, and anything that follows the link has proved
 * it does not read robots.txt. Well-behaved crawlers never see the trap; humans never see the
 * link; everything that arrives got there by ignoring an explicit instruction.
 *
 * Blocking is always by address and never by user agent, because a user agent is a claim and a
 * request is a fact.
 *
 * @property-read Bots $bots
 * @property-read Trap $trap
 * @property-read Guard $guard
 * @property-read Whitelist $whitelist
 * @property-read Robots $robots
 * @property-read Notifier $notifier
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'blackhole:viewBots';
    public const PERMISSION_MANAGE = 'blackhole:manageBots';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'blackhole';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'bots' => Bots::class,
                'trap' => Trap::class,
                'guard' => Guard::class,
                'whitelist' => Whitelist::class,
                'robots' => Robots::class,
                'notifier' => Notifier::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // First, and before anything else this plugin does: a banned address should not get any
        // further into the request than it has to.
        $this->guard->listen();

        $this->registerSiteRoutes();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerInjection();
        $this->registerGarbageCollection();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('blackhole', 'Black Hole');

        $item['subnav'] = [
            'bots' => [
                'label' => Craft::t('blackhole', 'Caught bots'),
                'url' => 'blackhole/bots',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('blackhole', 'Settings'),
                'url' => 'settings/plugins/blackhole',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $robots = $this->robots;

        return Craft::$app->getView()->renderTemplate('blackhole/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'trapUrl' => $this->trap->url(),
            'directives' => $robots->directives(),
            'robotsFileExists' => $robots->fileExists(),
            'robotsFilePath' => $robots->filePath(),
            'robotsCoversTrap' => $robots->fileCoversTrap(),
            'statusCodes' => $this->blockedStatusCodeOptions(),
        ]);
    }

    /** @return array<int, array<string, string>> */
    public function blockedStatusCodeOptions(): array
    {
        return [
            ['value' => '403', 'label' => Craft::t('blackhole', '403 — Forbidden (recommended)')],
            ['value' => '401', 'label' => Craft::t('blackhole', '401 — Unauthorized')],
            ['value' => '404', 'label' => Craft::t('blackhole', '404 — Not Found')],
            ['value' => '410', 'label' => Craft::t('blackhole', '410 — Gone')],
            ['value' => '418', 'label' => Craft::t('blackhole', '418 — I’m a teapot')],
            ['value' => '429', 'label' => Craft::t('blackhole', '429 — Too Many Requests')],
            ['value' => '451', 'label' => Craft::t('blackhole', '451 — Unavailable For Legal Reasons')],
            ['value' => '503', 'label' => Craft::t('blackhole', '503 — Service Unavailable')],
        ];
    }

    // ------------------------------------------------------------------ routing

    /**
     * The trap's own URL, plus everything under it.
     *
     * Both rules matter. A crawler that follows the link lands on the first; a crawler that
     * mangles the URL, appends a query, or tries `/blackhole/wp-admin` on a hunch lands on the
     * second — and both of those are exactly as disobedient as each other.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->enabled) {
                return;
            }

            $path = $settings->normalizedTrapPath();

            if ($path === '') {
                return;
            }

            $event->rules[$path] = 'blackhole/trap/index';
            $event->rules[$path . '/<rest:.*>'] = 'blackhole/trap/index';

            if ($this->robots->shouldServe()) {
                $event->rules['robots.txt'] = 'blackhole/robots/index';
            }
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'blackhole' => 'blackhole/bots/index',
                'blackhole/bots' => 'blackhole/bots/index',
                'blackhole/bots/<botId:\d+>' => 'blackhole/bots/detail',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('blackhole', 'Black Hole'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('blackhole', 'View caught bots'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('blackhole', 'Block, release and delete bots'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('blackhole', BlackholeVariable::class);
        });
    }

    // ------------------------------------------------------------------ the hidden link

    /**
     * Splices the hidden link into front-end HTML.
     *
     * Rewriting the prepared body rather than hooking `View::EVENT_END_BODY`, for the same reason
     * `[[project_craft_blaster]]` does: that hook only fires for templates that call
     * `{{ endBody() }}`, and plenty of real sites do not. A honeypot whose link silently never
     * appears is a honeypot that catches nothing.
     */
    private function registerInjection(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            try {
                $this->inject($event->sender);
            } catch (Throwable $e) {
                Craft::error('Could not add the Black Hole link: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function inject(Response $response): void
    {
        $settings = $this->getSettings();

        if (!$settings->enabled || !$settings->autoInject) {
            return;
        }

        // Nothing gets decorated on the way out the door.
        if ($this->guard->hasTriggered()) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request || $request->getIsCpRequest()) {
            return;
        }

        if ($response->getStatusCode() >= 500) {
            return;
        }

        if (!$this->isHtmlResponse((string)$response->getHeaders()->get('content-type'))) {
            return;
        }

        $html = $response->content;

        if (!is_string($html) || $html === '' || $this->alreadyLinked($html)) {
            return;
        }

        $spliced = $this->spliceIntoBody($html, (string)$this->trap->linkHtml());

        if ($spliced === null) {
            return;
        }

        $response->content = $spliced;

        // `sendContentLengthHeader` makes Craft stamp the length during prepare — before this
        // runs. Leaving it stale truncates the page at exactly the byte the link was added at,
        // which looks like a broken template rather than a broken header.
        $headers = $response->getHeaders();

        if ($headers->get('content-length') !== null) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }

    /**
     * Whether a prepared response is a page.
     *
     * The content *type* answers this; the response format does not. Craft renders front-end
     * templates through its own `template` format, and that formatter takes the MIME type from
     * the template's file extension — so `feed.rss.twig` and `manifest.json.twig` are template
     * responses that must be left alone. Testing the format instead matches nothing at all.
     */
    public function isHtmlResponse(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'text/html');
    }

    /**
     * Whether the author already placed the link with `{{ craft.blackhole.link }}`.
     *
     * Theirs wins. A second copy would be harmless, but it is noise in somebody's markup that
     * nobody put there, which is the worst kind.
     */
    public function alreadyLinked(string $html): bool
    {
        return stripos($html, $this->trap->url()) !== false;
    }

    /**
     * Puts the markup immediately before the page's last closing body tag.
     *
     * The *last* one, because a page may perfectly legitimately contain the string earlier — in a
     * code sample, in an escaped snippet, in a `<textarea>` holding markup someone is editing.
     */
    public function spliceIntoBody(string $html, string $markup): ?string
    {
        $position = strripos($html, '</body>');

        return $position === false ? null : substr_replace($html, $markup, $position, 0);
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->bots->prune();
        });
    }
}
