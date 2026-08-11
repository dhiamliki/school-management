<?php

namespace App\Http\Controllers;

use App\Models\Timetable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TimetableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Timetable::with(['lesson.teacher', 'lesson.schoolClass'])->latest()->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => ['required', 'exists:lessons,id'],
            'day_of_week' => ['required', 'string'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string'],
        ]);

        $timetable = Timetable::create($validated);

        return response()->json(
            $timetable->load(['lesson.teacher', 'lesson.schoolClass']),
            Response::HTTP_CREATED
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Timetable $timetable): JsonResponse
    {
        $timetable->load(['lesson.teacher', 'lesson.schoolClass']);

        return response()->json($timetable);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Timetable $timetable): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => ['required', 'exists:lessons,id'],
            'day_of_week' => ['required', 'string'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string'],
        ]);

        $timetable->update($validated);

        return response()->json($timetable->load(['lesson.teacher', 'lesson.schoolClass']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Timetable $timetable): Response
    {
        $timetable->delete();

        return response()->noContent();
    }
}
