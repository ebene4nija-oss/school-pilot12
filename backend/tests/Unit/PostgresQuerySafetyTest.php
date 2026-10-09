<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Query shapes Postgres refuses but SQLite — what the test suite runs on —
 * quietly accepts, found by reading the code because no test run can.
 *
 * `lockForUpdate()->max(...)` compiles to "select max(...) ... for update",
 * which Postgres rejects outright (FOR UPDATE is not allowed with aggregate
 * functions). SQLite drops the lock, so every test passed while every CBT
 * submit on production returned a 500.
 */
class PostgresQuerySafetyTest extends TestCase
{
    public function test_no_row_lock_is_combined_with_an_aggregate()
    {
        $offenders = [];
        $app = dirname(__DIR__, 2).'/app';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (preg_match('/(lockForUpdate|sharedLock)\(\)\s*->\s*(max|min|sum|avg|count)\(/', $code)) {
                $offenders[] = str_replace($app, 'app', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Lock the parent row instead: Postgres refuses FOR UPDATE with an aggregate.');
    }
}
