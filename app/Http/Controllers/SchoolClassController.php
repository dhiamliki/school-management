<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return response()->json(SchoolClass::latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $schoolClass = SchoolClass::create($validated);

        return response()->json($schoolClass, Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(SchoolClass $schoolClass): JsonResponse
    {
        $schoolClass->load(['students', 'lessons']);

        return response()->json($schoolClass);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $schoolClass->update($validated);

        return response()->json($schoolClass);
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
