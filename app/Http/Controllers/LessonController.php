<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LessonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return LessonResource::collection(
            Lesson::with(['teacher', 'schoolClass'])
                ->latest()
                ->orderByDesc('id')
                ->paginate($this->perPage($request))
                ->withQueryString()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLessonRequest $request): JsonResponse
    {
        $lesson = Lesson::create($request->validated());

        return (new LessonResource($lesson->load(['teacher', 'schoolClass'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Lesson $lesson): LessonResource
    {
        $lesson->load(['teacher', 'schoolClass', 'timetables']);

        return new LessonResource($lesson);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLessonRequest $request, Lesson $lesson): LessonResource
    {
        $lesson->update($request->validated());

        return new LessonResource($lesson->load(['teacher', 'schoolClass']));
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
