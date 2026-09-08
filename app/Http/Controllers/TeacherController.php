<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeacherRequest;
use App\Http\Requests\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TeacherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeacherResource::collection(
            Teacher::latest()->orderByDesc('id')->paginate($this->perPage($request))->withQueryString()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = Teacher::create($request->validated());

        return (new TeacherResource($teacher))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Teacher $teacher): TeacherResource
    {
        // The lessons carry their class and their slots, which is what the
        // profile builds the weekly timetable from. The classes served come
        // back separately, de-duplicated: a teacher normally has several
        // lessons with the same class and the header lists each one once.
        $teacher->load([
            'lessons.schoolClass',
            'lessons.timetables',
            'schoolClasses' => fn ($query) => $query->select('school_classes.*')->distinct()->orderBy('name'),
        ])->loadCount('lessons');

        return new TeacherResource($teacher);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $teacher->update($request->validated());

        return new TeacherResource($teacher);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Teacher $teacher): Response
    {
        $teacher->delete();

        return response()->noContent();
    }
}
