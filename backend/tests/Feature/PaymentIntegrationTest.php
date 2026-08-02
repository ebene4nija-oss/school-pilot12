<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_endpoint(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertStatus(200)
                 ->assertJson(['status' => 'ok']);
    }

    public function test_unauthenticated_user_cannot_access_finance_endpoints(): void
    {
        $response = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => 1,
            'amount' => 500,
            'gateway' => 'bank_transfer',
            'reference' => 'REF_TEST_123',
        ]);

        $response->assertStatus(401);
    }
}
