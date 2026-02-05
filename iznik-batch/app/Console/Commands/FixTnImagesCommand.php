<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Mail\Incoming\IncomingMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTnImagesCommand extends Command
{
    protected $signature = 'fix:tn-images
                            {--days=5 : Number of days to look back}
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit=0 : Limit number of messages to process (0 = no limit)}';

    protected $description = 'Fix TN messages that have image links in textbody but no attachments';

    public function handle(IncomingMailService $incomingMailService): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info("Looking for TN messages from the last {$days} days with image links but no attachments...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no changes will be made');
        }

        // Find messages with TN post ID from last N days
        $query = Message::where('tnpostid', '!=', '')
            ->whereNotNull('tnpostid')
            ->where('arrival', '>=', now()->subDays($days))
            // Has TN pic links in textbody
            ->where(function ($q) {
                $q->where('textbody', 'LIKE', '%trashnothing.com/pics/%')
                    ->orWhere('message', 'LIKE', '%trashnothing.com/pics/%');
            });

        if ($limit > 0) {
            $query->limit($limit);
        }

        $messages = $query->get();
        $this->info("Found {$messages->count()} TN messages to check");

        $fixed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($messages as $message) {
            // Check if message has any attachments
            $hasAttachments = MessageAttachment::where('msgid', $message->id)->exists();

            if ($hasAttachments) {
                $skipped++;
                $this->line("  Message {$message->id}: Already has attachments, skipping");

                continue;
            }

            // Try to extract TN image URLs from either textbody or raw message
            $textToSearch = $message->textbody ?: '';
            if (empty($textToSearch) && $message->message) {
                // Fall back to raw message if textbody is empty
                $textToSearch = $message->message;
            }

            $imageUrls = $incomingMailService->scrapeTnImageUrls($textToSearch);

            if (empty($imageUrls)) {
                $skipped++;
                $this->line("  Message {$message->id}: No TN image URLs found");

                continue;
            }

            $this->info("  Message {$message->id}: Found ".count($imageUrls).' images');

            if ($dryRun) {
                foreach ($imageUrls as $url) {
                    $this->line("    Would download: {$url}");
                }
                $fixed++;

                continue;
            }

            // Create the attachments
            try {
                $created = $incomingMailService->createTnImageAttachments($message->id, $imageUrls);

                if ($created > 0) {
                    $this->info("    Created {$created} attachments");

                    // Also clean up the textbody
                    $cleanedTextBody = $incomingMailService->stripTnPicLinks($message->textbody);
                    if ($cleanedTextBody !== $message->textbody) {
                        DB::table('messages')
                            ->where('id', $message->id)
                            ->update(['textbody' => $cleanedTextBody]);
                        $this->line('    Cleaned textbody');
                    }

                    $fixed++;
                } else {
                    $this->warn("    No attachments created (download/upload failed?)");
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->error("    Error: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Skipped: {$skipped}");
        $this->info("  Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }
}
