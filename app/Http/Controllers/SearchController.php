<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The header search: one query across the three things a school looks people
 * up by - a pupil, a teacher, a class.
 *
 * Kept as its own endpoint rather than a `search` filter bolted onto each
 * index. The header asks one question and wants one answer, and doing it here
 * means the three index endpoints keep the field-filter contracts their own
 * tests pin down.
 */
class SearchController extends Controller
{
    /**
     * How many hits of each kind come back. The header shows a short jump
     * list, not a results page - anyone wanting the full set has the pages
     * themselves.
     */
    private const PER_TYPE = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        $term = trim($validated['q']);

        // A single character matches most of the school and tells nobody
        // anything, so the search stays quiet until there is something to go on.
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

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function students(string $term): Collection
    {
        return Student::query()
            ->with('schoolClass')
            // A pupil is looked up by name at the desk and by matricule off a
            // register or a report card, so both reach the same row.
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

    /**
     * @return Collection<int, array<string, mixed>>
     */
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

    /**
     * @return Collection<int, array<string, mixed>>
     */
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
     * A contains-match with the wildcards the user typed escaped, so a name
     * with a % or _ in it searches for that character instead of turning into
     * a pattern of its own.
     */
    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
