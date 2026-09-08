<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    private const ACTIVITY_LIMIT = 15;

    /** Per table, so the one written last does not fill the list on its own. */
    private const ACTIVITY_PER_SOURCE = 5;

    /** Lundi to Samedi. */
    private const ATTENDANCE_WEEK_DAYS = 6;

    /**
     * French weekday names, indexed the way Carbon numbers them. Sunday (7)
     * is deliberately absent: the school week runs Lundi to Samedi.
     *
     * @var array<int, string>
     */
    private const WEEKDAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
    ];

    /**
     * Every headline figure in one request. The page used to read each from the
     * meta.total of a separate index call: seven round trips for seven integers.
     */
    public function stats(): JsonResponse
    {
        $today = Carbon::today();
        $todayCounts = $this->countsOn($today);

        // The day before today that the school actually opened, so a Monday
        // is compared against Saturday rather than against a shut Sunday.
        $previous = $this->previousSchoolDay($today);
        $previousCounts = $this->countsOn($previous);

        return response()->json([
            'counts' => [
                'school_classes' => SchoolClass::count(),
                'students' => Student::count(),
                'teachers' => Teacher::count(),
                'lessons' => Lesson::count(),
                'timetables' => Timetable::count(),
            ],
            'today' => [
                'date' => $today->toDateString(),
                'marked' => $todayCounts['marked'],
                'present' => $todayCounts['present'],
                // Null rather than 0% when nothing is marked yet: no register
                // taken is not the same as nobody present.
                'rate' => $todayCounts['rate'],
                // The comparison behind the trend arrow. Both sides have to be
                // real for it to mean anything, so it stays null until each of
                // the two days has a register - the alternative is an arrow
                // that invents a change out of missing data.
                'previous' => [
                    'date' => $previous->toDateString(),
                    'day' => self::WEEKDAYS[$previous->dayOfWeekIso] ?? null,
                    'rate' => $previousCounts['rate'],
                ],
                'change' => $todayCounts['rate'] !== null && $previousCounts['rate'] !== null
                    ? round($todayCounts['rate'] - $previousCounts['rate'], 1)
                    : null,
            ],
            'gender' => $this->genderSplit(),
            'context' => $this->context(),
        ]);
    }

    /**
     * The badge figures on the stat tiles. Nothing for the Cours tile: no figure
     * about lessons says more than the count itself.
     *
     * @return array{class_occupancy: float|null, teacher_subjects: int}
     */
    private function context(): array
    {
        $capacity = (int) SchoolClass::sum('capacity');
        $enrolled = Student::whereNotNull('school_class_id')->count();

        return [
            // How full the school is: pupils actually enrolled in a class,
            // over the places those classes declare. Null when no class states
            // a capacity, rather than a division by zero.
            'class_occupancy' => $capacity > 0
                ? round($enrolled / $capacity * 100, 1)
                : null,
            // Distinct subjects taught across the roster.
            'teacher_subjects' => Teacher::whereNotNull('subject')->distinct()->count('subject'),
        ];
    }

    /**
     * Counts every mark on the date, not only those tied to a lesson, so a
     * full-day absence is not dropped from the chart.
     */
    public function attendanceWeek(): JsonResponse
    {
        $days = [];
        $cursor = Carbon::today();

        // Today counts if it is a teaching day; otherwise start from the last
        // one, so a Sunday still shows the week that has just finished.
        if (! isset(self::WEEKDAYS[$cursor->dayOfWeekIso])) {
            $cursor = $this->previousSchoolDay($cursor);
        }

        for ($i = 0; $i < self::ATTENDANCE_WEEK_DAYS; $i++) {
            $counts = $this->countsOn($cursor);

            $days[] = [
                'date' => $cursor->toDateString(),
                'day' => self::WEEKDAYS[$cursor->dayOfWeekIso],
                // Samedi teaches a half day, so it has fewer marks by design.
                // Flagged rather than hidden: a shorter bar there is expected,
                // not a gap in the register.
                'half_day' => $cursor->dayOfWeekIso === 6,
                'present' => $counts['present'],
                'late' => $counts['late'],
                'absent' => $counts['absent'],
                'marked' => $counts['marked'],
                'rate' => $counts['rate'],
            ];

            $cursor = $this->previousSchoolDay($cursor);
        }

        return response()->json(['data' => array_reverse($days)]);
    }

    /**
     * "unknown" is a real bucket: the column is nullable, and a school that has
     * not recorded it should see that said rather than have pupils sorted.
     *
     * @return array{male: int, female: int, unknown: int, total: int}
     */
    private function genderSplit(): array
    {
        $counts = Student::query()
            ->selectRaw('gender, COUNT(*) as total')
            ->groupBy('gender')
            ->pluck('total', 'gender');

        $male = (int) ($counts[Student::MALE] ?? 0);
        $female = (int) ($counts[Student::FEMALE] ?? 0);
        // Anything that is not one of the two recorded values is unrecorded.
        // Derived by subtraction rather than by reading a null key, which the
        // database driver may hand back as null or as an empty string.
        $unknown = max(0, (int) $counts->sum() - $male - $female);

        return [
            'male' => $male,
            'female' => $female,
            'unknown' => $unknown,
            'total' => $male + $female + $unknown,
        ];
    }

    /**
     * Present, late, absent and the resulting attendance rate for one date.
     *
     * @return array{marked: int, present: int, late: int, absent: int, rate: float|null}
     */
    private function countsOn(Carbon $date): array
    {
        $counts = Attendance::query()
            ->whereDate('date', $date->toDateString())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = (int) ($counts[Attendance::PRESENT] ?? 0);
        $late = (int) ($counts[Attendance::LATE] ?? 0);
        $absent = (int) ($counts[Attendance::ABSENT] ?? 0);
        $marked = (int) $counts->sum();

        return [
            'marked' => $marked,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            // A retard counts against the rate, matching Student::attendanceRate().
            'rate' => $marked > 0 ? round($present / $marked * 100, 1) : null,
        ];
    }

    /** Steps over Sunday rather than reporting a day nobody attended. */
    private function previousSchoolDay(Carbon $date): Carbon
    {
        $previous = $date->copy()->subDay();

        while (! isset(self::WEEKDAYS[$previous->dayOfWeekIso])) {
            $previous->subDay();
        }

        return $previous;
    }

    /**
     * Weekday resolved server-side so it cannot disagree with the browser's
     * timezone.
     */
    public function todaySchedule(): JsonResponse
    {
        $today = Carbon::today();
        $weekday = self::WEEKDAYS[$today->dayOfWeekIso] ?? null;

        if ($weekday === null) {
            return response()->json([
                'data' => [],
                'date' => $today->toDateString(),
                'day' => null,
                'is_school_day' => false,
            ]);
        }

        $slots = Timetable::with(['lesson.teacher', 'lesson.schoolClass'])
            ->where('day_of_week', $weekday)
            ->orderBy('start_time')
            ->orderBy('end_time')
            ->get();

        $data = $slots->map(fn (Timetable $slot) => [
            'id' => $slot->id,
            'start_time' => substr((string) $slot->start_time, 0, 5),
            'end_time' => substr((string) $slot->end_time, 0, 5),
            'title' => $slot->lesson?->title,
            'subject' => $slot->lesson?->subject,
            'teacher' => $slot->lesson?->teacher?->name,
            'school_class' => $slot->lesson?->schoolClass?->name,
            'room' => $slot->room,
        ]);

        return response()->json([
            'data' => $data->values(),
            'date' => $today->toDateString(),
            'day' => $weekday,
            'is_school_day' => true,
        ]);
    }

    /**
     * Reconstructed from created_at and updated_at, so it shows additions and
     * edits but never deletions. Not an audit trail.
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::ACTIVITY_LIMIT);

        $entries = collect()
            ->concat($this->activityFrom(
                Student::query()->with('schoolClass'),
                'student',
                fn (Student $s) => $s->schoolClass
                    ? "{$s->name} ({$s->schoolClass->name})"
                    : $s->name,
                'élève',
            ))
            ->concat($this->activityFrom(
                Teacher::query(),
                'teacher',
                fn (Teacher $t) => $t->subject ? "{$t->name} ({$t->subject})" : $t->name,
                'enseignant',
            ))
            ->concat($this->activityFrom(
                SchoolClass::query(),
                'school_class',
                fn (SchoolClass $c) => $c->name,
                'classe',
            ))
            ->concat($this->activityFrom(
                Lesson::query()->with('schoolClass'),
                'lesson',
                fn (Lesson $l) => $l->schoolClass
                    ? "{$l->title} ({$l->schoolClass->name})"
                    : $l->title,
                'cours',
            ))
            ->concat($this->attendanceActivity());

        return response()->json(['data' => $this->interleave($entries, $limit)]);
    }

    /**
     * Newest first, interleaving sources when timestamps tie, which is the
     * normal case on a fresh seed. Ranking each source separately stops one
     * table pushing the others out.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function interleave(Collection $entries, int $limit): array
    {
        $ranked = $entries
            ->groupBy('type')
            ->flatMap(fn (Collection $group) => $group
                ->sortByDesc(fn (array $entry) => [$entry['at'], $entry['id']])
                ->values()
                ->map(fn (array $entry, int $rank) => $entry + ['rank' => $rank]));

        return $ranked
            ->sortBy([
                fn (array $a, array $b) => $b['at'] <=> $a['at'],
                fn (array $a, array $b) => $a['rank'] <=> $b['rank'],
                fn (array $a, array $b) => $a['type'] <=> $b['type'],
            ])
            ->take($limit)
            ->map(fn (array $entry) => Arr::except($entry, 'rank'))
            ->values()
            ->all();
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  callable(mixed): string  $describe
     * @return Collection<int, array<string, mixed>>
     */
    private function activityFrom(Builder $query, string $type, callable $describe, string $noun): Collection
    {
        return $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_PER_SOURCE)
            ->get()
            ->map(function (Model $row) use ($type, $describe, $noun) {
                // Equal timestamps mean the row has not been touched since it
                // was written, so this is the creation itself.
                $created = $row->created_at?->equalTo($row->updated_at) ?? true;

                return [
                    'id' => $row->getKey(),
                    'type' => $type,
                    'action' => $created ? 'created' : 'updated',
                    'label' => $created
                        ? $this->createdLabel($noun).' : '.$describe($row)
                        : $this->updatedLabel($noun).' : '.$describe($row),
                    'at' => $row->updated_at?->toIso8601String(),
                ];
            });
    }

    /**
     * Absences and lateness only. A feed of thousands of "présent" marks would
     * bury everything else and tell nobody anything.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function attendanceActivity(): Collection
    {
        return Attendance::with(['student', 'lesson'])
            ->whereIn('status', [Attendance::ABSENT, Attendance::LATE])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_PER_SOURCE)
            ->get()
            ->map(fn (Attendance $mark) => [
                'id' => $mark->id,
                'type' => 'attendance',
                'action' => 'recorded',
                'label' => sprintf(
                    '%s enregistré%s : %s%s',
                    $mark->status === Attendance::ABSENT ? 'Absence' : 'Retard',
                    $mark->status === Attendance::ABSENT ? 'e' : '',
                    $mark->student?->name ?? 'élève supprimé',
                    $mark->lesson ? ' ('.$mark->lesson->title.')' : '',
                ),
                'at' => $mark->updated_at?->toIso8601String(),
            ]);
    }

    /** "Nouvel élève", "Nouvelle classe" - French needs the article to agree. */
    private function createdLabel(string $noun): string
    {
        return match ($noun) {
            'classe' => 'Nouvelle classe ajoutée',
            'élève' => 'Nouvel élève ajouté',
            'enseignant' => 'Nouvel enseignant ajouté',
            'cours' => 'Nouveau cours créé',
            default => 'Nouvel élément : '.$noun,
        };
    }

    /**
     * mb_convert_case, not ucfirst: the latter works a byte at a time and would
     * mangle a leading accent.
     */
    private function updatedLabel(string $noun): string
    {
        $capitalised = mb_convert_case(mb_substr($noun, 0, 1), MB_CASE_UPPER).mb_substr($noun, 1);

        return $capitalised.' modifié'.($noun === 'classe' ? 'e' : '');
    }
}
