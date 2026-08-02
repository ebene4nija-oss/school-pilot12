<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AiStudioController extends Controller
{
    /**
     * Manage School AI Settings (Master Toggle, Feature Flags, BYO API Credentials)
     */
    public function updateAiSettings(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $school = \App\Models\School::findOrFail($schoolId);

        $validator = Validator::make($request->all(), [
            'ai_enabled'       => 'nullable|boolean',
            'ai_feature_flags' => 'nullable|array',
            'ai_feature_flags.report_comments'   => 'nullable|boolean',
            'ai_feature_flags.lesson_plans'      => 'nullable|boolean',
            'ai_feature_flags.exam_generation'   => 'nullable|boolean',
            'ai_feature_flags.homework_ideas'    => 'nullable|boolean',
            'ai_feature_flags.translation'       => 'nullable|boolean',
            'ai_feature_flags.performance_summaries' => 'nullable|boolean',
            'ai_api_settings'  => 'nullable|array',
            'ai_api_settings.api_key'      => 'nullable|string',
            'ai_api_settings.model'        => 'nullable|string',
            'ai_api_settings.temperature'  => 'nullable|numeric|min:0|max:1',
            'ai_api_settings.max_tokens'   => 'nullable|integer|min:50|max:4000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school->update([
            'ai_enabled' => $request->has('ai_enabled') ? $request->ai_enabled : $school->ai_enabled,
            'ai_feature_flags' => array_merge($school->ai_feature_flags ?? [
                'report_comments' => true,
                'lesson_plans' => true,
                'exam_generation' => true,
                'homework_ideas' => true,
                'translation' => true,
                'performance_summaries' => true,
            ], $request->input('ai_feature_flags', [])),
            'ai_api_settings' => array_merge($school->ai_api_settings ?? [], $request->input('ai_api_settings', [])),
        ]);

        return response()->json([
            'message' => 'School AI configuration and feature flags updated successfully.',
            'school_ai_config' => [
                'ai_enabled' => $school->ai_enabled,
                'ai_feature_flags' => $school->ai_feature_flags,
                'custom_api_configured' => !empty($school->ai_api_settings['api_key']),
            ]
        ]);
    }

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

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $plan = $aiManager->generateLessonPlan($schoolId, $request->subject, $request->topic, $request->class_level);
            return response()->json(['message' => 'Lesson plan generated', 'lesson_plan' => $plan]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function generateExams(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic'   => 'required|string',
            'count'   => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $exam = $aiManager->generateExamQuestions($schoolId, $request->subject, $request->topic, $request->get('count', 5));
            return response()->json(['message' => 'Exam questions generated', 'exam' => $exam]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function generateHomework(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic'   => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $ideas = $aiManager->generateHomeworkIdeas($schoolId, $request->subject, $request->topic);
            return response()->json(['message' => 'Homework ideas generated', 'homework_ideas' => $ideas]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function translate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text'            => 'required|string',
            'target_language' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $translation = $aiManager->translateContent($schoolId, $request->text, $request->target_language);
            return response()->json(['message' => 'Content translated', 'translation' => $translation]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function summarizePerformance(Request $request, $studentId)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $summary = $aiManager->generatePerformanceSummary($schoolId, $studentId);
            return response()->json(['message' => 'Performance summary generated', 'summary' => $summary]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
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
            'response' => "Great question! Let's break down key concepts together.",
            'is_guided' => true,
        ]);
    }
}
