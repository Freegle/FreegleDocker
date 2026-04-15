<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class EloquentUtils
{
    /**
     * Re-parent rows from $from to $to on $column, via Eloquent so events fire.
     * Loads in batches; re-queries each iteration so already-updated rows drop out.
     */
    public static function reparentRow(string $modelClass, string $column, int $from, int $to): void
    {
        $modelClass::where($column, $from)->get()->each(function ($row) use ($column, $to) {
            $row->$column = $to;
            $row->save();
        });
    }

    /**
     * Like reparentRow, but suppresses unique-constraint conflicts (UPDATE IGNORE equivalent).
     * @see https://dev.mysql.com/doc/refman/9.6/en/sql-mode.html#ignore-effect-on-execution
     */
    public static function reparentRowIgnore(string $modelClass, string $column, int $from, int $to): void
    {
        $modelClass::where($column, $from)->get()->each(function ($row) use ($modelClass, $column, $to) {
            try {
                $row->$column = $to;
                $row->save();
            } catch (QueryException $e) {
                Log::warning("Reparent conflict on {$modelClass}#{$row->getKey()} ({$column}): " . $e->getMessage());
            }
        });
    }
}
