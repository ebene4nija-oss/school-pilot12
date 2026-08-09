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
                '/finance/fee-structure' => [
                    'get' => [
                        'summary' => 'List fee structures for a term, with per-class totals payable',
                        'parameters' => [
                            ['name' => 'term_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Fee structures, per-class totals, and the term/class lists the builder form needs'],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Define a fee (school-wide when class_id is omitted)',
                        'responses' => [
                            '201' => ['description' => 'Fee structure created'],
                            '422' => ['description' => 'Validation failed'],
                        ],
                    ],
                ],
                '/finance/fee-structure/{id}' => [
                    'put' => [
                        'summary' => 'Correct a fee. Invoices already issued keep the amount they were raised with',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Fee updated'],
                        ],
                    ],
                    'delete' => [
                        'summary' => 'Retire a fee. Lines already billed from it remain on those invoices',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Fee removed'],
                        ],
                    ],
                ],
                '/finance/invoices/generate' => [
                    'post' => [
                        'summary' => 'Raise a term\'s invoices from its fee structures. Idempotent — safe to re-run',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['term_id'],
                                        'properties' => [
                                            'term_id' => ['type' => 'integer'],
                                            'class_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Restrict to one class'],
                                            'due_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Summary of invoices created, updated and lines added'],
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
