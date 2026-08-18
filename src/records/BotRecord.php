<?php

namespace justinholtweb\blackhole\records;

use craft\db\ActiveRecord;
use justinholtweb\blackhole\migrations\Install;

/**
 * One caught address.
 *
 * @property int $id
 * @property string $ip
 * @property string|null $hostname
 * @property string|null $userAgent
 * @property string|null $requestUri
 * @property string|null $referrer
 * @property string|null $method
 * @property string $status
 * @property int $hits
 * @property int $blockedHits
 * @property int|null $siteId
 * @property string|null $note
 * @property string|null $notifiedAt
 * @property string $dateCaught
 * @property string $dateLastSeen
 * @property string|null $dateExpires
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class BotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_BOTS;
    }
}
