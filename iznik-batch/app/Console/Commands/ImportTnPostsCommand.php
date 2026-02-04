<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportTnPostsCommand extends Command
{
    protected $signature = 'tn:import-posts
        {csv : Path to CSV file with TN posts}
        {--test : Test mode - only process first post}
        {--dry-run : Show what would be done without creating records}
        {--limit=0 : Limit number of posts to process}';

    protected $description = 'Import missing TrashNothing posts from CSV export';

    private int $created = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        $testMode = $this->option('test');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (!file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");
            return 1;
        }

        $this->info("Importing TN posts from: {$csvPath}");
        if ($testMode) {
            $this->warn("TEST MODE: Only processing first post");
            $limit = 1;
        }
        if ($dryRun) {
            $this->warn("DRY RUN: No records will be created");
        }

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);

        // Support both original TN format and merged format with group routing
        $requiredHeaders = ['from', 'subject', 'tn_post_id'];
        $headerMap = array_flip($headers);

        // Check if this is the merged format (has group_id)
        $mergedFormat = isset($headerMap['group_id']);

        if (!$mergedFormat) {
            // Original TN format requires these headers
            $requiredHeaders = ['from', 'date', 'subject', 'latitude', 'longitude', 'content', 'X-trash-nothing-Post-ID', 'X-trash-nothing-User-ID'];
        }

        foreach ($requiredHeaders as $required) {
            if (!isset($headerMap[$required])) {
                $this->error("Missing required header: {$required}");
                return 1;
            }
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if ($limit > 0 && $rowNum > $limit) {
                break;
            }

            $data = array_combine($headers, $row);

            try {
                $tnPostId = $data['tn_post_id'] ?? $data['X-trash-nothing-Post-ID'] ?? 'unknown';
                $result = $this->processPost($data, $dryRun);

                if ($result === 'created') {
                    $this->created++;
                    $this->info("✓ Created post {$tnPostId}: {$data['subject']}");
                } elseif ($result === 'skipped') {
                    $this->skipped++;
                    $this->line("- Skipped post {$tnPostId}: already exists");
                } else {
                    $this->errors++;
                    $this->warn("? Failed post {$tnPostId}: {$result}");
                }

            } catch (\Exception $e) {
                $this->errors++;
                $tnPostId = $data['tn_post_id'] ?? $data['X-trash-nothing-Post-ID'] ?? 'unknown';
                $this->error("✗ Error processing post {$tnPostId}: {$e->getMessage()}");
                Log::error("TN import error", [
                    'tn_post_id' => $tnPostId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        fclose($handle);

        $this->newLine();
        $this->info("Import complete:");
        $this->line("  Created: {$this->created}");
        $this->line("  Skipped: {$this->skipped}");
        $this->line("  Errors:  {$this->errors}");

        return $this->errors > 0 ? 1 : 0;
    }

    private function processPost(array $data, bool $dryRun): string
    {
        // Support both formats
        $tnPostId = $data['tn_post_id'] ?? $data['X-trash-nothing-Post-ID'];
        $tnUserId = $data['tn_user_id'] ?? $data['X-trash-nothing-User-ID'];
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $subject = $data['subject'];
        $content = $data['content'];
        $date = $data['timestamp'] ?? $data['date'];
        $from = $data['from'];

        // Check if this TN post already exists for this group
        $groupId = $data['group_id'] ?? null;

        if ($groupId) {
            // Merged format - check for exact post+group combo
            $existingInGroup = DB::table('messages')
                ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
                ->where('messages.tnpostid', $tnPostId)
                ->where('messages_groups.groupid', $groupId)
                ->exists();

            if ($existingInGroup) {
                return 'skipped';
            }
        } else {
            // Original format - check if TN post exists at all
            $existing = Message::where('tnpostid', $tnPostId)->first();
            if ($existing) {
                return 'skipped';
            }
        }

        // Parse from address - format: "username" <username-g1997@user.trashnothing.com>
        // Or simple format: username-g1234@user.trashnothing.com
        $fromAddress = $this->parseFromAddress($from);
        $fromName = $this->parseFromName($from);

        // Find group(s)
        if ($groupId) {
            // Merged format - use the specific group from the log
            $group = Group::find($groupId);
            if (!$group) {
                return "group {$groupId} not found";
            }
            $groups = [$group];
        } else {
            // Original format - find nearest groups
            $groups = $this->findGroupsNear($lat, $lng);
            if (empty($groups)) {
                return "no groups found near {$lat}, {$lng}";
            }
            // Only use the nearest group to avoid duplicate posts
            $groups = [$groups[0]];
        }

        // Find or create user
        $user = $this->findOrCreateUser($fromAddress, $fromName, $tnUserId, $lat, $lng);

        if (!$user) {
            return "failed to find/create user for {$fromAddress}";
        }

        if ($dryRun) {
            $groupNames = collect($groups)->pluck('nameshort')->implode(', ');
            $this->line("  Would create: TN#{$tnPostId} to groups: {$groupNames}");
            $this->line("  User: {$user->id} ({$user->displayname})");
            return 'created';
        }

        // Determine message type from subject
        $type = Message::determineType($subject);

        // Parse date
        $arrivalDate = \Carbon\Carbon::parse($date);

        // Create message for the specific group
        $createdCount = 0;
        foreach ($groups as $group) {
            // Double-check this specific message-group combo doesn't exist
            $existingInGroup = DB::table('messages')
                ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
                ->where('messages.tnpostid', $tnPostId)
                ->where('messages_groups.groupid', $group->id)
                ->exists();

            if ($existingInGroup) {
                continue;
            }

            // Ensure user is member of group
            $this->ensureMembership($user, $group);

            // Generate unique message ID
            $messageId = "tn-import-{$tnPostId}-{$group->id}@" . config('freegle.mail.user_domain', 'users.ilovefreegle.org');

            // Find closest postcode for locationid
            $locationId = $this->findClosestPostcodeId($lat, $lng);

            // Create message record
            $message = Message::create([
                'arrival' => $arrivalDate,
                'date' => $arrivalDate,
                'source' => 'Email',
                'sourceheader' => 'TN-native-app', // Default, could be TN-web-app too
                'message' => '', // Raw message not available
                'fromuser' => $user->id,
                'envelopefrom' => $fromAddress,
                'envelopeto' => strtolower($group->nameshort) . '@groups.ilovefreegle.org',
                'fromname' => $fromName,
                'fromaddr' => $fromAddress,
                'subject' => $subject,
                'suggestedsubject' => $subject,
                'messageid' => $messageId,
                'tnpostid' => $tnPostId,
                'textbody' => $content,
                'type' => $type,
                'lat' => (string) $lat,
                'lng' => (string) $lng,
                'locationid' => $locationId,
            ]);

            if (!$message || !$message->id) {
                Log::error("Failed to create message for TN post", [
                    'tn_post_id' => $tnPostId,
                    'group' => $group->nameshort,
                ]);
                continue;
            }

            // Create messages_groups entry - mark as Approved
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'msgtype' => $type,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => $arrivalDate,
            ]);

            // Add to message history
            DB::table('messages_history')->insert([
                'groupid' => $group->id,
                'source' => 'Email',
                'fromuser' => $user->id,
                'envelopefrom' => $fromAddress,
                'envelopeto' => strtolower($group->nameshort) . '@groups.ilovefreegle.org',
                'fromname' => $fromName,
                'fromaddr' => $fromAddress,
                'subject' => $subject,
                'prunedsubject' => $this->pruneSubject($subject),
                'messageid' => $messageId,
                'msgid' => $message->id,
            ]);

            $createdCount++;
            $this->line("    → Created in group: {$group->nameshort}");
        }

        return $createdCount > 0 ? 'created' : 'no groups matched';
    }

    private function parseFromAddress(string $from): string
    {
        // Parse "username" <email@domain.com> format
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return strtolower($matches[1]);
        }
        return strtolower(trim($from, '"'));
    }

    private function parseFromName(string $from): string
    {
        // Parse "username" <email@domain.com> format
        if (preg_match('/^"?([^"<]+)"?\s*</', $from, $matches)) {
            return trim($matches[1]);
        }

        // Extract username from email address
        // TN emails are like: username-g1234@user.trashnothing.com
        $email = $this->parseFromAddress($from);
        $localPart = explode('@', $email)[0];
        // Remove the -g1234 suffix
        $username = preg_replace('/-g\d+$/', '', $localPart);

        return $username ?: 'TrashNothing User';
    }

    private function findGroupsNear(float $lat, float $lng, int $radiusMiles = 20): array
    {
        $srid = config('freegle.srid', 3857);

        // Create bounding box for initial search
        // Roughly 1 degree = 69 miles at equator, less at higher latitudes
        $degPerMile = 1 / 69.0;
        $latDelta = $radiusMiles * $degPerMile;
        $lngDelta = $radiusMiles * $degPerMile / cos(deg2rad($lat));

        $swLat = $lat - $latDelta;
        $swLng = $lng - $lngDelta;
        $neLat = $lat + $latDelta;
        $neLng = $lng + $lngDelta;

        // Use MBRIntersects to find groups whose polygon intersects our search box
        $box = "ST_GeomFromText('POLYGON(({$swLng} {$swLat}, {$swLng} {$neLat}, {$neLng} {$neLat}, {$neLng} {$swLat}, {$swLng} {$swLat}))', {$srid})";

        $sql = "SELECT id, nameshort, lat, lng,
                    ST_distance(ST_GeomFromText('POINT({$lng} {$lat})', {$srid}), polyindex) * 111195 * 0.000621371 AS dist,
                    haversine(lat, lng, {$lat}, {$lng}) AS hav
                FROM `groups`
                WHERE MBRIntersects(polyindex, {$box})
                    AND publish = 1
                    AND listable = 1
                    AND type = 'Freegle'
                    AND ontn = 1
                HAVING hav < {$radiusMiles}
                ORDER BY dist ASC
                LIMIT 10";

        $results = DB::select($sql);

        // Convert to Group models
        $groups = [];
        foreach ($results as $result) {
            $group = Group::find($result->id);
            if ($group) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function findOrCreateUser(string $email, string $name, string $tnUserId, float $lat, float $lng): ?User
    {
        // First try to find by email in users_emails table
        $userEmail = UserEmail::where('email', $email)->first();

        if ($userEmail) {
            return User::find($userEmail->userid);
        }

        // Try to find by TN-style email pattern
        // TN emails are like: username-g1997@user.trashnothing.com
        // The username part can be used to find existing users
        $emailPrefix = explode('-g', explode('@', $email)[0])[0];
        $userEmail = UserEmail::where('email', 'LIKE', $emailPrefix . '%@user.trashnothing.com')
            ->first();

        if ($userEmail) {
            return User::find($userEmail->userid);
        }

        // Also try to find by tnuserid field in users table
        if ($tnUserId) {
            $user = User::where('tnuserid', $tnUserId)->first();
            if ($user) {
                // Add this email to the user
                UserEmail::create([
                    'userid' => $user->id,
                    'email' => $email,
                    'preferred' => 0,
                    'added' => now(),
                ]);
                return $user;
            }
        }

        // Create new user
        $user = User::create([
            'firstname' => $name,
            'fullname' => $name,
            'tnuserid' => $tnUserId ?: null,
            'added' => now(),
            'lastaccess' => now(),
        ]);

        // Add email to users_emails
        UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => 1,
            'added' => now(),
        ]);

        Log::info("Created new user for TN import", [
            'user_id' => $user->id,
            'email' => $email,
            'tn_user_id' => $tnUserId,
        ]);

        return $user;
    }

    private function ensureMembership(User $user, Group $group): void
    {
        $exists = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->exists();

        if (!$exists) {
            Membership::create([
                'userid' => $user->id,
                'groupid' => $group->id,
                'role' => 'Member',
                'collection' => 'Approved',
                'added' => now(),
            ]);
        }
    }

    private function pruneSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        // Remove location in parentheses at end
        $pruned = preg_replace('/\s*\([^)]+\)\s*$/', '', $subject);
        // Remove type prefix
        $pruned = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $pruned);

        return trim($pruned);
    }

    /**
     * Find the closest postcode location ID for given coordinates.
     */
    private function findClosestPostcodeId(float $lat, float $lng): ?int
    {
        $srid = config('freegle.srid', 3857);
        $scan = 0.00001953125;

        while ($scan <= 0.2) {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

            $sql = "SELECT locations.id
                    FROM locations_spatial
                    INNER JOIN locations ON locations.id = locations_spatial.locationid
                    WHERE MBRContains(ST_Envelope(ST_GeomFromText('$poly', $srid)), locations_spatial.geometry)
                      AND locations.type = 'Postcode'
                      AND LOCATE(' ', locations.name) > 0
                    ORDER BY ST_distance(locations_spatial.geometry, ST_GeomFromText('POINT($lng $lat)', $srid)) ASC
                    LIMIT 1";

            $result = DB::selectOne($sql);

            if ($result) {
                return (int) $result->id;
            }

            $scan *= 2;
        }

        return null;
    }
}
