<?php

namespace justinholtweb\blackhole\records;

use craft\db\ActiveRecord;
use justinholtweb\blackhole\migrations\Install;

/**
 * One request a caught address made — the trip that caught it, or an attempt turned away after.
 *
 * @property int $id
 * @property int $botId
 * @property string $kind
 * @property string|null $requestUri
 * @property string|null $userAgent
 * @property string|null $referrer
 * @property string|null $method
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class HitRecord extends ActiveRecord
{
    public const KIND_TRAP = 'trap';
    public const KIND_BLOCKED = 'blocked';

    public static function tableName(): string
    {
        return Install::TABLE_HITS;
    }
}
