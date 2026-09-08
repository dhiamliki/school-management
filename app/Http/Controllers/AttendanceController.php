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
    /**
     * Tenths, so an at-risk rate of 15.4% survives the integer comparison in
     * atRisk() without a float ever being bound. See the note there.
     */
    private const RATE_SCALE = 10;

    /**
     * Display a listing of the resource.
     *
     * Accepts ?date=, ?student_id= and ?school_class_id= so the marking grid
     * can ask for exactly the day it is showing.
     */
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

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $attendance = Attendance::create($request->validated());

        return (new AttendanceResource($attendance->load(['student.schoolClass', 'lesson'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Attendance $attendance): AttendanceResource
    {
        $attendance->load(['student.schoolClass', 'lesson']);

        return new AttendanceResource($attendance);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        $attendance->update($request->validated());

        return new AttendanceResource($attendance->load(['student.schoolClass', 'lesson']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attendance $attendance): Response
    {
        $attendance->delete();

        return response()->noContent();
    }

    /**
     * Full attendance history for one pupil, newest first.
     */
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
     * Pupils at risk on attendance, worst first.
     *
     * Measured as a RATE - absences over every mark the pupil has in the
     * window - not as a raw count.
     *
     * A count conflates two different things: how often a pupil is actually
     * away, and how many lessons they sit in the first place. One mark is
     * written per lesson, and the curriculum gives the upper band far more
     * weekly hours than the lower one, so an upper-band pupil accumulates
     * marks about 60% faster (114 in a month against 70) and crosses any fixed
     * count sooner on identical behaviour. On the seeded school, "more than 5
     * absences" flagged 45.5% of the upper band against 16.7% of the lower -
     * a 2.7x gap that is an artefact of the timetable, not of attendance. The
     * same data under the rate rule flags 4 upper and 2 lower.
     *
     * `threshold` still selects the old count rule for anyone who wants it,
     * but the two are different questions and cannot both be answered at once.
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

        // One aggregate pass over the window, joined back to the roster. Doing
        // it as a derived table rather than as withCount lets the rate be both
        // filtered and sorted on, which a withCount alias cannot be.
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
                // who has barely been in the register yet is not evidence of
                // anything. The floor keeps a new arrival with one absence in
                // three lessons off a list that is meant to prompt a phone call.
                fn ($query) => $query
                    ->where('summary.records_count', '>=', $minRecords)
                    // Compared as whole numbers rather than against the rate
                    // directly, and not for tidiness: PDO's SQLite driver has
                    // no float parameter type, so a bound 15.0 arrives as the
                    // string '15' - and SQLite sorts every text value above
                    // every number, making `absence_rate > ?` quietly false
                    // for every pupil. Multiplying out keeps both sides
                    // integers, which bind and compare correctly on SQLite and
                    // MySQL alike. The tenth is preserved: RATE_SCALE is 10.
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
            // The figure the rate rule filters on.
            'absence_rate' => round((float) $student->absence_rate, 1),
            // Kept for callers that already read it: the share of marks that
            // were not an absence.
            'attendance_rate' => $student->records_count > 0
                ? round(($student->records_count - $student->absence_count) / $student->records_count * 100, 1)
                : null,
        ]);

        // Wrapped as a resource collection so this answers with the same
        // { data, links, meta } envelope as every other index endpoint, with
        // the rule the report was built under added alongside.
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

    /**
     * Record a whole day's marks in one request.
     *
     * The marking grid saves a screenful of pupils at once, and one request
     * per row would be both slow and non-atomic. Marks are upserted on
     * (pupil, lesson, day) so re-saving a day corrects it instead of failing
     * on the unique index - and so that full-day marks, which the index
     * cannot constrain because their lesson_id is null, still cannot pile up.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            // Capped as well as floored. The grid saves one class at a time
            // and the largest class in the school is nowhere near this, so the
            // ceiling only bites on a payload nobody meant to send: without it
            // one request could ask for an unbounded number of rows, each
            // costing two queries inside a single transaction.
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
                // Matched with whereDate rather than updateOrCreate: the date
                // cast stores a midnight timestamp, so an equality match on a
                // 'Y-m-d' string would miss the existing row and insert a
                // duplicate every time the day was re-saved.
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
