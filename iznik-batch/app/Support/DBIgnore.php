<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Utility class to ignore DB errors to emulate MySQL's IGNORE keyword.
 * E.g. can be used instead of an "UPDATE IGNORE" statement.
 * @see https://dev.mysql.com/doc/refman/9.6/en/sql-mode.html#ignore-effect-on-execution
 */
class DBIgnore
{
    /**
     * Runs the callbacks with all QueryExceptions suppressed and reported as warnings.
     */
    public static function executeIgnored(array $callbacks): void {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (QueryException $e) {
                Log::warning("Query exception suppressed by executeIgnored: " . $e->getMessage());
            }
        }
    }
}
