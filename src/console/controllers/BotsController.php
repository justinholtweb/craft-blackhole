<?php

namespace justinholtweb\blackhole\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\Plugin;
use yii\console\ExitCode;

/**
 * The ledger, from a terminal.
 *
 * Deployment scripts and cron jobs are where "unblock the office" and "empty this before the load
 * test" actually happen, so everything the control panel can do is here too.
 */
class BotsController extends Controller
{
    /** @var string Which rows to list: all, blocked, warned or released. */
    public string $status = 'all';

    /** @var string Filter the list by address, hostname, user agent or URI. */
    public string $search = '';

    /** @var int How many rows to list. */
    public int $limit = 50;

    /** @var string A note to attach when blocking by hand. */
    public string $note = '';

    public function options($actionID): array
    {
        return match ($actionID) {
            'list' => array_merge(parent::options($actionID), ['status', 'search', 'limit']),
            'block' => array_merge(parent::options($actionID), ['note']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists caught addresses.
     */
    public function actionList(): int
    {
        $plugin = Plugin::getInstance();

        $rows = $plugin->bots->query($this->status, $this->search)
            ->orderBy(['dateLastSeen' => SORT_DESC])
            ->limit(max(1, $this->limit))
            ->all();

        if (!$rows) {
            $this->stdout("Nothing in the hole.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $colour = match ($row['status']) {
                Settings::STATUS_BLOCKED => Console::FG_RED,
                Settings::STATUS_WARNED => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout(str_pad($row['status'], 9), $colour);
            $this->stdout(str_pad($row['ip'], 42));
            $this->stdout(str_pad((string)$row['hits'] . '/' . (string)$row['blockedHits'], 10));
            $this->stdout(mb_substr((string)($row['hostname'] ?: $row['userAgent'] ?: '—'), 0, 60));
            $this->stdout("\n");
        }

        $counts = $plugin->bots->counts();

        $this->stdout(sprintf(
            "\n%d blocked · %d warned · %d released · %d total\n",
            $counts[Settings::STATUS_BLOCKED],
            $counts[Settings::STATUS_WARNED],
            $counts[Settings::STATUS_RELEASED],
            $counts['all']
        ), Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Blocks an address by hand.
     */
    public function actionBlock(string $ip): int
    {
        if (!Ip::isValid($ip)) {
            $this->stderr("“{$ip}” is not an IP address.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $record = Plugin::getInstance()->bots->block($ip, $this->note ?: null);

        if ($record === null) {
            $this->stderr("Could not block $ip.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Blocked {$record->ip}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lets an address back in, keeping its history.
     */
    public function actionRelease(string $ip): int
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->bots->findByIp($ip);

        if ($record === null) {
            $this->stderr("$ip is not in the ledger.\n", Console::FG_YELLOW);

            return ExitCode::DATAERR;
        }

        $plugin->bots->release($record);
        $this->stdout("Released {$record->ip}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Removes an address from the ledger entirely.
     */
    public function actionDelete(string $ip): int
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->bots->findByIp($ip);

        if ($record === null) {
            $this->stderr("$ip is not in the ledger.\n", Console::FG_YELLOW);

            return ExitCode::DATAERR;
        }

        $plugin->bots->delete($record);
        $this->stdout("Deleted $ip.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Empties the ledger.
     */
    public function actionPurge(): int
    {
        if ($this->interactive && !$this->confirm('Delete every caught bot and its history?')) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->bots->purge();
        $this->stdout("Emptied the hole — $count released.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Applies the retention window: drops old history and long-gone released rows.
     */
    public function actionPrune(): int
    {
        $count = Plugin::getInstance()->bots->prune();
        $this->stdout("Pruned $count rows.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
