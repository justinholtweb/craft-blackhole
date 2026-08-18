<?php

namespace justinholtweb\blackhole\migrations;

use craft\db\Migration;

/**
 * Installs the trap's ledger.
 *
 * Two tables: one row per caught address, and one row per request that address made after it was
 * caught. Splitting them keeps the index page cheap — it reads identities, not history — while
 * still leaving a full account of what a bot did once it was in the hole.
 */
class Install extends Migration
{
    public const TABLE_BOTS = '{{%blackhole_bots}}';
    public const TABLE_HITS = '{{%blackhole_hits}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_BOTS)) {
            $this->createTable(self::TABLE_BOTS, [
                'id' => $this->primaryKey(),
                // 45 characters is a full IPv6 address with an IPv4-mapped tail.
                'ip' => $this->string(45)->notNull(),
                'hostname' => $this->string(255),
                'userAgent' => $this->text(),
                'requestUri' => $this->text(),
                'referrer' => $this->text(),
                'method' => $this->string(10),
                'status' => $this->string(16)->notNull()->defaultValue('blocked'),
                'hits' => $this->integer()->notNull()->defaultValue(1),
                'blockedHits' => $this->integer()->notNull()->defaultValue(0),
                'siteId' => $this->integer(),
                'note' => $this->text(),
                'notifiedAt' => $this->dateTime(),
                'dateCaught' => $this->dateTime()->notNull(),
                'dateLastSeen' => $this->dateTime()->notNull(),
                'dateExpires' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // One row per address is the whole model: a bot that comes back updates its row
            // rather than starting a new one, so the unique key is doing real work.
            $this->createIndex(null, self::TABLE_BOTS, ['ip'], true);
            $this->createIndex(null, self::TABLE_BOTS, ['status']);
            $this->createIndex(null, self::TABLE_BOTS, ['dateLastSeen']);

            // Deliberately no foreign key to `sites`: which site a bot happened to trip the trap
            // on is a note about the past, and losing the site should not lose the ban.
        }

        if (!$this->db->tableExists(self::TABLE_HITS)) {
            $this->createTable(self::TABLE_HITS, [
                'id' => $this->primaryKey(),
                'botId' => $this->integer()->notNull(),
                // 'trap' — tripped the honeypot. 'blocked' — turned away by the guard.
                'kind' => $this->string(16)->notNull()->defaultValue('trap'),
                'requestUri' => $this->text(),
                'userAgent' => $this->text(),
                'referrer' => $this->text(),
                'method' => $this->string(10),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_HITS, ['botId', 'dateCreated']);
            $this->createIndex(null, self::TABLE_HITS, ['dateCreated']);

            $this->addForeignKey(
                null,
                self::TABLE_HITS,
                ['botId'],
                self::TABLE_BOTS,
                ['id'],
                'CASCADE',
                null
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_HITS);
        $this->dropTableIfExists(self::TABLE_BOTS);

        return true;
    }
}
