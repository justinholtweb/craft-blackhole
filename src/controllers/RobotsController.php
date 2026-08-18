<?php

namespace justinholtweb\blackhole\controllers;

use craft\web\Controller;
use justinholtweb\blackhole\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves `/robots.txt` on sites that do not have one.
 *
 * Only ever routed when the setting is on and there is genuinely no `web/robots.txt`, because a
 * plugin route that shadows a file somebody wrote is a plugin nobody can debug.
 */
class RobotsController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function actionIndex(): Response
    {
        $robots = Plugin::getInstance()->robots;

        if (!$robots->shouldServe()) {
            throw new NotFoundHttpException();
        }

        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = $robots->directives();
        $this->response->getHeaders()->set('Content-Type', 'text/plain; charset=UTF-8');

        return $this->response;
    }
}
