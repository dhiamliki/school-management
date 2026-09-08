<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AIChatController extends Controller
{
    private const HISTORY_TURNS = 10;

    private const TIMEOUT_SECONDS = 15;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $key = config('services.gemini.key');

        if (blank($key)) {
            return response()->json([
                'message' => "L'assistant n'est pas configuré : la clé GEMINI_API_KEY est absente du fichier .env.",
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $model = config('services.gemini.model');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => $key])
                ->asJson()
                ->post($endpoint, [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemInstruction()]],
                    ],
                    'contents' => $this->contents(
                        $validated['message'],
                        $validated['history'] ?? []
                    ),
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 1024,
                    ],
                ]);
        } catch (Throwable $exception) {
            // Connection refused, DNS failure, or the 15s timeout elapsing.
            Log::warning('Gemini request failed to complete.', ['exception' => $exception->getMessage()]);

            return response()->json([
                'message' => "L'assistant n'a pas répondu à temps. Réessayez dans un instant.",
            ], Response::HTTP_GATEWAY_TIMEOUT);
        }

        if ($response->failed()) {
            // The upstream error body can echo request details back, so only
            // the status and a generic message reach the client.
            Log::warning('Gemini returned an error response.', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return response()->json([
                'message' => $response->status() === 429
                    ? "Le quota gratuit de l'assistant est atteint. Réessayez plus tard."
                    : "L'assistant est momentanément indisponible (erreur {$response->status()}).",
            ], Response::HTTP_BAD_GATEWAY);
        }

        $reply = $this->textFrom($response->json());

        if ($reply === null) {
            Log::warning('Gemini returned no usable text part.', ['body' => $response->json()]);

            return response()->json([
                'message' => "L'assistant n'a pas pu formuler de réponse. Reformulez votre question.",
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['reply' => $reply]);
    }

    /**
     * Gemini calls the assistant side "model".
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function contents(string $message, array $history): array
    {
        $contents = [];

        foreach (array_slice($history, -self::HISTORY_TURNS) as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        return $contents;
    }

    /**
     * A successful response can still carry no text: a safety block, or the
     * token budget spent before any output.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function textFrom(?array $body): ?string
    {
        $parts = data_get($body, 'candidates.0.content.parts', []);

        $text = collect($parts)
            ->pluck('text')
            ->filter(fn ($part) => filled($part))
            ->implode("\n");

        return filled($text) ? trim($text) : null;
    }

    /**
     * The system prompt: the rules, then the whole school as plain text.
     * Aggregates first, raw rows last: the model cites a stated fact far more
     * reliably than one it has to derive.
     */
    private function systemInstruction(): string
    {
        return implode("\n", [
            'Tu es un assistant pour le personnel administratif de cette école primaire tunisienne.',
            "Réponds uniquement à partir des DONNÉES DE L'ÉCOLE ci-dessous.",
            "N'invente jamais un élève, un enseignant, une classe, un cours ou un créneau qui n'y figure pas.",
            'Réponds dans la langue de la question, de façon brève et factuelle.',
            '',
            'Comment lire ces données :',
            "- Pour un nombre, cite le chiffre déjà calculé dans EFFECTIFS, PROGRAMME PAR NIVEAU ou VOLUME HORAIRE PAR CLASSE. Ne compte jamais toi-même les lignes d'une liste.",
            "- PROGRAMME PAR NIVEAU et MATIÈRES PAR NIVEAU sont exhaustifs. Si une matière n'y figure pas pour un niveau, elle n'y est pas enseignée : tu peux alors répondre non avec certitude.",
            '- Une classe nommée "5ème année A" est de niveau 5, et son niveau est de toute façon écrit sur chaque ligne qui la concerne.',
            "- Pour savoir ce qu'enseigne un enseignant, lis sa ligne dans ENSEIGNANTS : le champ \"matières\" liste ce qu'il enseigne réellement, d'après ses cours. Un intitulé de poste comme \"Enseignement polyvalent\" ne nomme aucune matière ; ne t'en sers jamais pour répondre à une question de matière.",
            "- EMPLOI DU TEMPS donne une ligne par classe et par jour, créneaux dans l'ordre des horaires.",
            'Si la réponse ne se trouve vraiment pas dans ces données, dis simplement que tu ne le sais pas.',
            '',
            "DONNÉES DE L'ÉCOLE",
            '',
            $this->countsContext(),
            '',
            $this->curriculumContext(),
            '',
            $this->schoolContext(),
        ]);
    }

    /**
     * Every countable figure, worked out in SQL. The model miscounts lists:
     * it has listed seven pupils correctly and then reported six.
     */
    private function countsContext(): string
    {
        $perClass = SchoolClass::withCount('students')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $class) => sprintf('%s | %d élèves', $class->name, $class->students_count));

        $unassigned = Student::whereNull('school_class_id')->count();

        if ($unassigned > 0) {
            $perClass->push(sprintf('sans classe | %d élèves', $unassigned));
        }

        return implode("\n", [
            'EFFECTIFS (chiffres exacts, déjà calculés - à citer tels quels)',
            '',
            'Effectifs par classe:',
            $perClass->isEmpty() ? '(aucune classe)' : $perClass->implode("\n"),
            '',
            'Total élèves: '.Student::count(),
            'Total enseignants: '.Teacher::count(),
            'Total classes: '.SchoolClass::count(),
            'Total cours: '.Lesson::count(),
            'Total créneaux: '.Timetable::count(),
        ]);
    }

    /**
     * The curriculum in the three shapes staff ask about: hours per subject per
     * grade, the grades each subject covers, and the weekly total per class.
     * Left to join these itself the model gave up and said it did not know.
     */
    private function curriculumContext(): string
    {
        $rows = $this->timetableSlots()
            ->groupBy('school_classes.level', 'lessons.subject')
            ->get([
                'school_classes.level as level',
                'lessons.subject as subject',
                DB::raw('count(timetables.id) as slots'),
                DB::raw('count(distinct school_classes.id) as classes'),
            ]);

        if ($rows->isEmpty()) {
            return "PROGRAMME PAR NIVEAU\n(aucun créneau enregistré)";
        }

        // Hours are quoted per class: a grade with three classes timetables
        // every subject three times over, and staff ask about one class's week.
        $byLevel = $rows->mapToGroups(fn ($row) => [
            (int) $row->level => [
                'subject' => $row->subject ?: 'matière non renseignée',
                'hours' => (int) round($row->slots / max(1, (int) $row->classes)),
            ],
        ])->sortKeys();

        $levels = $byLevel->keys();

        $programme = $byLevel->map(function ($subjects, $level) {
            $subjects = collect($subjects)->sortByDesc('hours');

            return sprintf(
                '- niveau %d (%s) - %dh/semaine par classe: %s',
                $level,
                $this->gradeName($level),
                $subjects->sum('hours'),
                $subjects->map(fn ($row) => "{$row['subject']} {$row['hours']}h")->implode(', '),
            );
        });

        // The same data read the other way round: the grades a subject is
        // taught in, and the grades it is absent from, so that "does grade N
        // study X" has a stated answer whichever way it falls.
        $bySubject = $rows
            ->groupBy(fn ($row) => $row->subject ?: 'matière non renseignée')
            ->map(fn ($subjectRows) => $subjectRows
                ->map(fn ($row) => (int) $row->level)
                ->unique()
                ->sort()
                ->values())
            ->sortKeys()
            ->map(function ($taught, $subject) use ($levels) {
                $absent = $levels->diff($taught);

                return sprintf(
                    '- %s: enseignée en %s.%s',
                    $subject,
                    $taught->map(fn ($level) => $this->gradeName($level))->implode(', '),
                    $absent->isEmpty()
                        ? ' Enseignée à tous les niveaux.'
                        : sprintf(
                            " Première année d'enseignement: %s. Pas enseignée en %s.",
                            $this->gradeName($taught->first()),
                            $absent->map(fn ($level) => $this->gradeName($level))->implode(', '),
                        ),
                );
            });

        $perClass = $this->timetableSlots()
            ->groupBy('school_classes.id', 'school_classes.name', 'school_classes.level')
            ->orderBy('school_classes.name')
            ->get([
                'school_classes.name as name',
                'school_classes.level as level',
                DB::raw('count(timetables.id) as slots'),
            ])
            ->map(fn ($row) => sprintf(
                '- %s | niveau %d | %dh de cours par semaine',
                $row->name,
                (int) $row->level,
                (int) $row->slots,
            ));

        return implode("\n", [
            "PROGRAMME PAR NIVEAU (exhaustif, déjà calculé - une matière absente de la ligne d'un niveau n'y est pas enseignée)",
            '',
            $programme->implode("\n"),
            '',
            'MATIÈRES PAR NIVEAU (dans quelles années chaque matière est enseignée)',
            '',
            $bySubject->implode("\n"),
            '',
            'VOLUME HORAIRE PAR CLASSE (chiffres exacts, déjà calculés)',
            '',
            $perClass->implode("\n"),
        ]);
    }

    private function schoolContext(): string
    {
        $sections = [];

        $sections[] = "CLASSES (nom | niveau | capacité)\n".$this->lines(
            SchoolClass::orderBy('name')->get(),
            fn (SchoolClass $class) => sprintf(
                '%s | %s | %s',
                $class->name,
                $class->level ? 'niveau '.$class->level : 'niveau non renseigné',
                $class->capacity ? $class->capacity.' places' : 'capacité non renseignée',
            ),
        );

        $sections[] = $this->teacherContext();

        $sections[] = "ÉLÈVES (nom | classe | date de naissance)\n".$this->lines(
            Student::with('schoolClass')->orderBy('name')->get(),
            fn (Student $student) => sprintf(
                '%s | %s | %s',
                $student->name,
                $student->schoolClass?->name ?: 'sans classe',
                $student->birth_date?->format('Y-m-d') ?: 'date non renseignée',
            ),
        );

        $sections[] = "COURS (titre | matière | enseignant | classe)\n".$this->lines(
            Lesson::with(['teacher', 'schoolClass'])->orderBy('title')->get(),
            fn (Lesson $lesson) => sprintf(
                '%s | %s | %s | %s',
                $lesson->title,
                $lesson->subject ?: 'matière non renseignée',
                $lesson->teacher?->name ?: 'sans enseignant',
                $lesson->schoolClass?->name ?: 'sans classe',
            ),
        );

        $sections[] = $this->timetableContext();

        return implode("\n\n", $sections);
    }

    /**
     * What each teacher actually teaches, derived from their lessons. The
     * subject column can hold a job title ("Enseignement polyvalent") which
     * names nothing teachable, so it is never used to answer a subject question.
     */
    private function teacherContext(): string
    {
        // A lesson with no slot on the timetable still says what its teacher
        // teaches, so this join is left - such a lesson just adds zero hours.
        $subjects = DB::table('lessons')
            ->leftJoin('timetables', 'timetables.lesson_id', '=', 'lessons.id')
            ->whereNotNull('lessons.teacher_id')
            ->groupBy('lessons.teacher_id', 'lessons.subject')
            ->get([
                'lessons.teacher_id as teacher_id',
                'lessons.subject as subject',
                DB::raw('count(timetables.id) as slots'),
            ])
            ->groupBy('teacher_id');

        $classes = DB::table('lessons')
            ->join('school_classes', 'school_classes.id', '=', 'lessons.school_class_id')
            ->whereNotNull('lessons.teacher_id')
            ->distinct()
            ->orderBy('school_classes.name')
            ->get(['lessons.teacher_id as teacher_id', 'school_classes.name as name'])
            ->groupBy('teacher_id');

        return "ENSEIGNANTS (nom | matières réellement enseignées, avec les heures | classes | charge hebdomadaire | contact)\n".$this->lines(
            Teacher::orderBy('name')->get(),
            function (Teacher $teacher) use ($subjects, $classes) {
                $taught = collect($subjects->get($teacher->id, []));

                return sprintf(
                    '%s | matières: %s | classes: %s | %dh/semaine | %s | %s',
                    $teacher->name,
                    $taught->isEmpty()
                        ? 'aucun cours attribué'
                        : $taught
                            ->sortByDesc('slots')
                            ->map(fn ($row) => sprintf('%s %dh', $row->subject ?: 'matière non renseignée', (int) $row->slots))
                            ->implode(', '),
                    collect($classes->get($teacher->id, []))->pluck('name')->implode(', ') ?: 'aucune classe',
                    $taught->sum(fn ($row) => (int) $row->slots),
                    $teacher->email,
                    $teacher->phone ?: 'téléphone non renseigné',
                );
            },
        );
    }

    /**
     * Grouped by class and day. A flat dump of 432 slots answered nothing:
     * lesson titles are not unique and the line named neither class nor teacher.
     */
    private function timetableContext(): string
    {
        $slots = Timetable::with('lesson.schoolClass')->orderBy('start_time')->get();

        if ($slots->isEmpty()) {
            return "EMPLOI DU TEMPS (classe | jour: horaire matière - cours (salle))\n(aucun enregistrement)";
        }

        $dayOrder = array_flip(Timetable::DAYS);

        $lines = $slots
            ->groupBy(fn (Timetable $slot) => $slot->lesson?->schoolClass?->name ?: 'sans classe')
            ->sortKeys()
            ->flatMap(fn ($classSlots, $className) => $classSlots
                ->groupBy('day_of_week')
                ->sortBy(fn ($daySlots, $day) => $dayOrder[$day] ?? count($dayOrder))
                ->map(fn ($daySlots, $day) => sprintf(
                    '%s | %s: %s',
                    $className,
                    $day,
                    $daySlots->map(fn (Timetable $slot) => sprintf(
                        '%s %s - %s (%s)',
                        substr((string) $slot->start_time, 0, 5),
                        $slot->lesson?->subject ?: 'matière non renseignée',
                        $slot->lesson?->title ?: 'cours supprimé',
                        $slot->room ?: 'salle non renseignée',
                    ))->implode('; '),
                ))
                ->values());

        return "EMPLOI DU TEMPS (classe | jour: horaire matière - cours (salle))\n- ".$lines->implode("\n- ");
    }

    private function timetableSlots(): Builder
    {
        return DB::table('timetables')
            ->join('lessons', 'lessons.id', '=', 'timetables.lesson_id')
            ->join('school_classes', 'school_classes.id', '=', 'lessons.school_class_id');
    }

    private function gradeName(int $level): string
    {
        return $level === 1 ? '1ère année' : $level.'ème année';
    }

    /** @param  EloquentCollection<int, covariant \Illuminate\Database\Eloquent\Model>  $rows */
    private function lines(EloquentCollection $rows, callable $format): string
    {
        if ($rows->isEmpty()) {
            return '(aucun enregistrement)';
        }

        return $rows->map(fn ($row) => '- '.$format($row))->implode("\n");
    }
}
