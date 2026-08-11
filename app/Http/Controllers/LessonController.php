<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LessonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return response()->json(Lesson::with(['teacher', 'schoolClass'])->latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ]);

        $lesson = Lesson::create($validated);

        return response()->json($lesson->load(['teacher', 'schoolClass']), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Lesson $lesson): JsonResponse
    {
        $lesson->load(['teacher', 'schoolClass', 'timetables']);

        return response()->json($lesson);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Lesson $lesson): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ]);

        $lesson->update($validated);

        return response()->json($lesson->load(['teacher', 'schoolClass']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lesson $lesson): Response
    {
        $lesson->delete();

        return response()->noContent();
    }
}
