<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automated UX experiment framework: experiments per funnel, their hourly reads, the ideas
 * backlog per funnel, and the prompts microvolunteers are asked (about an experiment, or a survey
 * of what works best and worst). docs/developers/reference/ux-telemetry.md section 8.
 *
 * Everything an experiment or a prompt needs is a row here, so a new experiment or survey never
 * needs a schema change. Nothing alters an existing table: microvolunteer answers go in
 * ux_prompt_responses, not microactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_experiments')) {
            Schema::create('ux_experiments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32);
                // The switch key the client reads: useExperiment('<name>').
                $t->string('name', 64)->unique('ux_experiments_name');
                $t->string('title', 160);
                $t->text('hypothesis');
                $t->text('change_description');
                $t->text('expected');
                $t->json('arms');
                $t->string('status', 16)->default('draft');
                $t->timestamp('started_at')->nullable();
                $t->timestamp('ends_at')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision')->nullable();
                $t->json('result')->nullable();
                $t->string('pr_url', 255)->nullable();
                $t->string('cleanup_pr_url', 255)->nullable();
                $t->string('image_control', 255)->nullable();
                $t->string('image_variant', 255)->nullable();
                $t->unsignedBigInteger('idea_id')->nullable();
                // What the change can touch (the flows its guardrails read), the expected effect and the
                // power computed at start. See UxExperimentService::start().
                $t->json('design')->nullable();
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_experiments_flow_status');
            });
        }

        if (! Schema::hasTable('ux_experiment_reads')) {
            Schema::create('ux_experiment_reads', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('experiment_id');
                $t->timestamp('read_at');
                $t->decimal('hours', 6, 2);
                $t->json('metrics');
                $t->text('summary');
                $t->index(['experiment_id', 'read_at'], 'ux_experiment_reads_lookup');
            });
        }

        if (! Schema::hasTable('ux_ideas')) {
            Schema::create('ux_ideas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32);
                $t->string('title', 160);
                $t->text('rationale');
                $t->text('expected');
                $t->json('evidence')->nullable();
                $t->decimal('score', 6, 2)->default(0);
                $t->string('status', 16)->default('open');
                $t->unsignedBigInteger('experiment_id')->nullable();
                $t->string('source', 16)->default('ai');
                // The member problem this idea answers (ux_problems.id), if any.
                $t->unsignedBigInteger('problem_id')->nullable();
                // How many tests of this idea ended inconclusive. After two it is done.
                $t->unsignedInteger('attempts')->default(0);
                // 'fix' (obviously right, just do it) or 'experiment' (needs a test).
                $t->string('type', 16)->default('experiment');
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_ideas_flow_status');
                $t->index('problem_id', 'ux_ideas_problem');
            });
        }

        if (! Schema::hasTable('ux_prompts')) {
            Schema::create('ux_prompts', function (Blueprint $t) {
                $t->bigIncrements('id');
                // 'experiment' (would this be better or worse for you) or 'survey' (best / worst).
                $t->string('kind', 16);
                $t->string('flow', 32)->nullable();
                $t->unsignedBigInteger('experiment_id')->nullable();
                $t->string('question', 255);
                $t->text('body');
                $t->json('options');
                $t->json('images')->nullable();
                $t->string('status', 16)->default('open');
                $t->unsignedInteger('max_responses')->default(200);
                // best_label, worst_label, randomise, preset.
                $t->json('settings')->nullable();
                $t->timestamps();
                $t->index(['status', 'kind'], 'ux_prompts_status_kind');
            });
        }

        if (! Schema::hasTable('ux_problems')) {
            // What members say gets in their way, or what they value. Problems and solutions are kept
            // apart: a problem is stated by members, a solution is an idea (ux_ideas) that answers it.
            Schema::create('ux_problems', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32)->nullable();
                // 'problem' or 'value'.
                $t->string('kind', 16)->default('problem');
                $t->string('title', 160);
                $t->text('description')->nullable();
                // 'members', 'ai', 'reports' or 'figures'.
                $t->string('source', 16)->default('members');
                $t->unsignedBigInteger('prompt_id')->nullable();
                // Distinct answers that raised it.
                $t->unsignedInteger('mentions')->default(0);
                // (most - least) / answers from a follow-up ranking survey.
                $t->decimal('weight', 6, 3)->nullable();
                $t->json('evidence')->nullable();
                $t->string('status', 16)->default('open');
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_problems_flow_status');
                $t->index('prompt_id', 'ux_problems_prompt');
            });
        }

        if (! Schema::hasTable('ux_prompt_responses')) {
            Schema::create('ux_prompt_responses', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('prompt_id');
                $t->unsignedBigInteger('userid');
                $t->json('answer');
                $t->text('comment')->nullable();
                $t->timestamp('timestamp')->useCurrent();
                $t->unique(['prompt_id', 'userid'], 'ux_prompt_responses_once');
                $t->index(['userid', 'timestamp'], 'ux_prompt_responses_user_time');
            });
        }
    }

    public function down(): void
    {
        foreach (['ux_problems', 'ux_prompt_responses', 'ux_prompts', 'ux_ideas', 'ux_experiment_reads', 'ux_experiments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
