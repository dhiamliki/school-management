<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /** Tenths, so a 15.4% cutoff survives the integer comparison in atRisk(). */
    private const RATE_SCALE = 10;

    /** Filterable by ?date=, ?student_id=, ?school_class_id= and ?status=. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date'],
            'student_id' => ['sometimes', 'exists:students,id'],
            'school_class_id' => ['sometimes', 'exists:school_classes,id'],
            'status' => ['sometimes', Rule::in(Attendance::STATUSES)],
        ]);

        $attendances = Attendance::with(['student.schoolClass', 'lesson'])
            ->when(isset($filters['date']), fn ($query) => $query->whereDate('date', $filters['date']))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['school_class_id']), fn ($query) => $query->whereHas(
                'student',
                fn ($student) => $student->where('school_class_id', $filters['school_class_id']),
            ))
            ->latest()
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AttendanceResource::collection($attendances);
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $attendance = Attendance::create($request->validated());

        return (new AttendanceResource($attendance->load(['student.schoolClass', 'lesson'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Attendance $attendance): AttendanceResource
    {
        $attendance->load(['student.schoolClass', 'lesson']);

        return new AttendanceResource($attendance);
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        $attendance->update($request->validated());

        return new AttendanceResource($attendance->load(['student.schoolClass', 'lesson']));
    }

    public function destroy(Attendance $attendance): Response
    {
        $attendance->delete();

        return response()->noContent();
    }

    public function byStudent(Request $request, Student $student): AnonymousResourceCollection
    {
        $attendances = $student->attendances()
            ->with(['student.schoolClass', 'lesson'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AttendanceResource::collection($attendances);
    }

    /**
     * Pupils at risk, worst first, measured as an absence RATE not a count.
     * A count conflates being away with sitting more lessons: the upper band
     * accumulates marks 60% faster, so a fixed count flagged 45% of it against
     * 17% of the lower band on identical behaviour. ?threshold= still selects
     * the old count rule.
     */
    public function atRisk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The rate rule (the default), as a percentage.
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'prohibits:threshold'],
            // The legacy count rule, kept as an explicit opt-in.
            'threshold' => ['sometimes', 'integer', 'min:0', 'prohibits:rate'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'min_records' => ['sometimes', 'integer', 'min:1'],
        ]);

        $days = (int) ($validated['days'] ?? Attendance::RECENT_DAYS);
        $since = Carbon::today()->subDays($days)->toDateString();
        $byCount = array_key_exists('threshold', $validated);
        $threshold = (int) ($validated['threshold'] ?? 0);
        $rate = (float) ($validated['rate'] ?? Attendance::AT_RISK_RATE);
        $minRecords = (int) ($validated['min_records'] ?? Attendance::AT_RISK_MIN_RECORDS);

        // A derived table rather than withCount, so the rate can be both
        // filtered and sorted on.
        $summary = Attendance::query()
            ->selectRaw(
                'student_id,'
                .' count(*) as records_count,'
                .' sum(case when status = ? then 1 else 0 end) as absence_count,'
                .' sum(case when status = ? then 1 else 0 end) as late_count,'
                .' sum(case when status = ? then 1 else 0 end) * 100.0 / count(*) as absence_rate',
                [Attendance::ABSENT, Attendance::LATE, Attendance::ABSENT],
            )
            ->whereDate('date', '>=', $since)
            ->groupBy('student_id');

        $students = Student::query()
            ->with('schoolClass')
            ->joinSub($summary, 'summary', fn ($join) => $join->on('summary.student_id', '=', 'students.id'))
            ->select('students.*')
            ->addSelect(['summary.records_count', 'summary.absence_count', 'summary.late_count', 'summary.absence_rate'])
            ->when(
                $byCount,
                fn ($query) => $query->where('summary.absence_count', '>', $threshold),
                // A handful of marks can produce any rate at all, so a pupil
                // barely in the register yet is not evidence of anything.
                fn ($query) => $query
                    ->where('summary.records_count', '>=', $minRecords)
                    // Scaled to integers on both sides. PDO's SQLite driver has
                    // no float type, so a bound 15.0 arrives as the string '15',
                    // and SQLite sorts text above every number: the comparison
                    // would be quietly false for every pupil.
                    ->whereRaw(
                        'summary.absence_count * '.(self::RATE_SCALE * 100)
                        .' > ? * summary.records_count',
                        [(int) round($rate * self::RATE_SCALE)],
                    ),
            )
            ->orderByDesc($byCount ? 'summary.absence_count' : 'summary.absence_rate')
            ->orderBy('students.id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $students->getCollection()->transform(fn (Student $student) => [
            'id' => $student->id,
            'name' => $student->name,
            // The pupils table carries a matricule, not an address: the email
            // column was dropped when the two were swapped, and reading it
            // here answered null on every row.
            'matricule' => $student->matricule,
            'school_class' => $student->schoolClass?->only(['id', 'name', 'level']),
            'absence_count' => (int) $student->absence_count,
            'late_count' => (int) $student->late_count,
            'records_count' => (int) $student->records_count,
            'absence_rate' => round((float) $student->absence_rate, 1),
            'attendance_rate' => $student->records_count > 0
                ? round(($student->records_count - $student->absence_count) / $student->records_count * 100, 1)
                : null,
        ]);

        // Same { data, links, meta } envelope as every other index endpoint.
        return JsonResource::collection($students)
            ->additional([
                'rule' => $byCount ? 'count' : 'rate',
                'rate' => $byCount ? null : $rate,
                'threshold' => $byCount ? $threshold : null,
                'min_records' => $byCount ? null : $minRecords,
                'days' => $days,
                'since' => $since,
            ])
            ->response();
    }

    /** A whole class in one request, upserted so re-saving a day corrects it. */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            // Capped so one request cannot ask for unbounded rows.
            'marks' => ['required', 'array', 'min:1', 'max:200'],
            'marks.*.student_id' => ['required', 'exists:students,id'],
            'marks.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'marks.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();
        $lessonId = $validated['lesson_id'] ?? null;

        $saved = DB::transaction(function () use ($validated, $date, $lessonId) {
            $count = 0;

            foreach ($validated['marks'] as $mark) {
                // whereDate, not updateOrCreate: the date cast stores a
                // midnight timestamp, so matching a 'Y-m-d' string would miss
                // the row and insert a duplicate on every re-save.
                $existing = Attendance::where('student_id', $mark['student_id'])
                    ->when(
                        $lessonId === null,
                        fn ($query) => $query->whereNull('lesson_id'),
                        fn ($query) => $query->where('lesson_id', $lessonId),
                    )
                    ->whereDate('date', $date)
                    ->first();

                $attributes = [
                    'status' => $mark['status'],
                    'note' => $mark['note'] ?? null,
                ];

                if ($existing !== null) {
                    $existing->update($attributes);
                } else {
                    Attendance::create($attributes + [
                        'student_id' => $mark['student_id'],
                        'lesson_id' => $lessonId,
                        'date' => $date,
                    ]);
                }

                $count++;
            }

            return $count;
        });

        return response()->json([
            'saved' => $saved,
            'date' => $date,
            'lesson_id' => $lessonId,
        ]);
    }
}
