<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admins', 'editprotected')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('editprotected')->default(false)->after('essential');
            $table->string('template', 50)->nullable()->after('editprotected');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['editprotected', 'template']);
        });
    }
};
