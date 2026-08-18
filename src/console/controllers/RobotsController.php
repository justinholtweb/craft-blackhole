<?php

namespace justinholtweb\blackhole\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\blackhole\Plugin;
use yii\console\ExitCode;

/**
 * The robots.txt half of the setup.
 */
class RobotsController extends Controller
{
    /**
     * Prints the directives the trap needs in robots.txt.
     */
    public function actionShow(): int
    {
        $robots = Plugin::getInstance()->robots;

        $this->stdout($robots->directives());

        if ($robots->fileExists()) {
            $covered = $robots->fileCoversTrap();

            $this->stdout(
                "\n" . ($covered
                    ? $robots->filePath() . " already has them.\n"
                    : $robots->filePath() . " does NOT have them yet — run `craft blackhole/robots/write`.\n"),
                $covered ? Console::FG_GREEN : Console::FG_YELLOW
            );
        } else {
            $this->stdout("\nThere is no robots.txt at " . $robots->filePath() . ".\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Adds the directives to the site's robots.txt, creating it if there isn't one.
     */
    public function actionWrite(): int
    {
        $result = Plugin::getInstance()->robots->write();

        $this->stdout($result['message'] . "\n", $result['written'] ? Console::FG_GREEN : Console::FG_YELLOW);

        return ExitCode::OK;
    }
}
