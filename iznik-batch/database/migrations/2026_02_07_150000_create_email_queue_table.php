<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the email_queue table for the V2 API email queue system.
 *
 * Go v2 API handlers insert rows into this table when they need to trigger
 * emails (e.g., forgot password, verify email, welcome). The Laravel batch
 * processor reads pending rows and dispatches the appropriate Mailable via
 * the file-based spooler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_queue', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email_type', 50)->comment('Mailable class identifier, e.g. forgot_password, verify_email');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Target user');
            $table->unsignedBigInteger('group_id')->nullable()->comment('Related group (for welcome, modmail)');
            $table->unsignedBigInteger('message_id')->nullable()->comment('Related message');
            $table->unsignedBigInteger('chat_id')->nullable()->comment('Related chat room');
            $table->json('extra_data')->nullable()->comment('Additional data as JSON (email, subject, body, etc.)');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable()->comment('When successfully processed');
            $table->timestamp('failed_at')->nullable()->comment('When processing failed permanently');
            $table->text('error_message')->nullable()->comment('Error details on failure');

            $table->index(['processed_at', 'created_at'], 'idx_pending');
            $table->index('email_type', 'idx_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_queue');
    }
};
