<?php

namespace justinholtweb\blackhole\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The bottom of the hole.
 *
 * Anything that gets here followed a link that both robots.txt and `rel="nofollow"` told it not
 * to. Requests arrive by every method and with every kind of junk attached, so this controller
 * accepts all of them and validates none of it.
 */
class TrapController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function beforeAction($action): bool
    {
        // A bot that POSTs to the trap should be caught, not handed a 400 about a token it was
        // never going to have. Nothing here writes anything the visitor chose.
        $this->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            throw new NotFoundHttpException();
        }

        $visitor = Visitor::fromRequest($this->request);

        $eligible = $plugin->trap->requestIsEligible();
        $record = $eligible ? $plugin->trap->spring($visitor) : null;

        if ($record === null) {
            // Nobody was caught. Saying so out loud is deliberate: this is the page an author
            // sees when they check the trap works, and "why didn't it catch me" is otherwise a
            // genuinely hard question to answer from the outside.
            return $this->page(
                'blackhole/_site/exempt',
                $settings->trappedTemplate,
                200,
                [
                    'caught' => false,
                    'bot' => null,
                    'ip' => $visitor->ip,
                    'message' => $settings->trappedMessage,
                    'reason' => $eligible
                        ? $plugin->trap->lastExemption
                        : Craft::t('blackhole', 'the trap is not armed for this request'),
                ]
            );
        }

        return $this->page(
            'blackhole/_site/trapped',
            $settings->trappedTemplate,
            $record->status === Settings::STATUS_BLOCKED ? $settings->blockedStatusCode : 200,
            [
                'caught' => true,
                'bot' => $record,
                'ip' => $visitor->ip,
                'message' => $settings->trappedMessage,
                'blocked' => $record->status === Settings::STATUS_BLOCKED,
                'hits' => (int)$record->hits,
                'threshold' => max(1, $settings->blockThreshold),
                'reason' => null,
            ]
        );
    }

    /**
     * Renders a trap page: the author's template if they named one, the plugin's otherwise.
     *
     * @param array<string, mixed> $variables
     */
    private function page(string $fallback, string $override, int $statusCode, array $variables): Response
    {
        $html = null;
        $override = trim($override);

        if ($override !== '') {
            try {
                $html = $this->view->renderTemplate($override, $variables, View::TEMPLATE_MODE_SITE);
            } catch (Throwable $e) {
                Craft::error('Could not render the Black Hole trap template: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        $html ??= $this->view->renderTemplate($fallback, $variables, View::TEMPLATE_MODE_CP);

        $this->response->format = Response::FORMAT_HTML;
        $this->response->setStatusCode($statusCode);
        $this->response->content = $html;

        $headers = $this->response->getHeaders();
        $headers->set('Content-Type', 'text/html; charset=UTF-8');

        // The trap must never be cached, by anything. A cached trap page is a trap that fires
        // once and then quietly stops existing.
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $this->response;
    }
}
