<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class SwaggerDocController extends Controller
{
    /**
     * Generate interactive OpenAPI 3.0 JSON specification for frontends
     */
    public function getSpec()
    {
        return response()->json([
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'SchoolPilot API Specification',
                'version' => '1.0.0',
                'description' => 'Multi-tenant cloud school management system for private K-12 day schools in Nigeria.',
            ],
            'servers' => [
                ['url' => 'https://{school}.schoolpilot.test/api/v1', 'description' => 'Tenant Subdomain Server'],
            ],
            'paths' => [
                '/auth/login' => [
                    'post' => [
                        'summary' => 'Authenticate user and return Sanctum token',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'email' => ['type' => 'string'],
                                            'password' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Authentication successful'],
                        ],
                    ],
                ],
                '/verify-result/{token}' => [
                    'get' => [
                        'summary' => 'Public QR report card result verification (rate-limited)',
                        'parameters' => [
                            ['name' => 'token', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Verification status'],
                        ],
                    ],
                ],
                '/finance/payments' => [
                    'post' => [
                        'summary' => 'Record student fee payment and trigger automated WhatsApp receipt',
                        'responses' => [
                            '200' => ['description' => 'Payment recorded and receipt sent'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
