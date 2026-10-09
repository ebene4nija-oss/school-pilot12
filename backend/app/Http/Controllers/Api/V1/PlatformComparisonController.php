<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The "SchoolPilot compared" table on the marketing site, edited as one
 * document: the rows being compared, SchoolPilot's value for each, and each
 * competitor's value. Competitor claims are only as good as their source, so a
 * competitor cannot be published without a "last verified" date; the site shows
 * "as of <date>" beside it. Nothing is seeded: the page stays hidden until the
 * operator publishes a competitor with real, checked values.
 */
class PlatformComparisonController extends Controller
{
    private const EMPTY = ['rows' => [], 'competitors' => []];

    public function show()
    {
        return response()->json(['data' => $this->load()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:40'],
            'rows.*.label' => ['required', 'string', 'max:120'],
            'rows.*.schoolpilot' => ['nullable', 'string', 'max:200'],
            'competitors' => ['present', 'array', 'max:8'],
            'competitors.*.name' => ['required', 'string', 'max:80'],
            'competitors.*.website' => ['nullable', 'url:https', 'max:200'],
            'competitors.*.source_url' => ['nullable', 'url:https', 'max:300'],
            'competitors.*.verified_on' => ['nullable', 'date', 'before_or_equal:today'],
            'competitors.*.published' => ['required', 'boolean'],
            'competitors.*.values' => ['present', 'array'],
            'competitors.*.values.*' => ['nullable', 'string', 'max:200'],
        ]);

        $rowCount = count($data['rows']);

        foreach ($data['competitors'] as $i => $c) {
            if (count($c['values']) !== $rowCount) {
                abort(response()->json([
                    'message' => 'Each competitor needs one value per row.',
                    'errors' => ["competitors.$i.values" => ['One value per row is required.']],
                ], 422));
            }

            if ($c['published'] && empty($c['verified_on'])) {
                abort(response()->json([
                    'message' => 'Set the date you last verified these values before publishing.',
                    'errors' => ["competitors.$i.verified_on" => ['Required to publish.']],
                ], 422));
            }
        }

        $doc = [
            'rows' => array_map(fn ($r) => ['label' => trim($r['label']), 'schoolpilot' => trim((string) ($r['schoolpilot'] ?? ''))], $data['rows']),
            'competitors' => array_map(fn ($c) => [
                'name' => trim($c['name']),
                'website' => $c['website'] ?? null,
                'source_url' => $c['source_url'] ?? null,
                'verified_on' => $c['verified_on'] ?? null,
                'published' => (bool) $c['published'],
                'values' => array_map(fn ($v) => trim((string) $v), array_values($c['values'])),
            ], $data['competitors']),
        ];

        DB::table('marketing_comparison')->updateOrInsert(
            ['id' => 1],
            ['data' => json_encode($doc), 'updated_at' => now(), 'created_at' => now()],
        );

        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => 'website.comparison.updated',
            'auditable_type' => self::class,
            'auditable_id' => 1,
            'new_values' => ['rows' => count($doc['rows']), 'competitors' => count($doc['competitors'])],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['data' => $doc]);
    }

    /** Public: published competitors only, or null while there is nothing to show. */
    public function publicShow()
    {
        $doc = $this->load();
        $keep = array_values(array_filter($doc['competitors'], fn ($c) => $c['published'] && $c['verified_on']));

        if ($keep === [] || $doc['rows'] === []) {
            return response()->json(['data' => null]);
        }

        // Source URLs are the operator's own records; the site shows name,
        // website and the verification date.
        return response()->json(['data' => [
            'rows' => $doc['rows'],
            'competitors' => array_map(fn ($c) => array_diff_key($c, ['published' => 1, 'source_url' => 1]), $keep),
            'updated_at' => DB::table('marketing_comparison')->value('updated_at'),
        ]]);
    }

    private function load(): array
    {
        $raw = DB::table('marketing_comparison')->value('data');

        return $raw ? json_decode($raw, true) + self::EMPTY : self::EMPTY;
    }
}
