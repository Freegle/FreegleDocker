<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxPromptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The questions microvolunteers are asked that are not about one experiment: a "best / worst"
 * survey of Freegle's areas, and awareness questions ("did you know you can promise an item in
 * chat?"). A prompt is a row, so a new question needs no code or schema change.
 *
 *   ux:prompt list
 *   ux:prompt survey                      (open the standard best / worst survey over the funnels)
 *   ux:prompt awareness --flow=chat_promise --question="Did you know you can promise an item to someone in a chat?" --body="..."
 *   ux:prompt close <id>
 */
#[AsCommand(name: 'ux:prompt')]
class PromptCommand extends Command
{
    protected $signature = 'ux:prompt
        {action : list, survey, awareness or close}
        {id? : close: the prompt id}
        {--flow= : awareness: the funnel or feature the question is about}
        {--question= : awareness: the question}
        {--body= : awareness: a sentence or two of plain English under the question}
        {--image= : awareness: a picture of the feature}
        {--max=200 : how many answers to collect before the prompt closes itself}';

    protected $description = 'List, open or close the survey and awareness questions microvolunteers are asked';

    public function handle(UxPromptService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'survey' => $this->opened($service->openSurvey((int) $this->option('max'))),
                'awareness' => $this->opened($service->openAwareness(
                    (string) $this->option('flow'), (string) $this->option('question'), (string) $this->option('body'),
                    $this->option('image') ?: null, (int) $this->option('max'))),
                'close' => $this->close($service),
                default => $this->bad('action must be list, survey, awareness or close'),
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

    private function opened(int $id): int
    {
        $this->info("Opened prompt $id.");

        return self::SUCCESS;
    }

    private function close(UxPromptService $service): int
    {
        $id = (int) $this->argument('id');
        if ($id <= 0) {
            throw new \RuntimeException('Give the prompt id.');
        }
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
