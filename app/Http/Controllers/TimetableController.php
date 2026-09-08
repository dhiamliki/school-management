<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTimetableRequest;
use App\Http\Requests\UpdateTimetableRequest;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use App\Support\TimetableConflictDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TimetableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TimetableResource::collection(
            Timetable::with(['lesson.teacher', 'lesson.schoolClass'])
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request))
                ->withQueryString()
        );
    }

    /**
     * Report the conflicts a proposed slot would create, without saving it.
     *
     * Same detector the Form Requests use, so a slot this endpoint calls
     * clean is a slot the save will accept. It exists so the form can warn
     * while the user is still filling it in rather than only on rejection.
     */
    public function checkConflicts(Request $request, TimetableConflictDetector $detector): JsonResponse
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'string', Rule::in(Timetable::DAYS)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
            'room' => ['nullable', 'string'],
            'exclude_id' => ['nullable', 'integer'],
        ]);

        $conflicts = $detector->conflicts(
            dayOfWeek: $validated['day_of_week'],
            startTime: $validated['start_time'],
            endTime: $validated['end_time'],
            teacherId: isset($validated['teacher_id']) ? (int) $validated['teacher_id'] : null,
            room: $validated['room'] ?? null,
            schoolClassId: isset($validated['school_class_id']) ? (int) $validated['school_class_id'] : null,
            excludeId: isset($validated['exclude_id']) ? (int) $validated['exclude_id'] : null,
        );

        return response()->json([
            'has_conflicts' => $conflicts !== [],
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTimetableRequest $request): JsonResponse
    {
        $timetable = Timetable::create($request->validated());

        return (new TimetableResource($timetable->load(['lesson.teacher', 'lesson.schoolClass'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Timetable $timetable): TimetableResource
    {
        $timetable->load(['lesson.teacher', 'lesson.schoolClass']);

        return new TimetableResource($timetable);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTimetableRequest $request, Timetable $timetable): TimetableResource
    {
        $timetable->update($request->validated());

        return new TimetableResource($timetable->load(['lesson.teacher', 'lesson.schoolClass']));
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
