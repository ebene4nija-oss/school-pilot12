<?php

namespace Tests\Feature;

use App\Exceptions\TenantNotEstablished;
use App\Models\School;
use App\Models\SchoolClass;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    private School $a;

    private School $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = School::create(['name' => 'A School', 'slug' => 'a', 'subdomain' => 'a']);
        $this->b = School::create(['name' => 'B School', 'slug' => 'b', 'subdomain' => 'b']);
        SchoolClass::create(['school_id' => $this->a->id, 'name' => 'JSS 1']);
        SchoolClass::create(['school_id' => $this->b->id, 'name' => 'JSS 1']);
    }

    public function test_a_query_with_no_school_established_is_refused(): void
    {
        $this->expectException(TenantNotEstablished::class);

        SchoolClass::count();
    }

    public function test_for_school_sees_only_that_school(): void
    {
        $this->assertSame(1, $this->inSchool($this->a->id, fn () => SchoolClass::count()));
    }

    public function test_cross_tenant_work_must_say_so(): void
    {
        $this->assertSame(2, $this->acrossSchools(fn () => SchoolClass::count()));
        $this->assertSame(2, SchoolClass::allTenants()->count());
    }

    public function test_the_context_does_not_leak_after_a_job_finishes(): void
    {
        $this->inSchool($this->a->id, fn () => SchoolClass::count());

        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->expectException(TenantNotEstablished::class);

        SchoolClass::count();
    }
}
