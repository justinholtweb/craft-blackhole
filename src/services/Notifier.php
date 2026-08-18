<?php

namespace justinholtweb\blackhole\services;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\View;
use DateTime;
use DateTimeZone;
use justinholtweb\blackhole\Plugin;
use justinholtweb\blackhole\records\BotRecord;
use yii\base\Component;

/**
 * The email that goes out when something is caught.
 *
 * Once per address, ever — `notifiedAt` is the latch. A bot that trips the trap, gets banned and
 * then spends the night hammering the site should produce one email, not ten thousand, and the
 * simplest way to guarantee that is to record that the email was sent.
 */
class Notifier extends Component
{
    public function announce(BotRecord $record): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->emailAlerts || $record->notifiedAt !== null) {
            return false;
        }

        $recipients = $this->recipients();

        if (!$recipients) {
            return false;
        }

        $body = Craft::$app->getView()->renderTemplate(
            'blackhole/_mail/caught',
            [
                'bot' => $record,
                'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
                'cpUrl' => \craft\helpers\UrlHelper::cpUrl('blackhole/bots/' . $record->id),
            ],
            View::TEMPLATE_MODE_CP
        );

        $mailer = Craft::$app->getMailer();
        $sent = false;

        foreach ($recipients as $recipient) {
            $message = $mailer->compose()
                ->setSubject(Craft::t('blackhole', 'Black Hole caught {ip}', ['ip' => $record->ip]))
                ->setHtmlBody($body)
                ->setTo($recipient);

            $sent = $message->send() || $sent;
        }

        if ($sent) {
            $record->notifiedAt = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
            $record->save(false);
        }

        return $sent;
    }

    /**
     * Who hears about it: whoever the settings name, or every admin if they name nobody.
     *
     * @return string[]
     */
    public function recipients(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $configured = array_values(array_filter(array_map('trim', $settings->alertRecipients)));

        if ($configured) {
            return $configured;
        }

        $emails = [];

        foreach (User::find()->admin()->status(User::STATUS_ACTIVE)->all() as $admin) {
            if ($admin->email) {
                $emails[] = $admin->email;
            }
        }

        return array_values(array_unique($emails));
    }
}
