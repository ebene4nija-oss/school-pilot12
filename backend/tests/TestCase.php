<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run code the way a job for one school would: with that school established.
     * Needed when a test calls a service or model directly after a request has
     * finished, since the tenant no longer lingers once the request ends.
     */
    protected function inSchool(int $schoolId, callable $callback): mixed
    {
        return app(TenantContext::class)->forSchool($schoolId, $callback);
    }

    /** Run code the way a platform command would: across every school. */
    protected function acrossSchools(callable $callback): mixed
    {
        return app(TenantContext::class)->withoutScoping($callback);
    }
}
