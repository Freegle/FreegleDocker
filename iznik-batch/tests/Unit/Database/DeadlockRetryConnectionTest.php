<?php

namespace Tests\Unit\Database;

use App\Database\DeadlockRetryConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockRetryConnectionTest extends TestCase
{
    public function test_connection_is_deadlock_retry_instance(): void
    {
        // Verify the service provider registered our custom connection.
        $connection = DB::connection();
        $this->assertInstanceOf(DeadlockRetryConnection::class, $connection);
    }

    public function test_normal_queries_work(): void
    {
        // Basic sanity: the custom connection doesn't break normal queries.
        $result = DB::selectOne('SELECT 1 AS val');
        $this->assertEquals(1, $result->val);
    }

    public function test_normal_insert_update_delete_work(): void
    {
        DB::statement('CREATE TEMPORARY TABLE test_deadlock_conn (id INT PRIMARY KEY, val VARCHAR(50))');

        DB::insert('INSERT INTO test_deadlock_conn VALUES (1, ?)', ['hello']);
        $row = DB::selectOne('SELECT val FROM test_deadlock_conn WHERE id = 1');
        $this->assertEquals('hello', $row->val);

        DB::update('UPDATE test_deadlock_conn SET val = ? WHERE id = 1', ['world']);
        $row = DB::selectOne('SELECT val FROM test_deadlock_conn WHERE id = 1');
        $this->assertEquals('world', $row->val);

        DB::delete('DELETE FROM test_deadlock_conn WHERE id = 1');
        $count = DB::selectOne('SELECT COUNT(*) AS cnt FROM test_deadlock_conn');
        $this->assertEquals(0, $count->cnt);

        DB::statement('DROP TEMPORARY TABLE test_deadlock_conn');
    }

    public function test_transactions_still_work(): void
    {
        DB::statement('CREATE TEMPORARY TABLE test_deadlock_txn (id INT PRIMARY KEY)');

        // Test transaction nesting (test framework already wraps in one).
        $baseTxnLevel = DB::transactionLevel();

        DB::beginTransaction();
        $this->assertEquals($baseTxnLevel + 1, DB::transactionLevel());
        DB::insert('INSERT INTO test_deadlock_txn VALUES (1)');
        DB::rollBack();

        $count = DB::selectOne('SELECT COUNT(*) AS cnt FROM test_deadlock_txn');
        $this->assertEquals(0, $count->cnt);

        DB::statement('DROP TEMPORARY TABLE test_deadlock_txn');
    }

    public function test_non_deadlock_errors_propagate_immediately(): void
    {
        // Syntax errors or missing tables should throw immediately, not retry.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('SELECT * FROM nonexistent_table_deadlock_test_xyz');
    }
}
