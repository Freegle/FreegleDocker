<?php

namespace App\Console\Commands\TrashNothing;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\Location;
use App\Models\User;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TNSyncCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;
    use LogsBatchJob;

    protected $signature = 'tn:sync';

    protected $description = 'Sync data from TrashNothing, including user data updates, user ratings, posts/messages, and chat messages.';

    private const PAGE_SIZE = 100;

    private string $apiKey;
    private string $apiBaseUrl;
    private string $dateFile;

    public function handle(): int
    {
        $this->registerShutdownHandlers();

        if (!$this->acquireLock()) {
            $this->warn('TN sync is already running.');
            return Command::SUCCESS;
        }

        $this->apiKey = config('freegle.trashnothing.api_key');
        $this->apiBaseUrl = config('freegle.trashnothing.api_base_url');
        $this->dateFile = config('freegle.trashnothing.sync_date_file');

        try {
            return $this->runWithLogging(function () {
                $this->info('Starting TN sync...');

                $from = $this->getSyncFromDate();
                $to = gmdate('c');

                $maxChangeDate = null;

                // Sync ratings.
                [$ratingsProcessed, $ratingsMaxDate] = $this->syncRatings($from, $to);
                if ($ratingsMaxDate && (!$maxChangeDate || $ratingsMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $ratingsMaxDate;
                }

                // Sync user changes.
                [$changesProcessed, $changesMaxDate] = $this->syncUserChanges($from, $to);
                if ($changesMaxDate && (!$maxChangeDate || $changesMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $changesMaxDate;
                }

                // Merge duplicate TN users.
                $duplicatesMerged = $this->mergeDuplicateTNUsers();

                // Store the max change date for next sync.
                if ($maxChangeDate) {
                    $this->storeSyncDate($maxChangeDate);
                } else {
                    Log::info('No change date to store - no data processed');
                }

                if ($ratingsProcessed === 0 && $changesProcessed === 0 && $duplicatesMerged === 0) {
                    Log::warning('TN sync did nothing');
                    if (function_exists('\Sentry\captureMessage')) {
                        \Sentry\captureMessage('TN sync did nothing');
                    }
                }

                $this->info("TN sync complete: {$ratingsProcessed} ratings, {$changesProcessed} user changes, {$duplicatesMerged} duplicates merged.");
                Log::info('TN sync complete', [
                    'ratings_processed' => $ratingsProcessed,
                    'changes_processed' => $changesProcessed,
                    'duplicates_merged' => $duplicatesMerged,
                ]);

                return Command::SUCCESS;
            });
        } catch (\Exception $e) {
            $this->error('TN sync failed: ' . $e->getMessage());
            Log::error('TN sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        } finally {
            $this->releaseLock();
        }
    }

    private function getSyncFromDate(): string
    {
        // Try local file first.
        if (file_exists($this->dateFile)) {
            $lastSyncDate = trim(file_get_contents($this->dateFile));
            if ($lastSyncDate && strtotime($lastSyncDate)) {
                Log::info("Using stored sync date from {$this->dateFile}: {$lastSyncDate}");
                return $lastSyncDate;
            }
        }

        // Fallback to max rating timestamp.
        $max = DB::table('ratings')
            ->whereNotNull('tn_rating_id')
            ->max('timestamp');

        $from = $max ? gmdate('c', strtotime($max)) : gmdate('c', strtotime('-1 day'));
        Log::info("No stored sync date found, using max rating timestamp: {$from}");

        return $from;
    }

    private function storeSyncDate(string $date): void
    {
        if (file_put_contents($this->dateFile, $date) !== false) {
            Log::info("Stored max change date to {$this->dateFile}: {$date}");
        } else {
            Log::error("Failed to store max change date to {$this->dateFile}");
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage("Failed to store TN sync date to {$this->dateFile}");
            }
        }
    }

    /**
     * @return array [count, maxDate]
     */
    private function syncRatings(string $from, string $to): array
    {
        $page = 1;
        $count = 0;
        $maxDate = NULL;

        do {
            $response = Http::get("{$this->apiBaseUrl}/ratings", [
                'key' => $this->apiKey,
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'date_min' => $from,
                'date_max' => $to,
            ]);

            if (!$response->successful()) {
                Log::error("TN sync: ratings API failed on page {$page}", [
                    'status' => $response->status(),
                ]);
                break;
            }

            $ratings = $response->json('ratings', []);
            $page++;

            foreach ($ratings as $rating) {
                $count++;

                if (!$maxDate || $rating['date'] > $maxDate) {
                    $maxDate = $rating['date'];
                }

                if (!($rating['ratee_fd_user_id'] ?? null)) {
                    continue;
                }

                $user = User::find($rating['ratee_fd_user_id']);
                if (!$user) {
                    continue;
                }

                try {
                    if ($rating['rating']) {
                        DB::table('ratings')->upsert(
                            [
                                'ratee' => $rating['ratee_fd_user_id'],
                                'rating' => $rating['rating'],
                                'timestamp' => $rating['date'],
                                'visible' => 1,
                                'tn_rating_id' => $rating['rating_id'],
                            ],
                            ['tn_rating_id'],
                            ['rating', 'timestamp']
                        );
                    } else {
                        DB::table('ratings')
                            ->where('ratee', $rating['ratee_fd_user_id'])
                            ->where('tn_rating_id', $rating['rating_id'])
                            ->delete();
                    }
                } catch (\Exception $e) {
                    Log::error('TN sync: ratings sync failed', [
                        'error' => $e->getMessage(),
                        'rating' => $rating,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        } while ($ratings && count($ratings) == self::PAGE_SIZE);

        return [$count, $maxDate];
    }

    /**
     * @return array [count, maxDate]
     */
    private function syncUserChanges(string $from, string $to): array
    {
        $page = 1;
        $count = 0;
        $maxDate = null;

        do {
            $response = Http::get("{$this->apiBaseUrl}/user-changes", [
                'key' => $this->apiKey,
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'date_min' => $from,
                'date_max' => $to,
            ]);

            if (!$response->successful()) {
                Log::error("TN sync: user-changes API failed on page {$page}", [
                    'status' => $response->status(),
                ]);
                break;
            }

            $changes = $response->json('changes', []);
            $page++;

            foreach ($changes as $change) {
                $count++;

                if (!$maxDate || $change['date'] > $maxDate) {
                    $maxDate = $change['date'];
                }

                if (!($change['fd_user_id'] ?? null)) {
                    continue;
                }

                try {
                    $user = User::find($change['fd_user_id']);
                    if (!$user || !$user->isTN()) {
                        continue;
                    }

                    if (!empty($change['account_removed'])) {
                        Log::info("FD #{$change['fd_user_id']} TN account removed");
                        $user->forget('TN account removed');
                        continue;
                    }

                    if (!empty($change['reply_time'])) {
                        DB::table('users_replytime')->upsert(
                            [
                                'userid' => $change['fd_user_id'],
                                'replytime' => $change['reply_time'],
                                'timestamp' => $change['date'],
                            ],
                            ['userid'],
                            ['replytime', 'timestamp']
                        );
                    }

                    if (!empty($change['about_me'])) {
                        try {
                            DB::table('users_aboutme')->upsert(
                                [
                                    'userid' => $change['fd_user_id'],
                                    'timestamp' => $change['date'],
                                    'text' => $change['about_me'],
                                ],
                                ['userid'],
                                ['timestamp', 'text']
                            );
                        } catch (\Exception $e) {
                            if (function_exists('\Sentry\captureException')) {
                                \Sentry\captureException($e);
                            }
                        }
                    }

                    // Spot name changes.
                    if (!empty($change['username'])) {
                        $oldname = User::removeTNGroup($user->fullname ?? '');

                        if ($oldname != $change['username']) {
                            Log::info("Name change for {$change['fd_user_id']} {$oldname} => {$change['username']}");
                            $user->update(['fullname' => $change['username']]);

                            $emails = $user->emails()->pluck('email');

                            foreach ($emails as $email) {
                                if (str_contains($email, "{$oldname}-")) {
                                    $newEmail = str_replace("{$oldname}-", "{$change['username']}-", $email);
                                    $user->removeEmail($email);
                                    Log::info("...{$email} => {$newEmail}");
                                    $user->addEmail($newEmail);
                                }
                            }
                        }
                    }

                    // Location changes.
                    if (!empty($change['location'])) {
                        $lat = $change['location']['latitude'] ?? null;
                        $lng = $change['location']['longitude'] ?? null;

                        if ($lat !== null && $lng !== null) {
                            $loc = Location::closestPostcode((float) $lat, (float) $lng);

                            if ($loc) {
                                Log::info("FD #{$change['fd_user_id']} TN lat/lng {$lat},{$lng} has changed  => {$loc['id']} {$loc['name']}");
                                $user->update(['lastlocation' => $loc['id']]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('TN sync: user changes sync failed', [
                        'error' => $e->getMessage(),
                        'change' => $change,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        } while ($changes && count($changes) == self::PAGE_SIZE);

        return [$count, $maxDate];
    }

    private function mergeDuplicateTNUsers(): int
    {
        $duplicates = DB::table('users_emails')
            ->selectRaw("COUNT(DISTINCT(userid)) AS count, REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\\.trashnothing\\.com', '$1') AS username")
            ->where('email', 'LIKE', '%@user.trashnothing.com')
            ->groupBy('username')
            ->having('count', '>', 1)
            ->get()
            ->toArray();

        if (empty($duplicates)) {
            return 0;
        }

        Log::info('Found ' . count($duplicates) . ' duplicate TN users');
        $merged = 0;

        foreach ($duplicates as $dup) {
            Log::info("Look for dups for {$dup->username}");

            $userIds = DB::table('users_emails')
                ->selectRaw("DISTINCT(userid) as userid")
                ->whereRaw("REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\\.trashnothing\\.com', '$1') = ?", [$dup->username])
                ->where('email', 'LIKE', '%@user.trashnothing.com')
                ->pluck('userid')
                ->toArray();

            Log::info('Found ' . count($userIds) . " users for {$dup->username}");

            if (count($userIds) > 1) {
                $mergeTo = $userIds[0];

                foreach ($userIds as $userId) {
                    if ($userId != $mergeTo) {
                        Log::info("Merging {$userId} into {$mergeTo}");
                        User::merge($mergeTo, $userId, "Duplicate TN user created accidentally");
                        $merged++;
                    }
                }
            }
        }

        return $merged;
    }
}
