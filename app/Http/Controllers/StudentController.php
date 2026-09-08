<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StudentController extends Controller
{
    private const HISTORY_LENGTH = 15;

    public function index(Request $request): AnonymousResourceCollection
    {
        // ?school_class_id= lets a caller ask for one class's roster instead
        // of paging the whole school, which the marking grid relies on.
        $filters = $request->validate([
            'school_class_id' => ['sometimes', 'exists:school_classes,id'],
        ]);

        return StudentResource::collection(
            Student::with('schoolClass')
                ->withAttendanceSummary()
                ->when(
                    isset($filters['school_class_id']),
                    fn ($query) => $query->where('school_class_id', $filters['school_class_id']),
                )
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request))
                ->withQueryString()
        );
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        // Enrolling staff should not have to invent a school number: unless
        // one was typed, the pupil is numbered from the row id they just got.
        if (blank($student->matricule)) {
            $student->update(['matricule' => Student::matriculeFor($student->id)]);
        }

        // The tallies are loaded so a write answers with the same shape the
        // index does. They are all zero on a pupil enrolled a moment ago, but
        // an absent block and a zeroed one are different things to a client,
        // and this costs four counts against one row.
        $student->load('schoolClass')->loadAttendanceSummary();

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Student $student): StudentResource
    {
        // Everything the profile page needs, in one request: the class and
        // the week it teaches, the tallies, and the latest marks.
        $student->load([
            'schoolClass',
            'schoolClass.lessons.teacher',
            'schoolClass.lessons.timetables',
            'attendances' => fn ($query) => $query
                ->with('lesson')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(self::HISTORY_LENGTH),
        ])->loadAttendanceSummary();

        return new StudentResource($student);
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        // Loaded for the same reason as in store(): the edited pupil comes
        // back in the shape the list they came from uses.
        $student->load('schoolClass')->loadAttendanceSummary();

        return new StudentResource($student);
    }

    public function destroy(Student $student): Response
    {
        $student->delete();

        return response()->noContent();
    }
}
