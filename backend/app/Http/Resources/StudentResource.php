<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The student payload for rosters, search and detail views.
 *
 * Explicit field list rather than a model dump. Two reasons, and the second is
 * the one that bites in Nigeria:
 *
 * 1. Nothing sensitive can be added to the payload by accident. A future
 *    column on `students` does not silently start appearing in every teacher's
 *    roster response.
 * 2. Payload size is a feature requirement (doc §4.5 of the review): parents
 *    and teachers pay per megabyte, and a roster of 1,200 students dumping
 *    every column plus a fully-loaded `school` relation on each row is real
 *    money on a mobile bundle.
 */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admission_number' => $this->admission_number,
            'name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),

            'class_id' => $this->class_id,
            'class' => $this->whenLoaded('currentClass', fn () => $this->currentClass?->name),
            'arm_id' => $this->arm_id,
            'arm' => $this->whenLoaded('currentArm', fn () => $this->currentArm?->name),

            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'status' => $this->status,

            // Registration-form fields. Not health data, and a school needs
            // them on the roster for state-of-origin quotas and reporting.
            'state_of_origin' => $this->state_of_origin,
            'lga' => $this->lga,
            'religion' => $this->religion,
            'previous_school' => $this->previous_school,
            'passport_photo_path' => $this->passport_photo_path,

            // Deliberately absent: blood_group, allergies, medical_notes,
            // emergency_contacts. See StudentMedicalResource.
        ];
    }
}
