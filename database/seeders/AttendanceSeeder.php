<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Timetable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceSeeder extends Seeder
{
    /**
     * How many school days of history to lay down.
     */
    protected int $schoolDays = Attendance::RECENT_DAYS;

    /**
     * How many pupils are given a deliberately poor record, so that at-risk
     * queries have something real to surface.
     */
    protected int $atRiskStudents = 8;

    /**
     * French weekday names, indexed the way Carbon numbers them.
     *
     * @var array<int, string>
     */
    protected const WEEKDAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
    ];

    /**
     * Seed a term's worth of attendance.
     *
     * Marks only ever land on a lesson that the class really has on that
     * weekday, so the history matches the timetable: no Sunday rows, and no
     * Samedi afternoons, because the timetable has none to match.
     */
    public function run(): void
    {
        $lessonsByClassAndDay = $this->lessonsByClassAndDay();

        if ($lessonsByClassAndDay === []) {
            return;
        }

        $students = Student::whereNotNull('school_class_id')->get();

        if ($students->isEmpty()) {
            return;
        }

        $atRisk = $students->shuffle()->take($this->atRiskStudents)->pluck('id')->flip();
        $days = $this->recentSchoolDays();
        $rows = [];
        $now = Carbon::now()->toDateTimeString();

        foreach ($students as $student) {
            // A pupil's own baseline: most sit between 90% and 97% present,
            // the at-risk cohort a long way below that.
            $absentChance = $atRisk->has($student->id)
                ? fake()->randomFloat(2, 0.18, 0.30)
                : fake()->randomFloat(2, 0.02, 0.07);

            $lateChance = fake()->randomFloat(2, 0.01, 0.05);

            foreach ($days as $day) {
                $lessons = $lessonsByClassAndDay[$student->school_class_id][$day['weekday']] ?? [];

                // A subject can run twice in one day now that the timetable
                // carries real weekly hours, but attendance is unique on
                // (pupil, lesson, day) - one mark per subject per day, which
                // is how a register is actually kept.
                foreach (array_unique($lessons) as $lessonId) {
                    $rows[] = [
                        'student_id' => $student->id,
                        'lesson_id' => $lessonId,
                        'date' => $day['date'],
                        'status' => $this->roll($absentChance, $lateChance),
                        'note' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Insert per pupil rather than building one giant array.
            if (count($rows) >= 2000) {
                $this->flush($rows);
            }
        }

        $this->flush($rows);
    }

    /**
     * Pick a status for one lesson.
     */
    private function roll(float $absentChance, float $lateChance): string
    {
        $draw = fake()->randomFloat(4, 0, 1);

        if ($draw < $absentChance) {
            return Attendance::ABSENT;
        }

        if ($draw < $absentChance + $lateChance) {
            return Attendance::LATE;
        }

        return Attendance::PRESENT;
    }

    /**
     * Write the buffered rows and empty the buffer.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array &$rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }

        $rows = [];
    }

    /**
     * The last N teaching days, oldest first. Sunday is skipped: the school
     * week runs Lundi to Samedi.
     *
     * @return list<array{date: string, weekday: string}>
     */
    private function recentSchoolDays(): array
    {
        $days = [];
        $cursor = Carbon::today();

        while (count($days) < $this->schoolDays) {
            $weekday = self::WEEKDAYS[$cursor->dayOfWeekIso] ?? null;

            if ($weekday !== null) {
                $days[] = ['date' => $cursor->toDateString(), 'weekday' => $weekday];
            }

            $cursor = $cursor->subDay();
        }

        return array_reverse($days);
    }

    /**
     * Every class's lesson ids, grouped by the weekday they are taught on.
     *
     * @return array<int, array<string, list<int>>>
     */
    private function lessonsByClassAndDay(): array
    {
        $lessons = Lesson::whereNotNull('school_class_id')->get()->keyBy('id');
        $grouped = [];

        foreach (Timetable::all() as $slot) {
            $lesson = $lessons->get($slot->lesson_id);

            if ($lesson === null) {
                continue;
            }

            $grouped[$lesson->school_class_id][$slot->day_of_week][] = $lesson->id;
        }

        return $grouped;
    }
}
