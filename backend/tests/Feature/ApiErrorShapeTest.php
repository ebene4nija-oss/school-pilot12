<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiErrorShapeTest extends TestCase
{
    public function test_unknown_api_route_answers_json_even_without_accept_header(): void
    {
        $response = $this->call('GET', '/api/v1/definitely-not-a-route');

        $response->assertNotFound();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_unauthenticated_api_call_is_json_401(): void
    {
        $response = $this->call('GET', '/api/v1/students');

        $response->assertUnauthorized();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }
}
