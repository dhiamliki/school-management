<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return response()->json(Student::with('schoolClass')->latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('students', 'email')],
            'birth_date' => ['nullable', 'date'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ]);

        $student = Student::create($validated);

        return response()->json($student->load('schoolClass'), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Student $student): JsonResponse
    {
        $student->load('schoolClass');

        return response()->json($student);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('students', 'email')->ignore($student)],
            'birth_date' => ['nullable', 'date'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ]);

        $student->update($validated);

        return response()->json($student->load('schoolClass'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student): Response
    {
        $student->delete();

        return response()->noContent();
    }
}
