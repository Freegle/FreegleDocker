<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages_attachments', function (Blueprint $table) {
            $table->foreign(['msgid'], '_messages_attachments_ibfk_1')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_attachments', function (Blueprint $table) {
            $table->dropForeign('_messages_attachments_ibfk_1');
        });
    }
};
