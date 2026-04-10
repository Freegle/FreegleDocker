<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Multiple columns in messages_history were latin1_swedish_ci (legacy schema)
     * but the table default is utf8mb4_unicode_ci. This causes "Conversion from
     * collation utf8mb4_unicode_ci into latin1_swedish_ci impossible for parameter"
     * errors when LIKE queries contain non-ASCII characters (e.g. Swedish å, é).
     */
    public function up(): void
    {
        $columns = [
            'source' => 'enum',
            'fromip' => 'VARCHAR(40)',
            'fromhost' => 'VARCHAR(80)',
            'envelopefrom' => 'VARCHAR(255)',
            'fromname' => 'VARCHAR(255)',
            'fromaddr' => 'VARCHAR(255)',
            'envelopeto' => 'VARCHAR(255)',
            'subject' => 'VARCHAR(1024)',
            'prunedsubject' => 'VARCHAR(1024)',
            'messageid' => 'VARCHAR(255)',
        ];

        foreach ($columns as $col => $type) {
            if ($col === 'source') {
                DB::statement("
                    ALTER TABLE messages_history
                    MODIFY COLUMN source ENUM('Yahoo Approved','Yahoo Pending','Yahoo System','Platform')
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                    NULL DEFAULT NULL
                ");
            } else {
                DB::statement("
                    ALTER TABLE messages_history
                    MODIFY COLUMN {$col} {$type}
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                    NULL DEFAULT NULL
                ");
            }
        }
    }

    public function down(): void
    {
        $columns = [
            'source' => 'enum',
            'fromip' => 'VARCHAR(40)',
            'fromhost' => 'VARCHAR(80)',
            'envelopefrom' => 'VARCHAR(255)',
            'fromname' => 'VARCHAR(255)',
            'fromaddr' => 'VARCHAR(255)',
            'envelopeto' => 'VARCHAR(255)',
            'subject' => 'VARCHAR(1024)',
            'prunedsubject' => 'VARCHAR(1024)',
            'messageid' => 'VARCHAR(255)',
        ];

        foreach ($columns as $col => $type) {
            if ($col === 'source') {
                DB::statement("
                    ALTER TABLE messages_history
                    MODIFY COLUMN source ENUM('Yahoo Approved','Yahoo Pending','Yahoo System','Platform')
                    CHARACTER SET latin1 COLLATE latin1_swedish_ci
                    NULL DEFAULT NULL
                ");
            } else {
                DB::statement("
                    ALTER TABLE messages_history
                    MODIFY COLUMN {$col} {$type}
                    CHARACTER SET latin1 COLLATE latin1_swedish_ci
                    NULL DEFAULT NULL
                ");
            }
        }
    }
};
