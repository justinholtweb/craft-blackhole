<?php

namespace justinholtweb\blackhole\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * Black Hole settings.
 *
 * The defaults are the whole plugin working out of the box: the trap lives at `/blackhole/`, the
 * hidden link is injected automatically, one trip is enough to earn a ban, logged-in users are
 * never caught, and the search engines everybody wants are whitelisted — and verified.
 */
class Settings extends Model
{
    /** Every status a caught address can be in. */
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_WARNED = 'warned';
    public const STATUS_RELEASED = 'released';

    /**
     * @var bool The master switch. Off means nothing is trapped and nothing is blocked; the
     *           ledger is left alone so turning it back on picks up where it left off.
     */
    public bool $enabled = true;

    /**
     * @var string The path the hidden link points at, relative to the site root.
     */
    public string $trapPath = 'blackhole';

    /**
     * @var bool Whether the hidden link is spliced into front-end HTML automatically.
     */
    public bool $autoInject = true;

    /**
     * @var string The title attribute on the hidden link. It is a warning, and it is the reason a
     *             ban is defensible: nobody who reads the page can say they were not told.
     */
    public string $linkTitle = 'Do NOT follow this link or you will be banned from this site!';

    /**
     * @var int How many trap trips it takes to earn a ban. 1 is the honeypot's whole premise —
     *          there is no innocent reason to be here — but a site with an unusual crawl profile
     *          may want a little more rope.
     */
    public int $blockThreshold = 1;

    /**
     * @var int How many days a ban lasts. 0 means forever.
     */
    public int $banDays = 0;

    /**
     * @var bool Whether a logged-in user is exempt. On by default, and worth leaving on: a
     *           prefetching browser extension on a staff machine should not lock out the office.
     */
    public bool $ignoreLoggedIn = true;

    /**
     * @var int The status sent to a blocked request.
     */
    public int $blockedStatusCode = 403;

    /**
     * @var string What a blocked request is told. Plain text; it is shown to software.
     */
    public string $blockedMessage = 'Access denied. Your IP address is blocked from this site because it ignored our robots.txt rules.';

    /**
     * @var string Optional site template rendered instead of the built-in block page.
     */
    public string $blockedTemplate = '';

    /**
     * @var string Optional URL blocked requests are redirected to instead of being shown a page.
     */
    public string $redirectUrl = '';

    /**
     * @var string What a bot is told at the moment it is caught.
     */
    public string $trappedMessage = 'You followed a link that robots.txt told you not to follow, so your IP address has been blocked from this site.';

    /**
     * @var string Optional site template rendered instead of the built-in trap page.
     */
    public string $trappedTemplate = '';

    /**
     * @var string[] User-agent fragments that are never caught. Matched case-insensitively as
     *               substrings, the way every user-agent allowlist in the world works.
     */
    public array $whitelistUserAgents = [
        'a6-indexer', 'adsbot-google', 'ahrefsbot', 'aolbuild', 'apis-google', 'applebot',
        'baidu', 'bingbot', 'bingpreview', 'chrome-lighthouse', 'cloudflare', 'duckduckbot',
        'duckduckgo', 'embedly', 'facebookexternalhit', 'facebot', 'google page speed',
        'googlebot', 'ia_archiver', 'linkedinbot', 'mediapartners-google', 'msnbot',
        'netcraftsurvey', 'outbrain', 'petalbot', 'pingdom', 'pinterest', 'quora', 'rogerbot',
        'showyoubot', 'slackbot', 'slurp', 'sogou', 'teoma', 'tweetmemebot', 'twitterbot',
        'uptimerobot', 'urlresolver', 'vkshare', 'w3c_validator', 'wordpress', 'wp rocket',
        'yandex',
    ];

    /**
     * @var string[] Addresses that are never caught and never blocked. Exact addresses, dotted
     *               prefixes (`203.0.113.`) and CIDR ranges (`203.0.113.0/24`, `2001:db8::/32`).
     */
    public array $whitelistIps = [];

    /**
     * @var bool Whether a whitelisted user agent belonging to a crawler that publishes reverse
     *           DNS must prove it. "Googlebot" is the easiest string in the world to type, and
     *           without this the allowlist is a door with a sign on it instead of a lock.
     */
    public bool $verifyGoodBots = true;

    /**
     * @var bool Whether caught addresses get a reverse DNS lookup, for the record and the alert.
     *           Costs a blocking DNS call, but only ever on a catch.
     */
    public bool $resolveHostnames = true;

    /**
     * @var bool Whether an email goes out when something is caught.
     */
    public bool $emailAlerts = false;

    /**
     * @var string[] Who the alert goes to. Empty means every admin account.
     */
    public array $alertRecipients = [];

    /**
     * @var bool Whether the plugin answers `/robots.txt` itself. Only ever registered when the
     *           site has no `web/robots.txt` of its own — a plugin that shadows a file the author
     *           wrote is a plugin that eats an afternoon.
     */
    public bool $serveRobotsTxt = false;

    /**
     * @var int How many days of hit history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 90;

    protected function defineRules(): array
    {
        return [
            [['enabled', 'autoInject', 'ignoreLoggedIn', 'verifyGoodBots', 'resolveHostnames', 'emailAlerts', 'serveRobotsTxt'], 'boolean'],
            [['trapPath', 'linkTitle', 'blockedMessage', 'blockedTemplate', 'redirectUrl', 'trappedMessage', 'trappedTemplate'], 'string'],
            [['blockThreshold'], 'integer', 'min' => 1],
            [['banDays', 'logRetentionDays'], 'integer', 'min' => 0],
            [['blockedStatusCode'], 'in', 'range' => [401, 403, 404, 410, 418, 429, 451, 503]],
            [['whitelistUserAgents', 'whitelistIps', 'alertRecipients'], 'validateList', 'skipOnEmpty' => false],
            [['trapPath'], 'validateTrapPath'],
        ];
    }

    /**
     * Craft's editable tables post rows, not strings.
     *
     * A `{value: 'googlebot'}` array is what comes back from the CP, a flat list is what comes
     * back from a config file, and readers should not have to know which. Normalising here rather
     * than in a setter keeps the property a plain list for everything downstream.
     *
     * `skipOnEmpty` is false on purpose: Yii skips inline validators on empty attributes, and an
     * empty array is empty — so clearing a table would otherwise leave the posted rows in place.
     */
    public function validateList(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $clean = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $row = reset($row);
            }

            if (!is_string($row) && !is_numeric($row)) {
                continue;
            }

            $row = trim((string)$row);

            if ($row !== '' && !in_array($row, $clean, true)) {
                $clean[] = $row;
            }
        }

        $this->$attribute = $clean;
    }

    /**
     * The trap path has to be a path — one that Craft can route and robots.txt can name.
     */
    public function validateTrapPath(string $attribute): void
    {
        $path = $this->normalizedTrapPath();

        if ($path === '') {
            $this->addError($attribute, Craft::t('blackhole', 'The trap path cannot be empty.'));
            return;
        }

        if (!preg_match('~^[A-Za-z0-9/_.-]+$~', $path)) {
            $this->addError($attribute, Craft::t('blackhole', 'The trap path may only contain letters, numbers, slashes, dots, hyphens and underscores.'));
            return;
        }

        if (str_starts_with(strtolower($path), strtolower(Craft::$app->getConfig()->getGeneral()->cpTrigger ?? 'admin') . '/')
            || strcasecmp($path, (string)Craft::$app->getConfig()->getGeneral()->cpTrigger) === 0) {
            $this->addError($attribute, Craft::t('blackhole', 'The trap path cannot sit inside the control panel.'));
        }
    }

    /**
     * The trap path with the slashes taken off both ends, which is the form Craft's URL rules and
     * the whole rest of the plugin want.
     */
    public function normalizedTrapPath(): string
    {
        return trim(StringHelper::removeLeft(trim($this->trapPath), '/'), '/');
    }

    public function attributeLabels(): array
    {
        return [
            'enabled' => Craft::t('blackhole', 'Enabled'),
            'trapPath' => Craft::t('blackhole', 'Trap path'),
            'autoInject' => Craft::t('blackhole', 'Add the link automatically'),
            'linkTitle' => Craft::t('blackhole', 'Link warning'),
            'blockThreshold' => Craft::t('blackhole', 'Trips before a ban'),
            'banDays' => Craft::t('blackhole', 'Ban length'),
            'ignoreLoggedIn' => Craft::t('blackhole', 'Never catch logged-in users'),
            'blockedStatusCode' => Craft::t('blackhole', 'Blocked status code'),
            'blockedMessage' => Craft::t('blackhole', 'Blocked message'),
            'blockedTemplate' => Craft::t('blackhole', 'Blocked template'),
            'redirectUrl' => Craft::t('blackhole', 'Redirect blocked requests to'),
            'trappedMessage' => Craft::t('blackhole', 'Caught message'),
            'trappedTemplate' => Craft::t('blackhole', 'Caught template'),
            'whitelistUserAgents' => Craft::t('blackhole', 'Whitelisted user agents'),
            'whitelistIps' => Craft::t('blackhole', 'Whitelisted addresses'),
            'verifyGoodBots' => Craft::t('blackhole', 'Verify good bots'),
            'resolveHostnames' => Craft::t('blackhole', 'Resolve hostnames'),
            'emailAlerts' => Craft::t('blackhole', 'Email alerts'),
            'alertRecipients' => Craft::t('blackhole', 'Alert recipients'),
            'serveRobotsTxt' => Craft::t('blackhole', 'Serve robots.txt'),
            'logRetentionDays' => Craft::t('blackhole', 'Keep hit history for'),
        ];
    }
}
