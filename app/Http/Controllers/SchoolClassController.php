<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\UpdateSchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SchoolClassResource::collection(
            SchoolClass::latest()->orderByDesc('id')->paginate($this->perPage($request))->withQueryString()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $schoolClass = SchoolClass::create($request->validated());

        return (new SchoolClassResource($schoolClass))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(SchoolClass $schoolClass): SchoolClassResource
    {
        // withAttendanceSummary on the roster matters: StudentResource always
        // reports each pupil's attendance, and without the tallies preloaded
        // that would be four queries per pupil.
        $schoolClass->load([
            'students' => fn ($query) => $query->withAttendanceSummary()->orderBy('name'),
            'lessons.teacher',
            'lessons.timetables',
        ])->loadAttendanceSummary();

        return new SchoolClassResource($schoolClass);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolClassRequest $request, SchoolClass $schoolClass): SchoolClassResource
    {
        $schoolClass->update($request->validated());

        return new SchoolClassResource($schoolClass);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SchoolClass $schoolClass): Response
    {
        $schoolClass->delete();

        return response()->noContent();
    }
}
