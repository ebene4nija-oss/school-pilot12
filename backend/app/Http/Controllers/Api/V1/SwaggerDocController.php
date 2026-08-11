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
                '/auth/logout' => [
                    'post' => [
                        'summary' => 'Revoke the access token that made this request',
                        'description' => 'Only the calling token is deleted, so signing out of one device leaves the user\'s other sessions alive.',
                        'responses' => [
                            '200' => ['description' => 'Signed out'],
                            '401' => ['description' => 'No valid token supplied'],
                        ],
                    ],
                ],
                '/auth/forgot-password' => [
                    'post' => [
                        'summary' => 'Send a password-reset link to a registered address',
                        'description' => 'Always answers 200 with the same message, whether or not the address exists and whether or not a link was sent. Anything else would let a stranger test which addresses hold accounts at a school. Rate limited to 3/min per address and 10/min per IP.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['email'],
                                        'properties' => [
                                            'email' => ['type' => 'string', 'format' => 'email'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Request accepted (says nothing about whether the account exists)'],
                            '422' => ['description' => 'Not a valid email address'],
                            '429' => ['description' => 'Rate limited'],
                        ],
                    ],
                ],
                '/auth/reset-password' => [
                    'post' => [
                        'summary' => 'Redeem a reset or account-setup link',
                        'description' => 'Consumes the single-use token, sets the new password and revokes every existing session for that user. Tokens expire after auth.passwords.users.expire minutes (60 by default).',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['email', 'token', 'password', 'password_confirmation'],
                                        'properties' => [
                                            'email' => ['type' => 'string', 'format' => 'email'],
                                            'token' => ['type' => 'string'],
                                            'password' => ['type' => 'string', 'description' => 'Min 8 characters, with letters and numbers'],
                                            'password_confirmation' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Password updated; all sessions revoked'],
                            '422' => ['description' => 'Invalid or expired link, or the password failed the strength rules'],
                            '429' => ['description' => 'Rate limited'],
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
                '/finance/defaulters/remind' => [
                    'post' => [
                        'summary' => 'Queue fee reminders to the guardians behind outstanding invoices',
                        'description' => 'Grouped by recipient — a parent with several children owing gets one message. Channels are required, never defaulted, because SMS is billed per message.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['channels'],
                                        'properties' => [
                                            'channels' => [
                                                'type' => 'array',
                                                'items' => ['type' => 'string', 'enum' => ['push', 'sms', 'whatsapp']],
                                            ],
                                            'invoice_ids' => [
                                                'type' => 'array',
                                                'items' => ['type' => 'integer'],
                                                'description' => 'Specific invoices; omit to sweep the filters below',
                                            ],
                                            'term_id' => ['type' => 'integer', 'nullable' => true],
                                            'class_id' => ['type' => 'integer', 'nullable' => true],
                                            'min_balance' => ['type' => 'number', 'nullable' => true],
                                            'note' => ['type' => 'string', 'nullable' => true, 'maxLength' => 300],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '202' => ['description' => 'Reminders queued, with the families that could not be reached'],
                            '422' => ['description' => 'No outstanding invoices matched, or no channel chosen'],
                        ],
                    ],
                ],
                '/finance/payments' => [
                    'post' => [
                        'summary' => 'Record student fee payment and trigger automated WhatsApp receipt',
                        'description' => 'For a payment already taken — cash, or a bank transfer awaiting reconciliation. To take a payment online use /finance/payments/initialize instead. `reference` is optional and server-minted; it is honoured only from an admin recording a manual payment, where it carries a real-world slip number.',
                        'responses' => [
                            '200' => ['description' => 'Payment recorded and receipt sent'],
                        ],
                    ],
                ],
                '/finance/payments/initialize' => [
                    'post' => [
                        'summary' => "Open a hosted checkout on the school's own gateway account",
                        'description' => 'Returns an `authorization_url` to open in a browser or webview. The client never holds a gateway key and never mints a reference; the payment stays `pending` until the signed gateway callback settles it. Omit `amount` to pay the whole outstanding balance.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['invoice_id'],
                                        'properties' => [
                                            'invoice_id' => ['type' => 'integer'],
                                            'gateway' => ['type' => 'string', 'nullable' => true, 'enum' => ['paystack', 'flutterwave'], 'description' => 'Usually omit. A payer does not know which merchant account their school holds, so the server picks the one it connected.'],
                                            'amount' => ['type' => 'number', 'nullable' => true, 'description' => 'Part-payment in naira. Defaults to the full balance and may not exceed it.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Checkout ready; open authorization_url'],
                            '403' => ['description' => 'Not this caller\'s child'],
                            '409' => ['description' => 'Invoice already settled, or the school has not connected this gateway'],
                            '502' => ['description' => 'The school\'s gateway refused or could not be reached'],
                        ],
                    ],
                ],
                '/finance/gateways' => [
                    'get' => [
                        'summary' => "Which gateways the school has connected, and the webhook URL to paste into its dashboard",
                        'description' => 'school_admin only. Keys are write-only across this API: only a masked hint of the secret is ever returned.',
                        'responses' => [
                            '200' => ['description' => 'One entry per supported gateway, connected or not'],
                        ],
                    ],
                ],
                '/finance/gateways/{gateway}' => [
                    'put' => [
                        'summary' => "Connect or re-key the school's own merchant account",
                        'description' => 'school_admin only — deliberately not available to platform staff. Keys are stored encrypted and never returned. Flutterwave additionally requires the secret hash from the school\'s dashboard, without which no callback from it can be verified.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['secret_key'],
                                        'properties' => [
                                            'secret_key' => ['type' => 'string', 'description' => 'sk_test_… / sk_live_… (Paystack) or FLWSECK… (Flutterwave)'],
                                            'public_key' => ['type' => 'string', 'nullable' => true],
                                            'webhook_secret' => ['type' => 'string', 'nullable' => true, 'description' => 'Required for Flutterwave: the secret hash set in its dashboard.'],
                                            'is_active' => ['type' => 'boolean', 'nullable' => true],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Connected; paste the returned webhook_url into the gateway dashboard'],
                            '422' => ['description' => 'The key does not look like one this gateway issues'],
                        ],
                    ],
                    'delete' => [
                        'summary' => 'Disconnect the account and delete the stored keys',
                        'responses' => [
                            '200' => ['description' => 'Disconnected; online payment through it stops immediately'],
                            '404' => ['description' => 'That gateway is not connected'],
                        ],
                    ],
                ],
                '/payments/return' => [
                    'get' => [
                        'summary' => 'Where a gateway sends the payer when checkout ends',
                        'description' => 'Unauthenticated, and says nothing about whether the payment succeeded — it cannot verify one. It exists so a mobile webview has a URL it can recognise as "checkout is over" and close on; host sniffing cannot do that job because bank 3-D Secure steps route through arbitrary domains mid-payment. What happened to the money is decided by the signed webhook and read back from the statement.',
                        'responses' => [
                            '200' => ['description' => 'A static "checkout complete, confirming" page'],
                        ],
                    ],
                ],
                '/notifications/devices' => [
                    'post' => [
                        'summary' => 'Register this handset\'s FCM token for push',
                        'description' => 'Call on login and on every token refresh. Upserted on (user, token), so a reinstall does not double-send.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['token', 'platform'],
                                        'properties' => [
                                            'token' => ['type' => 'string', 'maxLength' => 512, 'description' => 'FCM registration token'],
                                            'platform' => ['type' => 'string', 'enum' => ['android', 'ios', 'web']],
                                            'device_name' => ['type' => 'string', 'nullable' => true, 'maxLength' => 120],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Device registered'],
                        ],
                    ],
                    'delete' => [
                        'summary' => 'Unregister this handset on sign-out',
                        'responses' => [
                            '200' => ['description' => 'Device unregistered'],
                        ],
                    ],
                ],
                '/notifications/devices/test' => [
                    'post' => [
                        'summary' => 'Send a test push to your own devices and report per-device delivery',
                        'description' => 'Client self-diagnostic. Sends immediately rather than queueing, throttled to 10/minute, and never written to the school notification log.',
                        'responses' => [
                            '200' => ['description' => 'At least one device accepted the message'],
                            '422' => ['description' => 'No devices registered for the caller'],
                            '502' => ['description' => 'Every device failed; see the per-device reason'],
                            '503' => ['description' => 'Push is not configured on this deployment'],
                        ],
                    ],
                ],
                '/parent/children' => [
                    'get' => [
                        'summary' => "The caller's own children",
                        'description' => 'The first request a signed-in parent makes: every other parent route takes a student id, and this is the only place to learn one. Scoped to the caller\'s `student_guardian` rows, not to school membership. A parent with no guardian record gets an empty list and a message rather than a 403.',
                        'responses' => [
                            '200' => ['description' => 'Linked children, possibly empty'],
                        ],
                    ],
                ],
                '/classes' => [
                    'get' => [
                        'summary' => "The school's classes, in teaching order, with arms and roll counts",
                        'description' => 'Ordered by `order_index` so JSS 1 sorts before SS 1. `student_count` counts active students only. Any authenticated role.',
                        'responses' => [
                            '200' => ['description' => 'Classes with their arms'],
                        ],
                    ],
                ],
                '/terms' => [
                    'get' => [
                        'summary' => "The school's terms, newest first, with the current one resolved",
                        'description' => 'Read `current_term_id` rather than scanning `data[].is_current` — it is resolved once, server-side, from the term date ranges. It is null only when the school has defined no terms.',
                        'responses' => [
                            '200' => ['description' => 'Terms, `current_term_id` and `current_session`'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
