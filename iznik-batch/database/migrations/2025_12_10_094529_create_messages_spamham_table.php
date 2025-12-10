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
        Schema::create('messages_spamham', function (Blueprint $table) {
            $table->comment('User feedback on messages ');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->enum('spamham', ['Spam', 'Ham']);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_spamham');
    }
};
