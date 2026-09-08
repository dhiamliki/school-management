<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The header search: one query across pupils, teachers and classes. */
class SearchController extends Controller
{
    /** Hits of each kind. The header is a jump list, not a results page. */
    private const PER_TYPE = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        $term = trim($validated['q']);

        // One character matches most of the school.
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => [], 'term' => $term]);
        }

        $results = collect()
            ->concat($this->students($term))
            ->concat($this->teachers($term))
            ->concat($this->schoolClasses($term));

        return response()->json([
            'data' => $results->values()->all(),
            'term' => $term,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function students(string $term): Collection
    {
        return Student::query()
            ->with('schoolClass')
            ->where(fn ($query) => $query
                ->where('name', 'like', $this->like($term))
                ->orWhere('matricule', 'like', $this->like($term)))
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Student $student) => [
                'type' => 'student',
                'kind' => 'Élève',
                'id' => $student->id,
                'label' => $student->name,
                'meta' => $student->schoolClass?->name ?? 'sans classe',
                'to' => '/students/'.$student->id,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function teachers(string $term): Collection
    {
        return Teacher::query()
            ->where('name', 'like', $this->like($term))
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Teacher $teacher) => [
                'type' => 'teacher',
                'kind' => 'Enseignant',
                'id' => $teacher->id,
                'label' => $teacher->name,
                'meta' => $teacher->subject ?? 'sans matière',
                'to' => '/teachers/'.$teacher->id,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function schoolClasses(string $term): Collection
    {
        return SchoolClass::query()
            ->withCount('students')
            ->where('name', 'like', $this->like($term))
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (SchoolClass $schoolClass) => [
                'type' => 'school_class',
                'kind' => 'Classe',
                'id' => $schoolClass->id,
                'label' => $schoolClass->name,
                'meta' => $schoolClass->students_count.' élèves',
                'to' => '/classes/'.$schoolClass->id,
            ]);
    }

    /**
     * Wildcards the user typed are escaped, so a name with a % in it searches
     * for that character.
     */
    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
