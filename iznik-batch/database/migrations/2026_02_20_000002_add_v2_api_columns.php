<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns and enum values needed by Go V2 API handlers.
     */
    public function up(): void
    {
        // Add rsvp column to chat_messages
        if (Schema::hasTable('chat_messages') && !Schema::hasColumn('chat_messages', 'rsvp')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->enum('rsvp', ['Yes', 'No', 'Maybe'])->nullable()->after('deleted');
            });
        }

        // Add ReferToSupport to chat_messages type enum
        if (Schema::hasTable('chat_messages')) {
            DB::statement("ALTER TABLE chat_messages MODIFY COLUMN type ENUM('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated','Reminder','ReferToSupport') NOT NULL DEFAULT 'Default'");
        }

        // Add shown and clicked columns to alerts_tracking
        if (Schema::hasTable('alerts_tracking') && !Schema::hasColumn('alerts_tracking', 'shown')) {
            Schema::table('alerts_tracking', function (Blueprint $table) {
                $table->unsignedInteger('shown')->default(0)->after('response');
                $table->unsignedInteger('clicked')->default(0)->after('shown');
            });
        }

        // Add partnerconsent column to messages
        if (Schema::hasTable('messages') && !Schema::hasColumn('messages', 'partnerconsent')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->boolean('partnerconsent')->default(false)->after('deadline');
            });
        }

        // Add deletedby column to messages
        if (Schema::hasTable('messages') && !Schema::hasColumn('messages', 'deletedby')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->unsignedBigInteger('deletedby')->nullable()->after('deleted');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'rsvp')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('rsvp');
            });
        }

        if (Schema::hasTable('alerts_tracking')) {
            if (Schema::hasColumn('alerts_tracking', 'shown')) {
                Schema::table('alerts_tracking', function (Blueprint $table) {
                    $table->dropColumn(['shown', 'clicked']);
                });
            }
        }

        if (Schema::hasTable('messages')) {
            if (Schema::hasColumn('messages', 'partnerconsent')) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->dropColumn('partnerconsent');
                });
            }
            if (Schema::hasColumn('messages', 'deletedby')) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->dropColumn('deletedby');
                });
            }
        }
    }
};
