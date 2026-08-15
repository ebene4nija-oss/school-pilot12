<?php

/**
 * Generate a sealed bundle fixture for the Rust relay's unseal test.
 *
 * This mirrors `CbtOfflineBundleService::seal()` deliberately and separately —
 * gzip, AES-256-GCM, and the plaintext header bound in as additional
 * authenticated data. The point is to prove the Rust side opens a bundle *PHP
 * actually produced*, rather than one Rust sealed for itself, which would test
 * nothing but that the relay agrees with itself.
 *
 * Run from this directory when the bundle format changes:
 *
 *     php make-sealed-bundle.php
 *
 * The key is committed alongside the ciphertext on purpose: this is a fixture
 * carrying invented questions about the Harmattan, not a school's paper.
 */

const CIPHER = 'aes-256-gcm';
const IV_BYTES = 12;
const TAG_BYTES = 16;
const FORMAT_VERSION = 1;

$key = str_repeat("\x2b", 32);      // fixed, so the fixture is reproducible
$iv = str_repeat("\x07", IV_BYTES); // likewise — never do this in production

$payload = [
    'format_version' => FORMAT_VERSION,
    'exam' => [
        'id' => 42,
        // A quotation mark and a slash, because the AAD and the payload are
        // both JSON and both have to survive characters a teacher will type.
        'title' => 'JSS2 Geography — "Weather & Climate" / Term 2',
        'instructions' => 'Answer all questions.',
        'content_format' => 'plain',
        'duration_minutes' => 45,
        'opens_at' => '2026-09-14T09:00:00+01:00',
        'closes_at' => '2026-09-14T11:00:00+01:00',
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'shuffle_within_group' => false,
        'questions_per_attempt' => null,
        'max_attempts' => 1,
        'negative_marking' => false,
        'pass_mark' => 40.0,
        'total_marks' => 10.0,
        'integrity_settings' => ['require_fullscreen' => true],
        'show_results_immediately' => false,
    ],
    'groups' => [
        [
            'group_id' => 101,
            'title' => 'Passage 2 — The Harmattan',
            'stimulus' => "The Harmattan is a dry, dusty wind...\nIt blows from the Sahara.",
            'content_format' => 'plain',
            'instructions' => 'Read the passage and answer questions 2 to 3.',
            'media' => [['asset_id' => 900, 'role' => 'stem', 'position' => 0]],
        ],
    ],
    'questions' => [
        [
            'question_id' => 201,
            'question_type' => 'multiple_choice',
            'content_format' => 'plain',
            'question' => 'Which instrument measures rainfall?',
            'topic' => 'Weather',
            'section' => 'A',
            'marks' => 4.0,
            'negative_marks' => 0.0,
            'group_id' => null,
            'group_sequence' => null,
            'options' => [
                ['key' => 'A', 'text' => 'Rain gauge', 'image_asset_id' => null],
                ['key' => 'B', 'text' => 'Barometer', 'image_asset_id' => null],
                ['key' => 'C', 'text' => 'Anemometer', 'image_asset_id' => null],
                ['key' => 'D', 'text' => 'Hygrometer', 'image_asset_id' => null],
            ],
            'media' => [],
            'interaction' => [],
            'answer_mode' => 'on_screen',
        ],
        [
            'question_id' => 202,
            'question_type' => 'multiple_choice',
            'content_format' => 'plain',
            'question' => 'From which desert does the Harmattan blow?',
            'topic' => 'Weather',
            'section' => 'B',
            'marks' => 3.0,
            'negative_marks' => 0.0,
            'group_id' => 101,
            'group_sequence' => 1,
            'options' => [
                ['key' => 'A', 'text' => 'Kalahari', 'image_asset_id' => null],
                ['key' => 'B', 'text' => 'Sahara', 'image_asset_id' => null],
            ],
            'media' => [],
            'interaction' => [],
            'answer_mode' => 'on_screen',
        ],
        [
            'question_id' => 203,
            'question_type' => 'theory',
            'content_format' => 'plain',
            'question' => 'Describe two effects of the Harmattan on farming.',
            'topic' => 'Weather',
            'section' => 'B',
            'marks' => 3.0,
            'negative_marks' => 0.0,
            'group_id' => 101,
            'group_sequence' => 2,
            'options' => [],
            'media' => [],
            // The candidate-facing half of answer_schema only. No `rubric`.
            'interaction' => [
                'response_format' => 'short_answer',
                'answer_mode' => 'on_screen',
                'max_words' => 120,
            ],
            'answer_mode' => 'on_screen',
        ],
    ],
    'roster' => [
        [
            'attempt_id' => 9001,
            'student_id' => 55,
            'candidate_name' => 'Adaeze Okonkwo',
            'admission_number' => 'SP/2026/0055',
            'attempt_number' => 1,
            'seed' => 7,
            'question_order' => [201, 202, 203],
            'server_deadline_at' => '2026-09-14T09:45:00+01:00',
            'relay_code' => 'K4M9PQR2',
        ],
        [
            'attempt_id' => 9002,
            'student_id' => 56,
            'candidate_name' => 'Bola Adeyemi',
            'admission_number' => 'SP/2026/0056',
            'attempt_number' => 1,
            'seed' => 13,
            'question_order' => [202, 203, 201],
            'server_deadline_at' => '2026-09-14T09:45:00+01:00',
            'relay_code' => 'T7WXY3Z5',
        ],
    ],
];

$header = [
    'bundle_id' => '11111111-2222-3333-4444-555555555555',
    'exam_id' => 42,
    'school_id' => 3,
    'format_version' => FORMAT_VERSION,
    'question_count' => 3,
    'attempt_count' => 2,
    'opens_at' => '2026-09-14T09:00:00+01:00',
    'closes_at' => '2026-09-14T11:00:00+01:00',
    'built_at' => '2026-09-13T16:20:00+01:00',
];

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$plaintext = gzencode(json_encode($payload, $flags), 6);
$aad = json_encode($header, $flags);
$tag = '';

$ciphertext = openssl_encrypt(
    $plaintext,
    CIPHER,
    $key,
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    $aad,
    TAG_BYTES
);

if ($ciphertext === false) {
    fwrite(STDERR, "encryption failed\n");
    exit(1);
}

// Shaped exactly like CbtOfflineBundleController::store's 201 response, because
// that is the string the relay actually parses.
$response = [
    'message' => 'Bundle issued. The key is released separately, once the exam opens.',
    'bundle' => [
        'bundle_id' => $header['bundle_id'],
        'key_id' => '99999999-8888-7777-6666-555555555555',
        'format_version' => FORMAT_VERSION,
        'built_at' => $header['built_at'],
        'header' => $header,
        'cipher' => [
            'algorithm' => CIPHER,
            'compression' => 'gzip',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ],
    ],
    'media_manifest' => [
        'asset_count' => 1,
        'total_bytes' => 20480,
        'assets' => [[
            'asset_id' => 900,
            'url' => 'https://example.test/api/v1/cbt/media/900',
            'checksum' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'byte_size' => 20480,
            'mime_type' => 'image/png',
            'width' => 640,
            'height' => 480,
            'alt_text' => 'Map of prevailing winds',
            'caption' => null,
        ]],
    ],
];

// Compact, not pretty-printed: this is what Laravel actually puts on the wire,
// and the relay slices the raw `header` substring out of exactly these bytes to
// use as the AAD. Pretty-printing here would produce a fixture that passes for
// the wrong reason — see the pretty-printed variant in the Rust test, which
// exercises the fallback path on purpose.
file_put_contents(
    __DIR__ . '/sealed-bundle.json',
    json_encode($response, $flags) . "\n"
);

file_put_contents(
    __DIR__ . '/sealed-bundle.key',
    base64_encode($key) . "\n"
);

echo "Wrote sealed-bundle.json (" . strlen($ciphertext) . " ciphertext bytes) and sealed-bundle.key\n";
