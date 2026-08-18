<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\blackhole\helpers\Ip;
use justinholtweb\blackhole\migrations\Install;
use justinholtweb\blackhole\models\Settings;
use justinholtweb\blackhole\models\Visitor;
use justinholtweb\blackhole\Plugin;
use justinholtweb\blackhole\records\BotRecord;
use justinholtweb\blackhole\records\HitRecord;
use yii\base\Component;
use yii\db\Expression;

/**
 * The ledger of caught addresses.
 *
 * Everything that reads or writes a bot row goes through here, including the one read that
 * happens on every single front-end request — which is why that read has a cache in front of it
 * and this class is careful about what it puts in that cache.
 */
class Bots extends Component
{
    private const CACHE_KEY = 'blackhole:blocked-map';
    private const CACHE_TTL = 60;

    /**
     * How many banned addresses will be held in memory. Past this the map stops being a saving
     * and starts being a payload, and the per-address query is the better trade.
     */
    private const CACHE_LIMIT = 10000;

    /**
     * How long to wait before writing another history row for the same address.
     *
     * A bot that has been blocked usually does not stop; it keeps hammering. Counting every
     * attempt is worth one cheap `UPDATE`, but writing a row for each one turns a bad crawler
     * into a disk space problem.
     */
    private const HIT_THROTTLE = 60;

    private ?array $blockedMap = null;
    private bool $blockedMapLoaded = false;

    // ------------------------------------------------------------------ reading

    public function findByIp(string $ip): ?BotRecord
    {
        $ip = Ip::normalize($ip);

        if ($ip === '') {
            return null;
        }

        return BotRecord::findOne(['ip' => $ip]);
    }

    public function findById(int $id): ?BotRecord
    {
        return BotRecord::findOne(['id' => $id]);
    }

    /**
     * Whether an address is banned right now.
     *
     * "Right now" is load-bearing: a row can be `blocked` and still be over, because a ban with a
     * length on it stays in the ledger after it lapses so the history is not lost.
     */
    public function isBlocked(string $ip): bool
    {
        $ip = Ip::normalize($ip);

        if ($ip === '') {
            return false;
        }

        $map = $this->getBlockedMap();

        if ($map !== null) {
            if (!array_key_exists($ip, $map)) {
                return false;
            }

            return $map[$ip] === null || $map[$ip] > time();
        }

        // Too many bans to hold in memory, so ask about this one address. `false` is "no such
        // row", `null` is "a row with no expiry" — which is a permanent ban, not an absent one.
        $expires = (new Query())
            ->select(['dateExpires'])
            ->from(Install::TABLE_BOTS)
            ->where(['ip' => $ip, 'status' => Settings::STATUS_BLOCKED])
            ->limit(1)
            ->scalar();

        if ($expires === false) {
            return false;
        }

        return $expires === null || $this->timestamp((string)$expires) > time();
    }

    /**
     * Every banned address and when its ban lapses, or null when there are too many to hold.
     *
     * @return array<string, int|null>|null
     */
    public function getBlockedMap(): ?array
    {
        if ($this->blockedMapLoaded) {
            return $this->blockedMap;
        }

        $this->blockedMapLoaded = true;

        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::CACHE_KEY);

        if ($cached !== false) {
            return $this->blockedMap = ($cached === 'over-limit' ? null : $cached);
        }

        $count = (new Query())
            ->from(Install::TABLE_BOTS)
            ->where(['status' => Settings::STATUS_BLOCKED])
            ->count();

        // `count()` comes back as a string from every driver Craft supports.
        if ((int)$count > self::CACHE_LIMIT) {
            $cache->set(self::CACHE_KEY, 'over-limit', self::CACHE_TTL);
            return $this->blockedMap = null;
        }

        $map = [];

        $rows = (new Query())
            ->select(['ip', 'dateExpires'])
            ->from(Install::TABLE_BOTS)
            ->where(['status' => Settings::STATUS_BLOCKED])
            ->all();

        foreach ($rows as $row) {
            $map[$row['ip']] = $row['dateExpires'] ? $this->timestamp($row['dateExpires']) : null;
        }

        $cache->set(self::CACHE_KEY, $map, self::CACHE_TTL);

        return $this->blockedMap = $map;
    }

    /**
     * Throws away the cached ban list.
     *
     * Called after every write. The TTL is the backstop for other web nodes, which is the one
     * case a local invalidation cannot reach — a ban can be up to a minute old elsewhere.
     */
    public function invalidateCache(): void
    {
        $this->blockedMap = null;
        $this->blockedMapLoaded = false;
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    // ------------------------------------------------------------------ catching

    /**
     * Records a trap trip and returns the row it landed in.
     *
     * Status comes out of the threshold: under it the address is on notice, at it the address is
     * banned. A row that was released starts its count again from zero, so a second chance really
     * is a second chance.
     */
    public function catchVisitor(Visitor $visitor): BotRecord
    {
        $settings = Plugin::getInstance()->getSettings();
        $now = new DateTime('now', new DateTimeZone('UTC'));

        $record = $this->findByIp($visitor->ip);
        $isNew = $record === null;

        if ($isNew) {
            $record = new BotRecord();
            $record->ip = Ip::normalize($visitor->ip);
            $record->hits = 0;
            $record->blockedHits = 0;
            $record->dateCaught = Db::prepareDateForDb($now);
        }

        $record->hits = (int)$record->hits + 1;
        $record->userAgent = $this->truncate($visitor->userAgent, 1000);
        $record->requestUri = $this->truncate($visitor->requestUri, 1000);
        $record->referrer = $this->truncate($visitor->referrer, 1000);
        $record->method = substr($visitor->method, 0, 10);
        $record->siteId = $visitor->siteId;
        $record->dateLastSeen = Db::prepareDateForDb($now);

        if ($settings->resolveHostnames && $record->hostname === null) {
            $record->hostname = $this->truncate((string)$visitor->getHostname(), 255) ?: null;
        }

        if ($record->hits >= max(1, $settings->blockThreshold)) {
            $record->status = Settings::STATUS_BLOCKED;
            $record->dateExpires = $settings->banDays > 0
                ? Db::prepareDateForDb((clone $now)->modify('+' . $settings->banDays . ' days'))
                : null;
        } else {
            $record->status = Settings::STATUS_WARNED;
            $record->dateExpires = null;
        }

        $record->save(false);

        $this->logHit((int)$record->id, $visitor, HitRecord::KIND_TRAP);
        $this->invalidateCache();

        return $record;
    }

    /**
     * Notes that a banned address tried again.
     *
     * The hot path here is one arithmetic `UPDATE` and nothing else. That matters: a bot that has
     * been blocked usually does not go away, it speeds up, and this runs on every one of those
     * requests. Arithmetic in SQL rather than a read-modify-write also means two simultaneous
     * attempts cannot lose a count between them.
     *
     * The history row is gated behind a short cache lease, so a flood writes one row a minute
     * instead of one row a request — and skips both extra queries the rest of the time.
     */
    public function noteBlockedAttempt(string $ip, Visitor $visitor): void
    {
        $ip = Ip::normalize($ip);

        if ($ip === '') {
            return;
        }

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        $affected = Craft::$app->getDb()->createCommand()
            ->update(
                Install::TABLE_BOTS,
                [
                    'blockedHits' => new Expression('[[blockedHits]] + 1'),
                    'dateLastSeen' => $now,
                    'dateUpdated' => $now,
                ],
                ['ip' => $ip]
            )
            ->execute();

        if (!$affected) {
            return;
        }

        $cache = Craft::$app->getCache();
        $lease = 'blackhole:noted:' . $ip;

        if ($cache->get($lease) !== false) {
            return;
        }

        $cache->set($lease, 1, self::HIT_THROTTLE);

        $botId = (new Query())
            ->select(['id'])
            ->from(Install::TABLE_BOTS)
            ->where(['ip' => $ip])
            ->scalar();

        if ($botId) {
            $this->logHit((int)$botId, $visitor, HitRecord::KIND_BLOCKED);
        }
    }

    /**
     * Writes one history row.
     */
    public function logHit(int $botId, Visitor $visitor, string $kind): void
    {
        $hit = new HitRecord();
        $hit->botId = $botId;
        $hit->kind = $kind;
        $hit->requestUri = $this->truncate($visitor->requestUri, 1000);
        $hit->userAgent = $this->truncate($visitor->userAgent, 1000);
        $hit->referrer = $this->truncate($visitor->referrer, 1000);
        $hit->method = substr($visitor->method, 0, 10);
        $hit->save(false);
    }

    // ------------------------------------------------------------------ managing

    /**
     * Bans an address by hand, whether or not it has ever tripped the trap.
     */
    public function block(string $ip, ?string $note = null): ?BotRecord
    {
        $ip = Ip::normalize($ip);

        if ($ip === '' || !Ip::isValid($ip)) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $record = $this->findByIp($ip);

        if ($record === null) {
            $record = new BotRecord();
            $record->ip = $ip;
            $record->hits = 0;
            $record->blockedHits = 0;
            $record->dateCaught = Db::prepareDateForDb($now);
        }

        $record->status = Settings::STATUS_BLOCKED;
        $record->dateLastSeen = Db::prepareDateForDb($now);
        $record->dateExpires = $settings->banDays > 0
            ? Db::prepareDateForDb((clone $now)->modify('+' . $settings->banDays . ' days'))
            : null;

        if ($note !== null) {
            $record->note = $note;
        }

        $record->save(false);
        $this->invalidateCache();

        return $record;
    }

    /**
     * Lets an address back in, keeping the row so the history survives.
     *
     * The hit count goes back to zero on purpose: a released address that trips the trap again
     * should get the same run-up as anyone else, not be banned instantly by an old count.
     */
    public function release(BotRecord $record): bool
    {
        $record->status = Settings::STATUS_RELEASED;
        $record->hits = 0;
        $record->dateExpires = null;

        $saved = $record->save(false);
        $this->invalidateCache();

        return $saved;
    }

    public function delete(BotRecord $record): bool
    {
        $deleted = (bool)$record->delete();
        $this->invalidateCache();

        return $deleted;
    }

    /**
     * Empties the ledger. The hits go with it, by the cascading key.
     */
    public function purge(): int
    {
        $count = (int)Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_BOTS)
            ->execute();

        $this->invalidateCache();

        return $count;
    }

    /**
     * Housekeeping, run by Craft's garbage collector.
     *
     * Three things go: history older than the retention window, rows whose ban lapsed and who
     * have not been seen since, and released rows nobody has heard from in a long time.
     */
    public function prune(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $db = Craft::$app->getDb();
        $removed = 0;

        if ($settings->logRetentionDays > 0) {
            $cutoff = Db::prepareDateForDb(
                (new DateTime('now', new DateTimeZone('UTC')))->modify('-' . $settings->logRetentionDays . ' days')
            );

            $removed += (int)$db->createCommand()
                ->delete(Install::TABLE_HITS, ['<', 'dateCreated', $cutoff])
                ->execute();

            $removed += (int)$db->createCommand()
                ->delete(Install::TABLE_BOTS, [
                    'and',
                    ['status' => Settings::STATUS_RELEASED],
                    ['<', 'dateLastSeen', $cutoff],
                ])
                ->execute();
        }

        $this->invalidateCache();

        return $removed;
    }

    // ------------------------------------------------------------------ listing

    /**
     * The index query, in one place so the CP list, the console list and the counts agree.
     */
    public function query(?string $status = null, ?string $search = null): Query
    {
        $query = (new Query())
            ->select([
                'id', 'ip', 'hostname', 'userAgent', 'requestUri', 'referrer', 'method', 'status',
                'hits', 'blockedHits', 'siteId', 'note', 'dateCaught', 'dateLastSeen', 'dateExpires',
            ])
            ->from(Install::TABLE_BOTS);

        if ($status !== null && $status !== '' && $status !== 'all') {
            $query->andWhere(['status' => $status]);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $query->andWhere([
                'or',
                ['like', 'ip', $term, false],
                ['like', 'hostname', $term, false],
                ['like', 'userAgent', $term, false],
                ['like', 'requestUri', $term, false],
            ]);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from(Install::TABLE_BOTS)
            ->groupBy(['status'])
            ->all();

        $counts = [
            Settings::STATUS_BLOCKED => 0,
            Settings::STATUS_WARNED => 0,
            Settings::STATUS_RELEASED => 0,
            'all' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
            $counts['all'] += (int)$row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hits(int $botId, int $limit = 100): array
    {
        return (new Query())
            ->from(Install::TABLE_HITS)
            ->where(['botId' => $botId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * A stored datetime as a Unix timestamp.
     *
     * Not `strtotime()`. Craft stores datetimes in UTC with no zone on the string, and
     * `strtotime()` reads a bare string in PHP's *default* timezone — so on a server set to
     * America/Los_Angeles a ban that lapsed an hour ago reads as lapsing seven hours from now,
     * and a temporary ban quietly lasts the wrong length in a way that depends on where the
     * server is. `DateTimeHelper::toDateTime()` assumes UTC for a zone-less string, which is the
     * assumption the column was written under.
     */
    private function timestamp(string $value): int
    {
        $date = DateTimeHelper::toDateTime($value);

        return $date === false ? 0 : $date->getTimestamp();
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim($value);

        return mb_substr($value, 0, $length);
    }
}
