<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Remove repeated rows from users_aboutme, keeping the history of real changes.
 *
 * users_aboutme keeps one row per change and readers take the latest. Until the
 * API skipped unchanged saves, every settings save added a row: production held
 * 10.7M rows for 87,633 members, and in a sample of 5,265 members 99% of rows
 * repeated the text of the row before them.
 *
 * For each member, a row whose text equals the previous row's text (ordered by
 * timestamp, then id) is deleted. The first row of each run stays, so the
 * timestamp left is when the text was first written. Changes, including a return
 * to earlier text, are kept.
 *
 * Works through members in userid order and can be stopped and restarted with
 * --from.
 */
#[AsCommand(name: 'users:dedupe-aboutme')]
class DedupeAboutMeCommand extends Command
{
    protected $signature = 'users:dedupe-aboutme
                            {--apply : Delete the repeated rows. Without this the command only reports what it would do}
                            {--users=500 : Members to read per batch}
                            {--pause=200 : Milliseconds to wait between batches, to keep replication quiet}
                            {--from=0 : Start after this userid}';

    protected $description = 'Delete users_aboutme rows that repeat the previous text for the same member';

    private const DELETE_CHUNK = 1000;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $batch = max(1, (int) $this->option('users'));
        $pauseMs = max(0, (int) $this->option('pause'));
        $cursor = (int) $this->option('from');

        $this->line($apply ? 'Applying changes.' : 'Dry run: nothing will be deleted. Add --apply to delete.');

        $members = 0;
        $kept = 0;
        $repeats = 0;

        while (true) {
            $userids = DB::table('users_aboutme')
                ->where('userid', '>', $cursor)
                ->orderBy('userid')
                ->distinct()
                ->limit($batch)
                ->pluck('userid')
                ->all();

            if (empty($userids)) {
                break;
            }

            $rows = DB::table('users_aboutme')
                ->select('id', 'userid', 'text')
                ->whereIn('userid', $userids)
                ->orderBy('userid')
                ->orderBy('timestamp')
                ->orderBy('id')
                ->get();

            $doomed = [];
            $lastUser = null;
            $lastText = null;
            foreach ($rows as $row) {
                if ($row->userid === $lastUser && $row->text === $lastText) {
                    $doomed[] = $row->id;
                    continue;
                }
                $lastUser = $row->userid;
                $lastText = $row->text;
                $kept++;
            }

            $members += count($userids);
            $repeats += count($doomed);

            if ($apply) {
                foreach (array_chunk($doomed, self::DELETE_CHUNK) as $ids) {
                    DB::table('users_aboutme')->whereIn('id', $ids)->delete();
                }
            }

            $cursor = (int) end($userids);
            $this->line("userid {$cursor}: {$members} members, {$kept} kept, {$repeats} repeats");

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $verb = $apply ? 'Deleted' : 'Would delete';
        $this->info("{$verb} {$repeats} repeated rows across {$members} members; {$kept} rows kept.");

        return self::SUCCESS;
    }
}
