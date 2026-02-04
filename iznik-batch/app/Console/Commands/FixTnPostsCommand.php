<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixTnPostsCommand extends Command
{
    protected $signature = 'tn:fix-posts
        {--dry-run : Show what would be done without making changes}
        {--limit=0 : Limit number of posts to process}';

    protected $description = 'Fix imported TN posts: add item links (finds posts by tn-import-* messageid pattern)';

    private int $fixed = 0;
    private int $skipped = 0;
    private int $errors = 0;
    private int $itemsLinked = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn("DRY RUN: No changes will be made");
        }

        // Find all imported TN posts that don't have items linked
        $query = DB::table('messages')
            ->where('messageid', 'LIKE', 'tn-import-%')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_items')
                    ->whereColumn('messages_items.msgid', 'messages.id');
            })
            ->select('id', 'subject', 'tnpostid');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $messages = $query->get();

        $this->info("Found " . count($messages) . " imported TN posts without item links");

        foreach ($messages as $message) {
            try {
                $result = $this->fixPost($message, $dryRun);

                if ($result === 'fixed') {
                    $this->fixed++;
                } elseif ($result === 'skipped') {
                    $this->skipped++;
                } else {
                    $this->errors++;
                    $this->warn("  ? msg {$message->id}: {$result}");
                }

            } catch (\Exception $e) {
                $this->errors++;
                $this->error("✗ Error on msg {$message->id}: {$e->getMessage()}");
                Log::error("TN fix error", [
                    'msgid' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Fix complete:");
        $this->line("  Fixed:        {$this->fixed}");
        $this->line("  Items linked: {$this->itemsLinked}");
        $this->line("  Skipped:      {$this->skipped}");
        $this->line("  Errors:       {$this->errors}");

        return $this->errors > 0 ? 1 : 0;
    }

    private function fixPost(object $message, bool $dryRun): string
    {
        $fixes = [];

        // 1. Parse subject and link item
        list($type, $itemName, $location) = $this->parseSubject($message->subject);

        if ($itemName) {
            $itemId = $this->findOrCreateItem($itemName, $dryRun);
            if ($itemId) {
                if (!$dryRun) {
                    DB::table('messages_items')->insertOrIgnore([
                        'msgid' => $message->id,
                        'itemid' => $itemId,
                    ]);
                }
                $this->itemsLinked++;
                $fixes[] = "item → {$itemName}";
            }
        }

        // Note: Skipping approvedby/approvedat - not critical and no system user exists

        if (empty($fixes)) {
            return 'skipped';
        }

        $this->info("✓ msg {$message->id}: " . implode(', ', $fixes));
        return 'fixed';
    }

    private function parseFromAddress(string $from): string
    {
        // Parse "username" <email@domain.com> format
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return strtolower($matches[1]);
        }
        return strtolower(trim($from, '"'));
    }

    /**
     * Parse subject line: "OFFER: Item name (Location)"
     * Returns [type, item, location]
     */
    private function parseSubject(string $subj): array
    {
        $type = null;
        $item = null;
        $location = null;

        $p = strpos($subj, ':');

        if ($p !== false) {
            $startp = $p;
            $rest = trim(substr($subj, $p + 1));
            $p = strlen($rest) - 1;

            if (substr($rest, -1) == ')') {
                $count = 0;

                do {
                    $curr = substr($rest, $p, 1);

                    if ($curr == '(') {
                        $count--;
                    } elseif ($curr == ')') {
                        $count++;
                    }

                    $p--;
                } while ($count > 0 && $p > 0);

                if ($count == 0) {
                    $type = trim(substr($subj, 0, $startp));
                    $location = trim(substr($rest, $p + 2, strlen($rest) - $p - 3));
                    $item = trim(substr($rest, 0, $p));
                }
            }
        }

        return [$type, $item, $location];
    }

    /**
     * Find or create an item by name
     */
    private function findOrCreateItem(string $name, bool $dryRun): ?int
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        // Truncate to max length (from Item.php)
        if (strlen($name) > 60) {
            $name = substr($name, 0, 60);
        }

        // Try to find existing item
        $existing = DB::table('items')
            ->where('name', $name)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        if ($dryRun) {
            return 999999; // Placeholder for dry run
        }

        // Create new item (indexing will happen via normal cron process)
        $id = DB::table('items')->insertGetId([
            'name' => $name,
            'popularity' => 0,
        ]);

        return $id;
    }
}
