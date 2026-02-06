<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Service for sending push notifications via Firebase Cloud Messaging.
 *
 * Ported from iznik-server PushNotifications.php.
 * Handles FCM Android/iOS notifications for ModTools moderators.
 */
class PushNotificationService
{
    private const PUSH_FCM_ANDROID = 'FCMAndroid';
    private const PUSH_FCM_IOS = 'FCMIOS';

    private const APPTYPE_MODTOOLS = 'ModTools';

    private $messaging = null;

    public function __construct()
    {
        $credentialsPath = config('freegle.firebase.credentials_path', '/etc/firebase.json');

        if (file_exists($credentialsPath)) {
            try {
                $factory = (new Factory)->withServiceAccount($credentialsPath);
                $this->messaging = $factory->createMessaging();
            } catch (\Throwable $e) {
                Log::warning('Failed to initialize Firebase', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify group moderators of new pending work.
     *
     * Matches legacy PushNotifications::notifyGroupMods().
     * Finds all moderators/owners, checks their pushnotify setting,
     * and sends FCM notifications.
     */
    public function notifyGroupMods(int $groupId): int
    {
        $count = 0;

        $mods = DB::select(
            "SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator')",
            [$groupId]
        );

        foreach ($mods as $mod) {
            // Check per-group notification settings
            $settings = $this->getGroupSettings($mod->userid, $groupId);

            if (! array_key_exists('pushnotify', $settings) || $settings['pushnotify']) {
                $count += $this->notify($mod->userid, TRUE);
            }
        }

        return $count;
    }

    /**
     * Send push notification to a user (ModTools context).
     *
     * Queries the user's registered FCM devices and sends a data-only
     * notification with badge count and message summary.
     */
    public function notify(int $userId, bool $modtools): int
    {
        if (! $this->messaging) {
            Log::debug('Firebase not configured, skipping push notification', [
                'user_id' => $userId,
            ]);

            return 0;
        }

        $count = 0;

        $apptype = $modtools ? self::APPTYPE_MODTOOLS : 'User';
        $notifs = DB::select(
            "SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?",
            [$userId, $apptype]
        );

        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                $payload = $this->buildModToolsPayload($userId);
                if ($payload === null) {
                    continue;
                }

                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('Push notification failed', [
                    'user_id' => $userId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                // Remove invalid/unregistered tokens
                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();

                    Log::info('Removed invalid push subscription', [
                        'user_id' => $userId,
                        'subscription' => substr($notif->subscription, 0, 20) . '...',
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Build the ModTools notification payload.
     *
     * For ModTools, we send a simple "pending messages" notification.
     * Matches legacy User::getNotificationPayload(modtools=true).
     */
    private function buildModToolsPayload(int $userId): ?array
    {
        // Count pending messages across all groups this mod manages
        $pending = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN memberships m ON m.groupid = mg.groupid AND m.userid = ?
             WHERE m.role IN ('Owner', 'Moderator')
             AND mg.collection = 'Pending'",
            [$userId]
        );

        // Count pending spam
        $spam = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN messages ON messages.id = mg.msgid
             INNER JOIN memberships m ON m.groupid = mg.groupid AND m.userid = ?
             WHERE m.role IN ('Owner', 'Moderator')
             AND mg.collection = 'Pending'
             AND messages.spamtype IS NOT NULL",
            [$userId]
        );

        $pendingCount = $pending->cnt ?? 0;
        $spamCount = $spam->cnt ?? 0;
        $total = $pendingCount + $spamCount;

        if ($total === 0) {
            // Still send a zero-count to clear badge
            return [
                'badge' => '0',
                'count' => '0',
                'chatcount' => '0',
                'notifcount' => '0',
                'title' => '',
                'message' => '',
                'chatids' => '',
                'content-available' => '0',
                'image' => 'www/images/modtools_logo.png',
                'modtools' => '1',
                'sound' => 'default',
                'route' => '/modtools',
            ];
        }

        $title = "$total message" . ($total > 1 ? 's' : '') . " pending";
        $message = $pendingCount . ' pending';
        if ($spamCount > 0) {
            $message .= ", $spamCount possible spam";
        }

        return [
            'badge' => (string) $total,
            'count' => (string) $total,
            'chatcount' => '0',
            'notifcount' => (string) $total,
            'title' => $title,
            'message' => $message,
            'chatids' => '',
            'content-available' => '1',
            'image' => 'www/images/modtools_logo.png',
            'modtools' => '1',
            'sound' => 'default',
            'route' => '/modtools/messages/pending',
            'notId' => (string) floor(microtime(TRUE)),
        ];
    }

    /**
     * Send FCM notification to a device.
     */
    private function sendFcm(int $userId, string $type, string $token, array $payload): void
    {
        if ($type === self::PUSH_FCM_ANDROID) {
            // Android: data-only message (no notification block)
            $message = CloudMessage::fromArray([
                'token' => $token,
                'data' => $payload,
            ]);

            $message = $message->withAndroidConfig([
                'ttl' => '3600s',
                'priority' => 'normal',
            ]);
        } else {
            // iOS: include notification block for display
            $ios = [
                'token' => $token,
                'data' => $payload,
            ];

            if (! empty($payload['title'])) {
                $ios['notification'] = [
                    'title' => $payload['title'],
                    'body' => $payload['message'] ?: $payload['title'],
                ];
            }

            $message = CloudMessage::fromArray($ios);

            $badge = (int) ($payload['count'] ?? 0);
            $message = $message->withApnsConfig([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'badge' => $badge,
                        'sound' => 'default',
                    ],
                ],
            ]);
        }

        $this->messaging->validate($message);
        $this->messaging->send($message);
    }

    /**
     * Get user's per-group settings.
     */
    private function getGroupSettings(int $userId, int $groupId): array
    {
        $membership = DB::selectOne(
            "SELECT settings FROM memberships WHERE userid = ? AND groupid = ?",
            [$userId, $groupId]
        );

        if (! $membership || ! $membership->settings) {
            return [];
        }

        return json_decode($membership->settings, TRUE) ?: [];
    }
}
