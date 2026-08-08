<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A child's health record — the only place these four fields leave the server.
 *
 * `makeVisible` is called explicitly here because the model hides them by
 * default. That inversion is the point: exposure has to be a decision someone
 * wrote down, in a controller action that checks authorization and writes an
 * audit row, rather than the automatic consequence of returning a model.
 *
 * Under NDPA (doc §12) health data is a special category. The practical test a
 * school will be asked is "who looked at this child's medical notes, and when"
 * — which is answerable only if there is exactly one route to the data.
 */
class StudentMedicalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->resource->makeVisible([
            'blood_group',
            'allergies',
            'medical_notes',
            'emergency_contacts',
        ]);

        return [
            'student_id' => $student->id,
            'admission_number' => $student->admission_number,
            'name' => $student->user?->name,

            'blood_group' => $student->blood_group,
            'allergies' => $student->allergies ?? [],
            'medical_notes' => $student->medical_notes,
            'emergency_contacts' => $student->emergency_contacts ?? [],

            'accessed_at' => now()->toIso8601String(),
        ];
    }
}
