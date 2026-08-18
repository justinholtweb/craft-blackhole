<?php

namespace justinholtweb\blackhole\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The caught-bots list and everything you can do to a row in it.
 */
class BotsController extends Controller
{
    /** How many rows a page of the index holds. */
    private const PER_PAGE = 50;

    /**
     * Columns the index may be sorted by.
     *
     * An allowlist rather than a sanitiser: the value arrives in a query string and ends up in an
     * `ORDER BY`, and there is no version of that which is safe by inspection.
     */
    private const SORTABLE = ['ip', 'hostname', 'status', 'hits', 'blockedHits', 'dateCaught', 'dateLastSeen'];

    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        $status = (string)$this->request->getQueryParam('status', 'all');
        $search = (string)$this->request->getQueryParam('search', '');
        $sort = (string)$this->request->getQueryParam('sort', 'dateLastSeen');
        $direction = strtolower((string)$this->request->getQueryParam('dir', 'desc')) === 'asc' ? SORT_ASC : SORT_DESC;
        $page = max(1, (int)$this->request->getQueryParam('page', 1));

        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'dateLastSeen';
        }

        $query = $plugin->bots->query($status, $search);
        $total = (int)$query->count();
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $rows = $query
            ->orderBy([$sort => $direction, 'id' => SORT_DESC])
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->all();

        return $this->renderTemplate('blackhole/bots/index', [
            'bots' => $rows,
            'counts' => $plugin->bots->counts(),
            'status' => $status,
            'search' => $search,
            'sort' => $sort,
            'dir' => $direction === SORT_ASC ? 'asc' : 'desc',
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'settings' => $plugin->getSettings(),
            'trapUrl' => $plugin->trap->url(),
            'robotsCoversTrap' => $plugin->robots->fileCoversTrap(),
            'robotsFileExists' => $plugin->robots->fileExists(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionDetail(int $botId): Response
    {
        $plugin = Plugin::getInstance();
        $bot = $plugin->bots->findById($botId);

        if ($bot === null) {
            throw new NotFoundHttpException(Craft::t('blackhole', 'That bot is not in the ledger.'));
        }

        return $this->renderTemplate('blackhole/bots/detail', [
            'bot' => $bot,
            'hits' => $plugin->bots->hits($botId),
            'isWhitelisted' => $plugin->whitelist->ipIsWhitelisted($bot->ip),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionBlock(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $ip = trim((string)$this->request->getBodyParam('ip'));

        if (!Ip::isValid($ip)) {
            $this->setFailFlash(Craft::t('blackhole', '“{ip}” is not an IP address.', ['ip' => $ip]));

            return $this->redirectToPostedUrl();
        }

        $record = Plugin::getInstance()->bots->block($ip, (string)$this->request->getBodyParam('note') ?: null);

        if ($record === null) {
            $this->setFailFlash(Craft::t('blackhole', 'Could not block {ip}.', ['ip' => $ip]));
        } else {
            $this->setSuccessFlash(Craft::t('blackhole', '{ip} is blocked.', ['ip' => $record->ip]));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionRelease(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $bot = $plugin->bots->findById((int)$this->request->getBodyParam('botId'));

        if ($bot === null) {
            throw new NotFoundHttpException();
        }

        $plugin->bots->release($bot);

        $this->setSuccessFlash(Craft::t('blackhole', '{ip} can come back.', ['ip' => $bot->ip]));

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $bot = $plugin->bots->findById((int)$this->request->getBodyParam('botId'));

        if ($bot === null) {
            throw new NotFoundHttpException();
        }

        $ip = $bot->ip;
        $plugin->bots->delete($bot);

        $this->setSuccessFlash(Craft::t('blackhole', 'Deleted {ip}.', ['ip' => $ip]));

        return $this->redirect('blackhole/bots');
    }

    public function actionPurge(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $count = Plugin::getInstance()->bots->purge();

        $this->setSuccessFlash(Craft::t('blackhole', 'Emptied the hole — {count} released.', ['count' => $count]));

        return $this->redirect('blackhole/bots');
    }

    /**
     * Adds an address to the whitelist and lets it back in.
     *
     * The whitelist is a plugin setting, so this writes to project config — which a site running
     * with `allowAdminChanges` off will refuse, correctly. Saying so is more useful than a stack
     * trace about a read-only config.
     */
    public function actionWhitelist(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $bot = $plugin->bots->findById((int)$this->request->getBodyParam('botId'));

        if ($bot === null) {
            throw new NotFoundHttpException();
        }

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $list = $settings->whitelistIps;

        if (!in_array($bot->ip, $list, true)) {
            $list[] = $bot->ip;
        }

        try {
            $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, ['whitelistIps' => $list]);
        } catch (Throwable $e) {
            Craft::error('Could not whitelist an address: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $saved = false;
        }

        if (!$saved) {
            $this->setFailFlash(Craft::t('blackhole', 'Could not save the whitelist. Is admin changes allowed on this environment?'));

            return $this->redirectToPostedUrl();
        }

        $plugin->bots->release($bot);

        $this->setSuccessFlash(Craft::t('blackhole', '{ip} is whitelisted and will not be caught again.', ['ip' => $bot->ip]));

        return $this->redirectToPostedUrl();
    }
}
