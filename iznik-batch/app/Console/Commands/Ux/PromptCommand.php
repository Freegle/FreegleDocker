<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxPromptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The questions microvolunteers are asked that are not about one experiment: a "best / worst"
 * survey of Freegle's areas shown four at a time, awareness questions ("On Freegle, is it
 * possible to promise an item to someone in a chat?"), and free-text questions that farm problems
 * and values. A prompt is a row, so a new
 * question needs no code or schema change.
 *
 *   ux:prompt list
 *   ux:prompt survey                      (open the standard best / worst survey over the funnels)
 *   ux:prompt awareness --flow=chat_promise --action="promise an item to someone in a chat"
 *   ux:prompt awareness --flow=give --action="pay to have your offer shown at the top of the list" --decoy --reveal="No. Freegle is free, and nobody can pay to have their offer shown first."
 *       (asks "Do you know how to <action>?" and shows nothing afterwards: it is a question, not a tip. A decoy asks about something Freegle does not do, so its right answer is no, and its --reveal says so; make about one in five a decoy. Otherwise --reveal and --link are optional.)
 *   ux:prompt open --preset=problems|values|improve   (a free-text question, at most 300 answers)
 *   ux:prompt analyse <id>                (code an open prompt's answers into problems, values and suggestions)
 *   ux:prompt followup <id>               (ask other members which of the top 8 problems found in prompt <id> is the biggest problem for them, four at a time; needs 100 analysed answers, lists the titles, then needs --confirm)
 *   ux:prompt close <id>
 */
#[AsCommand(name: 'ux:prompt')]
class PromptCommand extends Command
{
    protected $signature = 'ux:prompt
        {action : list, survey, awareness, open, analyse, followup or close}
        {id? : close, analyse, followup: the prompt id}
        {--preset= : open: problems, values or improve}
        {--confirm : followup: the titles have been read and none names a person or place}
        {--force : analyse: replace the themes of a prompt that was analysed before}
        {--flow= : awareness: the funnel or feature the question is about}
        {--action= : awareness: what is asked about, in plain words, such as "promise an item to someone in a chat"}
        {--reveal= : awareness: one or two sentences shown after the answer (optional; required with --decoy)}
        {--link= : awareness: a path on this site shown after the answer, such as /settings}
        {--decoy : awareness: the question is about something Freegle does not do (the right answer is no)}
        {--max= : how many answers to collect before the prompt closes itself (default 200; an open prompt defaults to and is capped at 300)}';

    protected $description = 'List, open, analyse or close the survey, awareness and open questions microvolunteers are asked';

    public function handle(UxPromptService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'survey' => $this->opened($service->openSurvey($this->max(200))),
                'awareness' => $this->opened($service->openAwareness(
                    (string) $this->option('flow'), (string) $this->option('action'), $this->option('reveal') ?: null,
                    $this->option('link') ?: null, (bool) $this->option('decoy'), $this->max(200))),
                'open' => $this->opened($service->openOpen((string) $this->option('preset'), $this->max(UxPromptService::OPEN_MAX))),
                'analyse' => $this->analyse($service),
                'followup' => $this->opened($service->followup($this->needId(), $this->max(200), (bool) $this->option('confirm'))),
                'close' => $this->close($service),
                default => $this->bad('action must be list, survey, awareness, open, analyse, followup or close'),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(): int
    {
        $rows = DB::table('ux_prompts')->orderByDesc('id')->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('No prompts.');

            return self::SUCCESS;
        }
        $this->table(['id', 'kind', 'flow', 'status', 'answers', 'question'], $rows->map(fn ($r) => [
            $r->id, $r->kind, $r->flow ?? '', $r->status,
            DB::table('ux_prompt_responses')->where('prompt_id', $r->id)->count(),
            mb_substr($r->question, 0, 60),
        ])->all());

        return self::SUCCESS;
    }

    private function max(int $default): int
    {
        return $this->option('max') !== null && $this->option('max') !== '' ? (int) $this->option('max') : $default;
    }

    private function needId(): int
    {
        $id = (int) $this->argument('id');
        if ($id <= 0) {
            throw new \RuntimeException('Give the prompt id.');
        }

        return $id;
    }

    private function analyse(UxPromptService $service): int
    {
        $c = $service->analyse($this->needId(), (bool) $this->option('force'));
        $this->info(sprintf('Analysed %d answers: %d problems, %d values, %d suggestions, %d unclear.',
            $c['answers'], $c['problems'], $c['values'], $c['solutions'], $c['unclear']));

        return self::SUCCESS;
    }

    private function opened(int $id): int
    {
        $this->info("Opened prompt $id.");

        return self::SUCCESS;
    }

    private function close(UxPromptService $service): int
    {
        $id = $this->needId();
        $service->close($id);
        $this->info("Closed prompt $id.");

        return self::SUCCESS;
    }

    private function bad(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
