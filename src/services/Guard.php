<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\web\Application;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The door.
 *
 * Runs at `Application::EVENT_BEFORE_REQUEST` — before routing, before the session, before Craft
 * has done any real work — and answers one question: is this address banned?
 *
 * The order of the checks in `intercept()` is the whole design. The ban lookup is first because
 * it is nearly free and the answer is no for every visitor the site actually wants. The
 * whitelist and the logged-in test come after, because asking whether someone is logged in
 * *starts a session*, and a session means a `Set-Cookie` on every response, and a `Set-Cookie` on
 * every response is a full-page cache that never hits again. A plugin has no business doing that
 * to a site on the off chance the reader is a robot.
 */
class Guard extends Component
{
    private bool $triggered = false;
    private bool $warnedAboutRedirect = false;

    /** Whether this request was turned away, so nothing else bothers decorating it. */
    public function hasTriggered(): bool
    {
        return $this->triggered;
    }

    public function intercept(): void
    {
        try {
            $this->check();
        } catch (Throwable $e) {
            // A broken guard must fail open. Turning the site off for everyone because a query
            // threw is a far worse outcome than letting one bot through.
            Craft::error('Black Hole could not check the request: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    private function check(): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof Request || $request->getIsCpRequest()) {
            return;
        }

        $ip = Ip::normalize((string)$request->getUserIP());

        if ($ip === '') {
            return;
        }

        // Cheap, and false for every real visitor. Everything expensive is on the other side.
        if (!$plugin->bots->isBlocked($ip)) {
            return;
        }

        if ($plugin->whitelist->ipIsWhitelisted($ip)) {
            return;
        }

        if ($settings->ignoreLoggedIn && !Craft::$app->getUser()->getIsGuest()) {
            return;
        }

        $visitor = Visitor::fromRequest($request);

        $plugin->bots->noteBlockedAttempt($ip, $visitor);

        $this->triggered = true;
        $this->deny($visitor);
    }

    /**
     * Turns a request away and ends it.
     */
    public function deny(Visitor $visitor): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $response = Craft::$app->getResponse();

        $redirect = trim($settings->redirectUrl);

        if ($redirect !== '' && $this->redirectIsSafe($redirect)) {
            $response->redirect($redirect, 302);
        } else {
            $response->setStatusCode($settings->blockedStatusCode);
            $response->format = Response::FORMAT_HTML;
            $response->content = $this->renderBlockedPage($visitor);
            $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        }

        $headers = $response->getHeaders();

        // Never let a block page be cached — by a proxy, by a CDN, or by the bot. The ban can be
        // lifted a second from now, and a cached 403 outlives the reason for it.
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        $response->send();

        Craft::$app->end();
    }

    /**
     * Whether a configured redirect points somewhere that will not simply bounce back here.
     *
     * Sending a blocked address to a page on the same site produces a redirect loop, because that
     * request is blocked too. Rather than let the site fail in a way nobody would attribute to
     * this plugin, an on-site redirect is ignored and the block page is shown instead.
     */
    public function redirectIsSafe(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            $this->warnAboutRedirect($url);
            return false;
        }

        $request = Craft::$app->getRequest();
        $currentHost = $request instanceof Request ? $request->getHostName() : null;

        if ($currentHost !== null && strcasecmp($host, $currentHost) === 0) {
            $this->warnAboutRedirect($url);
            return false;
        }

        return true;
    }

    private function warnAboutRedirect(string $url): void
    {
        if ($this->warnedAboutRedirect) {
            return;
        }

        $this->warnedAboutRedirect = true;

        Craft::warning(
            sprintf('Ignoring the Black Hole redirect to "%s": it is on this site, which would loop.', $url),
            Plugin::LOG_CATEGORY
        );
    }

    /**
     * The block page.
     *
     * A site template if the author named one, the plugin's own otherwise, and a bare string if
     * both of those manage to throw — because whatever happens, this request has to end with
     * something sent.
     */
    public function renderBlockedPage(Visitor $visitor): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $variables = [
            'ip' => $visitor->ip,
            'message' => $settings->blockedMessage,
            'userAgent' => $visitor->userAgent,
            'requestUri' => $visitor->requestUri,
            'statusCode' => $settings->blockedStatusCode,
        ];

        $view = Craft::$app->getView();

        if (trim($settings->blockedTemplate) !== '') {
            try {
                return $view->renderTemplate(trim($settings->blockedTemplate), $variables, View::TEMPLATE_MODE_SITE);
            } catch (Throwable $e) {
                Craft::error('Could not render the Black Hole block template: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        try {
            return $view->renderTemplate('blackhole/_site/blocked', $variables, View::TEMPLATE_MODE_CP);
        } catch (Throwable $e) {
            Craft::error('Could not render the Black Hole block page: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return '<!doctype html><meta charset="utf-8"><title>Access denied</title><p>'
                . htmlspecialchars($settings->blockedMessage, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
    }

    /**
     * Registers the interceptor. Web requests only — there is nobody to block on the console.
     */
    public function listen(): void
    {
        if (!Craft::$app instanceof Application) {
            return;
        }

        \yii\base\Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, function() {
            $this->intercept();
        });
    }
}
