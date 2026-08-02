<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AiStudioController extends Controller
{
    public function generateLessonPlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic' => 'required|string',
            'class_level' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cacheKey = "lesson_plan:" . md5("{$request->subject}:{$request->topic}:{$request->class_level}");

        $lessonPlan = Cache::remember($cacheKey, 86400, function () use ($request) {
            return [
                'title' => "Lesson Plan: {$request->topic}",
                'subject' => $request->subject,
                'class_level' => $request->class_level,
                'objectives' => [
                    "Understand key principles of {$request->topic}",
                    "Apply theoretical concepts to practical exercises",
                ],
                'curriculum_alignment' => 'WAEC/NECO Standard',
                'activities' => [
                    '0-10m' => 'Introduction and prerequisite review',
                    '10-30m' => 'Core lecture & interactive whiteboard demo',
                    '30-40m' => 'Group activity and problem solving',
                ],
                'assessment_strategy' => 'End-of-lesson 5-question quiz',
            ];
        });

        return response()->json([
            'message' => 'Lesson plan generated successfully',
            'lesson_plan' => $lessonPlan,
        ]);
    }

    public function tutorChat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = strtolower($request->message);

        // Explicit Domain Rule: Block bare exam answer outputs
        if (str_contains($message, 'give me the answer to question') || str_contains($message, 'what is the direct answer')) {
            return response()->json([
                'response' => "I am here to help you learn! Instead of handing over direct exam answers, let me guide you through how to solve this step-by-step.",
                'is_guided' => true,
            ]);
        }

        return response()->json([
            'response' => "Great question! Let's break down {$request->message} into key concepts...",
            'is_guided' => true,
        ]);
    }
}
