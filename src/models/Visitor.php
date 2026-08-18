<?php

namespace justinholtweb\blackhole\models;

use Craft;
use craft\base\Model;
use craft\web\Request;
use justinholtweb\blackhole\helpers\Ip;

/**
 * Everything the plugin knows about whoever is on the other end of a request.
 *
 * A value object rather than a pile of `$request->getX()` calls scattered through three services,
 * because the checks want to be testable without a web request — and because the console commands
 * and the integration checks need to be able to hand-build one.
 */
class Visitor extends Model
{
    public string $ip = '';
    public string $userAgent = '';
    public string $requestUri = '';
    public string $referrer = '';
    public string $method = 'GET';
    public ?int $siteId = null;

    /** @var string|null Resolved lazily and only when something asks; a DNS call is not free. */
    private ?string $hostname = null;
    private bool $hostnameResolved = false;

    public static function fromRequest(?Request $request = null): self
    {
        $request ??= Craft::$app->getRequest();

        $visitor = new self();
        $visitor->ip = Ip::normalize((string)$request->getUserIP());
        $visitor->userAgent = (string)$request->getUserAgent();
        $visitor->referrer = (string)$request->getReferrer();
        $visitor->method = (string)$request->getMethod();

        // The full requested URI, query string and all, because "what were they asking for" is
        // half of what makes a caught bot readable later.
        $visitor->requestUri = (string)$request->getUrl();

        try {
            $visitor->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            // No current site yet — this can run before Craft has resolved one, and which site
            // a bot was on is a nicety, not a requirement.
            $visitor->siteId = null;
        }

        return $visitor;
    }

    /**
     * The reverse DNS name, or null if there isn't one.
     *
     * `gethostbyaddr()` blocks for as long as the resolver takes, which is why nothing on the
     * hot path calls this — only a trap trip does, and the answer is remembered.
     */
    public function getHostname(): ?string
    {
        if ($this->hostnameResolved) {
            return $this->hostname;
        }

        $this->hostnameResolved = true;

        if ($this->ip === '' || !Ip::isValid($this->ip)) {
            return $this->hostname = null;
        }

        $name = @gethostbyaddr($this->ip);

        // gethostbyaddr() hands back the address itself when there is no PTR record.
        $this->hostname = ($name === false || $name === '' || $name === $this->ip) ? null : $name;

        return $this->hostname;
    }

    public function setHostname(?string $hostname): void
    {
        $this->hostname = $hostname;
        $this->hostnameResolved = true;
    }

    /** Whether a hostname lookup has already happened, so callers can avoid forcing one. */
    public function hostnameIsResolved(): bool
    {
        return $this->hostnameResolved;
    }

    public function hasIp(): bool
    {
        return $this->ip !== '' && Ip::isValid($this->ip);
    }
}
