<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chat_prompts.userid and chat_prompts.heldchatmsgid - a prompt asked of one member, about a
 * reply that waits for the answer.
 *
 * The 'confirm_reply' prompt is asked in a chat between two members, but only of the
 * replier: "this looks damaged / needs a car - still want it?". `userid` says who was asked,
 * so only they see it and only they can answer it. NULL keeps the existing meaning: a prompt
 * in a member's own chat with Freegle, answerable by that member.
 *
 * `heldchatmsgid` is the reply the answer decides. chats:process-incoming leaves it
 * unprocessed - so the poster neither sees it nor hears about it - until the prompt is
 * answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_prompts', 'userid')) {
            Schema::table('chat_prompts', function (Blueprint $t) {
                $t->unsignedBigInteger('userid')->nullable()->after('msgids');
                $t->unsignedBigInteger('heldchatmsgid')->nullable()->after('userid')
                    ->index('chat_prompts_heldchatmsgid');
            });

            DB::statement(
                'ALTER TABLE chat_prompts
                    ADD CONSTRAINT chat_prompts_userid_foreign
                    FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE'
            );

            DB::statement(
                'ALTER TABLE chat_prompts
                    ADD CONSTRAINT chat_prompts_heldchatmsgid_foreign
                    FOREIGN KEY (heldchatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_prompts', 'userid')) {
            Schema::table('chat_prompts', function (Blueprint $t) {
                $t->dropForeign('chat_prompts_userid_foreign');
                $t->dropForeign('chat_prompts_heldchatmsgid_foreign');
                $t->dropIndex('chat_prompts_heldchatmsgid');
                $t->dropColumn(['userid', 'heldchatmsgid']);
            });
        }
    }
};
