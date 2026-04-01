<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dump a user's data to a JSON file for later restoration.
 *
 * Run this on the backup (Yesterday) system, then use user:restore on the
 * target (live) system to apply the data.
 *
 * Usage: php artisan user:dump --email=user@example.com --output=/tmp/user-dump.json
 */
class DumpUserCommand extends Command
{
    protected $signature = 'user:dump
                            {--email= : Email address of the user to dump (required)}
                            {--output= : Output file path (default: /tmp/user-<id>.json)}';

    protected $description = 'Dump a user\'s data to a JSON file for restoration on another system';

    /**
     * Tables to dump, with the columns that hold the user ID.
     * Mirrors the logic in scripts/cli/user_restore.php.
     */
    private array $tables = [
        'memberships'              => ['userid'],
        'spam_users'               => ['userid', 'byuserid'],
        'users_banned'             => ['userid'],
        'users_donations'          => ['userid'],
        'microactions'             => ['userid'],
        'giftaid'                  => ['userid'],
        'users_logins'             => ['userid'],
        'users_emails'             => ['userid'],
        'users_comments'           => ['userid', 'byuserid'],
        'sessions'                 => ['userid'],
        'messages'                 => ['fromuser'],
        'users_push_notifications' => ['userid'],
        'users_notifications'      => ['fromuser', 'touser'],
        'chat_rooms'               => ['user1', 'user2'],
        'chat_roster'              => ['userid'],
        'chat_messages'            => ['userid'],
        'users_searches'           => ['userid'],
        'memberships_history'      => ['userid'],
        'logs'                     => ['user'],
        'logs_sql'                 => ['userid'],
        'newsfeed'                 => ['userid'],
    ];

    public function handle(): int
    {
        $email = $this->option('email');

        if (! $email) {
            $this->error('--email is required');
            $this->line('Usage: php artisan user:dump --email=user@example.com --output=/tmp/dump.json');

            return Command::FAILURE;
        }

        // Find the user by email.
        $userEmail = DB::table('users_emails')->where('email', $email)->first();

        if (! $userEmail) {
            $this->error("No user found with email: $email");

            return Command::FAILURE;
        }

        $userId = $userEmail->userid;
        $user = DB::table('users')->where('id', $userId)->first();

        if (! $user) {
            $this->error("User record not found for id: $userId");

            return Command::FAILURE;
        }

        $this->info("Dumping user #$userId ($email)");

        $output = $this->option('output') ?? "/tmp/user-{$userId}.json";

        // Collect user attributes (those the restore script copies).
        $dump = [
            'version'       => 1,
            'email'         => $email,
            'dumped_at'     => now()->toIso8601String(),
            'source_userid' => $userId,
            'user'          => [
                'fullname'    => $user->fullname,
                'firstname'   => $user->firstname,
                'lastname'    => $user->lastname,
                'yahooid'     => $user->yahooid,
                'systemrole'  => $user->systemrole,
                'permissions' => $user->permissions,
            ],
            'tables'        => [],
        ];

        // Dump each table.
        foreach ($this->tables as $table => $userIdColumns) {
            $rows = [];
            $query = DB::table($table);

            // Build OR condition for all user-ID columns.
            $query->where(function ($q) use ($userIdColumns, $userId) {
                foreach ($userIdColumns as $col) {
                    $q->orWhere($col, $userId);
                }
            });

            foreach ($query->get() as $row) {
                $rows[] = (array) $row;
            }

            if (count($rows) > 0) {
                $this->line("  $table: ".count($rows).' row(s)');
            }

            $dump['tables'][$table] = $rows;
        }

        // Also dump messages_groups for all messages owned by this user.
        $messageIds = DB::table('messages')
            ->where('fromuser', $userId)
            ->pluck('id')
            ->toArray();

        $messagesGroups = [];
        if (count($messageIds) > 0) {
            foreach (DB::table('messages_groups')->whereIn('msgid', $messageIds)->get() as $row) {
                $messagesGroups[] = (array) $row;
            }
        }
        $dump['tables']['messages_groups'] = $messagesGroups;

        if (count($messagesGroups) > 0) {
            $this->line('  messages_groups: '.count($messagesGroups).' row(s)');
        }

        // Write to file.
        $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($output, $json);

        $this->info("Dump written to: $output");
        $this->line('User ID: '.$userId);
        $this->line('Tables dumped: '.count(array_filter($dump['tables'], fn ($r) => count($r) > 0)));

        return Command::SUCCESS;
    }
}
