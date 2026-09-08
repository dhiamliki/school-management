<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract of the five CRUD endpoints: the shape their API Resources expose,
 * the Form Request rules behind store/update, and the pagination envelope.
 */
class CrudResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The page size the API falls back to, and the ceiling it clamps to.
     */
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 600;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function resourceProvider(): array
    {
        return [
            'teachers' => ['teachers'],
            'school classes' => ['school-classes'],
            'students' => ['students'],
            'lessons' => ['lessons'],
            'timetables' => ['timetables'],
            'attendances' => ['attendances'],
        ];
    }

    /**
     * Per-endpoint expectations. Payload builders run inside the test so they
     * can lean on rows created there.
     *
     * @return array{
     *     model: class-string<Model>,
     *     index_keys: list<string>,
     *     show_keys: list<string>,
     *     valid: callable,
     *     invalid: list<array<string, mixed>>,
     * }
     */
    private function spec(string $endpoint): array
    {
        $specs = [
            'teachers' => [
                'model' => Teacher::class,
                'index_keys' => ['id', 'name', 'email', 'phone', 'subject', 'created_at', 'updated_at'],
                'show_keys' => [
                    'id', 'name', 'email', 'phone', 'subject', 'created_at', 'updated_at',
                    'lessons', 'lessons_count', 'classes',
                ],
                'valid' => fn () => [
                    'name' => 'Nouvelle Enseignante',
                    'email' => 'nouvelle.enseignante@ecole.tn',
                    'phone' => '+216 20 000 000',
                    'subject' => 'Physique',
                ],
                'invalid' => [
                    [],
                    ['name' => 'Sans email'],
                    ['name' => 'Mauvais email', 'email' => 'pas-un-email'],
                    ['name' => str_repeat('a', 256), 'email' => 'trop.long@ecole.tn'],
                ],
            ],
            'school-classes' => [
                'model' => SchoolClass::class,
                'index_keys' => ['id', 'name', 'level', 'capacity', 'created_at', 'updated_at'],
                'show_keys' => [
                    'id', 'name', 'level', 'capacity', 'created_at', 'updated_at',
                    'students', 'lessons', 'attendance',
                ],
                'valid' => fn () => ['name' => '4ème C', 'level' => 'Quatrième', 'capacity' => 30],
                'invalid' => [
                    [],
                    ['name' => 'Capacité nulle', 'capacity' => 0],
                    ['name' => 'Capacité texte', 'capacity' => 'beaucoup'],
                ],
            ],
            'students' => [
                'model' => Student::class,
                'index_keys' => [
                    'id', 'name', 'matricule', 'birth_date', 'gender', 'school_class_id',
                    'created_at', 'updated_at', 'school_class', 'attendance',
                ],
                'show_keys' => [
                    'id', 'name', 'matricule', 'birth_date', 'gender', 'school_class_id',
                    'created_at', 'updated_at', 'school_class', 'attendance',
                    'attendance_history',
                ],
                'valid' => fn () => [
                    'name' => 'Nouvel Élève',
                    'matricule' => 'E-90001',
                    'birth_date' => '2010-03-04',
                    'school_class_id' => SchoolClass::factory()->create()->id,
                ],
                'invalid' => [
                    [],
                    ['name' => str_repeat('a', 256)],
                    ['name' => 'Date invalide', 'birth_date' => 'hier'],
                    ['name' => 'Classe absente', 'school_class_id' => 99999],
                ],
            ],
            'lessons' => [
                'model' => Lesson::class,
                'index_keys' => [
                    'id', 'title', 'subject', 'teacher_id', 'school_class_id',
                    'created_at', 'updated_at', 'teacher', 'school_class',
                ],
                'show_keys' => [
                    'id', 'title', 'subject', 'teacher_id', 'school_class_id',
                    'created_at', 'updated_at', 'teacher', 'school_class', 'timetables',
                ],
                'valid' => fn () => [
                    'title' => 'Nouveau cours',
                    'subject' => 'Physique',
                    'teacher_id' => Teacher::factory()->create()->id,
                    'school_class_id' => SchoolClass::factory()->create()->id,
                ],
                'invalid' => [
                    [],
                    ['title' => 'Enseignant absent', 'teacher_id' => 99999],
                    ['title' => 'Classe absente', 'school_class_id' => 99999],
                ],
            ],
            'timetables' => [
                'model' => Timetable::class,
                'index_keys' => [
                    'id', 'lesson_id', 'day_of_week', 'start_time', 'end_time', 'room',
                    'created_at', 'updated_at', 'lesson',
                ],
                'show_keys' => [
                    'id', 'lesson_id', 'day_of_week', 'start_time', 'end_time', 'room',
                    'created_at', 'updated_at', 'lesson',
                ],
                'valid' => fn () => [
                    'lesson_id' => Lesson::factory()->create()->id,
                    'day_of_week' => 'Jeudi',
                    'start_time' => '14:00',
                    'end_time' => '15:00',
                    'room' => 'Salle 210',
                ],
                'invalid' => [
                    [],
                    // end_time must come after start_time
                    ['lesson_id' => 1, 'day_of_week' => 'Jeudi', 'start_time' => '10:00', 'end_time' => '09:00'],
                    // times must be H:i, not H:i:s
                    ['lesson_id' => 1, 'day_of_week' => 'Jeudi', 'start_time' => '08:00:00', 'end_time' => '09:00:00'],
                    ['lesson_id' => 99999, 'day_of_week' => 'Jeudi', 'start_time' => '08:00', 'end_time' => '09:00'],
                ],
            ],
            'attendances' => [
                'model' => Attendance::class,
                'index_keys' => [
                    'id', 'student_id', 'lesson_id', 'date', 'status', 'note',
                    'created_at', 'updated_at', 'student', 'lesson',
                ],
                'show_keys' => [
                    'id', 'student_id', 'lesson_id', 'date', 'status', 'note',
                    'created_at', 'updated_at', 'student', 'lesson',
                ],
                'valid' => fn () => [
                    'student_id' => Student::factory()->create()->id,
                    'lesson_id' => Lesson::factory()->create()->id,
                    'date' => '2026-09-01',
                    'status' => Attendance::ABSENT,
                    'note' => 'justifié par les parents',
                ],
                'invalid' => [
                    [],
                    // status must be one of the three marks
                    ['student_id' => 1, 'date' => '2026-09-01', 'status' => 'peut-être'],
                    ['student_id' => 1, 'date' => 'hier', 'status' => Attendance::PRESENT],
                    ['student_id' => 99999, 'date' => '2026-09-01', 'status' => Attendance::PRESENT],
                    ['student_id' => 1, 'lesson_id' => 99999, 'date' => '2026-09-01', 'status' => Attendance::PRESENT],
                ],
            ],
        ];

        return $specs[$endpoint];
    }

    /**
     * Create one row for the endpoint under test.
     */
    private function makeRow(string $endpoint): mixed
    {
        return $this->spec($endpoint)['model']::factory()->create();
    }

    /**
     * Every submitted field should be on the row. Date and time columns come
     * back from the database padded ('2010-03-04 00:00:00', '14:00:00'), so
     * they are compared on the prefix that was sent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertStored(string $endpoint, mixed $row, array $payload, string $what): void
    {
        foreach ($payload as $field => $value) {
            $stored = (string) $row->getRawOriginal($field);
            $sent = (string) $value;

            $this->assertSame(
                $sent,
                substr($stored, 0, strlen($sent)),
                "{$endpoint}.{$field} should be {$what} (stored: {$stored})",
            );
        }
    }

    #[DataProvider('resourceProvider')]
    public function test_index_returns_a_paginated_envelope(string $endpoint): void
    {
        $this->makeRow($endpoint);

        $response = $this->getJson("/api/{$endpoint}")->assertOk();

        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
        ]);

        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(self::DEFAULT_PER_PAGE, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[DataProvider('resourceProvider')]
    public function test_index_exposes_exactly_the_declared_resource_fields(string $endpoint): void
    {
        $this->makeRow($endpoint);

        $row = $this->getJson("/api/{$endpoint}")->assertOk()->json('data.0');

        // Exact match in both directions: a missing field fails, and so does a
        // raw model attribute leaking in because someone added a column.
        $this->assertEqualsCanonicalizing(
            $this->spec($endpoint)['index_keys'],
            array_keys($row),
            "Unexpected field set on GET /api/{$endpoint}",
        );
    }

    #[DataProvider('resourceProvider')]
    public function test_show_exposes_exactly_the_declared_resource_fields(string $endpoint): void
    {
        $row = $this->makeRow($endpoint);

        $body = $this->getJson("/api/{$endpoint}/{$row->id}")->assertOk()->json();

        $this->assertSame(['data'], array_keys($body), 'show should wrap in a data key');
        $this->assertSame($row->id, $body['data']['id']);
        $this->assertEqualsCanonicalizing(
            $this->spec($endpoint)['show_keys'],
            array_keys($body['data']),
            "Unexpected field set on GET /api/{$endpoint}/{id}",
        );
    }

    #[DataProvider('resourceProvider')]
    public function test_per_page_is_respected_and_clamped(string $endpoint): void
    {
        $this->spec($endpoint)['model']::factory()->count(3)->create();

        // Honoured inside the allowed range.
        $this->getJson("/api/{$endpoint}?per_page=1")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/{$endpoint}?per_page=2")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/{$endpoint}?per_page=".self::MAX_PER_PAGE)
            ->assertOk()
            ->assertJsonPath('meta.per_page', self::MAX_PER_PAGE);

        // Above the ceiling, clamped down.
        foreach ([self::MAX_PER_PAGE + 1, 5000] as $tooBig) {
            $this->getJson("/api/{$endpoint}?per_page={$tooBig}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', self::MAX_PER_PAGE);
        }

        // Nonsense or out-of-range low values fall back to the default.
        foreach (['0', '-5', 'abc', ''] as $bogus) {
            $this->getJson("/api/{$endpoint}?per_page={$bogus}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', self::DEFAULT_PER_PAGE);
        }
    }

    #[DataProvider('resourceProvider')]
    public function test_store_rejects_invalid_payloads(string $endpoint): void
    {
        $spec = $this->spec($endpoint);
        // Some invalid payloads reference id 1 on purpose; make it exist so the
        // failure is the rule under test and not a missing relation.
        $this->makeRow($endpoint);

        $before = $spec['model']::count();

        foreach ($spec['invalid'] as $index => $payload) {
            $this->postJson("/api/{$endpoint}", $payload)
                ->assertStatus(422)
                ->assertJsonStructure(['message', 'errors']);

            $this->assertSame(
                $before,
                $spec['model']::count(),
                "Invalid payload #{$index} for {$endpoint} must not persist a row",
            );
        }
    }

    #[DataProvider('resourceProvider')]
    public function test_store_creates_a_row_and_answers_201(string $endpoint): void
    {
        $spec = $this->spec($endpoint);
        $payload = ($spec['valid'])();

        $response = $this->postJson("/api/{$endpoint}", $payload)->assertCreated();

        $id = $response->json('data.id');
        $this->assertNotNull($id);
        $this->assertEqualsCanonicalizing(
            $spec['index_keys'],
            array_keys($response->json('data')),
            "Unexpected field set on POST /api/{$endpoint}",
        );

        $row = $spec['model']::find($id);
        $this->assertNotNull($row, 'the row should be persisted');

        $this->assertStored($endpoint, $row, $payload, 'stored as submitted');
    }

    #[DataProvider('resourceProvider')]
    public function test_update_changes_the_row(string $endpoint): void
    {
        $spec = $this->spec($endpoint);
        $row = $this->makeRow($endpoint);
        $payload = ($spec['valid'])();

        $this->putJson("/api/{$endpoint}/{$row->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $row->id);

        $row->refresh();

        $this->assertStored($endpoint, $row, $payload, 'updated');
    }

    #[DataProvider('resourceProvider')]
    public function test_update_rejects_invalid_payloads(string $endpoint): void
    {
        $row = $this->makeRow($endpoint);

        $this->putJson("/api/{$endpoint}/{$row->id}", [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    #[DataProvider('resourceProvider')]
    public function test_destroy_removes_the_row(string $endpoint): void
    {
        $spec = $this->spec($endpoint);
        $row = $this->makeRow($endpoint);

        $this->deleteJson("/api/{$endpoint}/{$row->id}")->assertNoContent();

        $this->assertNull($spec['model']::find($row->id));
        $this->getJson("/api/{$endpoint}/{$row->id}")->assertNotFound();
    }

    #[DataProvider('resourceProvider')]
    public function test_endpoints_require_authentication(string $endpoint): void
    {
        $row = $this->makeRow($endpoint);

        // Drop the guard set up in setUp() to act as a guest.
        Auth::forgetGuards();

        $this->getJson("/api/{$endpoint}")->assertUnauthorized();
        $this->getJson("/api/{$endpoint}/{$row->id}")->assertUnauthorized();
        $this->postJson("/api/{$endpoint}", [])->assertUnauthorized();
        $this->putJson("/api/{$endpoint}/{$row->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/{$endpoint}/{$row->id}")->assertUnauthorized();
    }

    /**
     * The email uniqueness rule must ignore the row being updated, so saving a
     * teacher without touching the email is not a duplicate of itself. Pupils
     * have no address - they are numbered instead - so this covers staff only.
     */
    public function test_teacher_email_unique_rule_ignores_the_updated_row(): void
    {
        $teacher = Teacher::factory()->create(['email' => 'occupe@ecole.tn']);
        $other = Teacher::factory()->create(['email' => 'autre@ecole.tn']);

        // Same email, same row: allowed.
        $this->putJson("/api/teachers/{$teacher->id}", [
            'name' => 'Nom modifié',
            'email' => 'occupe@ecole.tn',
        ])->assertOk()->assertJsonPath('data.name', 'Nom modifié');

        // Another row already holds it: rejected.
        $this->putJson("/api/teachers/{$teacher->id}", [
            'name' => 'Nom modifié',
            'email' => $other->email,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // And still rejected on create.
        $this->postJson('/api/teachers', [
            'name' => 'Doublon',
            'email' => 'occupe@ecole.tn',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('occupe@ecole.tn', $teacher->refresh()->email);
    }

    public function test_student_matricule_unique_rule_ignores_the_updated_row(): void
    {
        $student = Student::factory()->create(['matricule' => 'E-00042']);
        $other = Student::factory()->create(['matricule' => 'E-00043']);

        $this->putJson("/api/students/{$student->id}", [
            'name' => 'Nom modifié',
            'matricule' => 'E-00042',
        ])->assertOk()->assertJsonPath('data.name', 'Nom modifié');

        $this->putJson("/api/students/{$student->id}", [
            'name' => 'Nom modifié',
            'matricule' => $other->matricule,
        ])->assertStatus(422)->assertJsonValidationErrors('matricule');

        $this->postJson('/api/students', [
            'name' => 'Doublon',
            'matricule' => 'E-00042',
        ])->assertStatus(422)->assertJsonValidationErrors('matricule');
    }

    /**
     * A pupil enrolled without a number is given one from their row id, so
     * staff never have to invent a matricule at the point of enrolment.
     */
    public function test_a_student_created_without_a_matricule_is_numbered_automatically(): void
    {
        $id = $this->postJson('/api/students', ['name' => 'Sans matricule'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(Student::matriculeFor($id), Student::find($id)->matricule);
    }

    /**
     * Relationships are eager-loaded, so the nested payloads must be present
     * and themselves shaped by their own Resource.
     */
    public function test_nested_relationships_are_serialised_by_their_own_resource(): void
    {
        $timetable = Timetable::factory()->create();
        $lesson = $timetable->lesson;

        $slot = $this->getJson('/api/timetables')->assertOk()->json('data.0');

        $this->assertSame($lesson->id, $slot['lesson']['id']);
        $this->assertSame($lesson->teacher->name, $slot['lesson']['teacher']['name']);
        $this->assertSame($lesson->schoolClass->name, $slot['lesson']['school_class']['name']);

        // Nested teacher is a TeacherResource, not a raw model dump.
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'phone', 'subject', 'created_at', 'updated_at'],
            array_keys($slot['lesson']['teacher']),
        );

        // A student with no class serialises the relation as null, not missing.
        $student = Student::factory()->create(['school_class_id' => null]);
        $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.school_class', null);
    }

    /**
     * whenLoaded means a relation that was not eager-loaded is absent rather
     * than triggering a lazy query per row.
     */
    public function test_relations_that_are_not_loaded_are_omitted(): void
    {
        Teacher::factory()->has(Lesson::factory()->count(2))->create();

        // The teachers index does not load lessons.
        $row = $this->getJson('/api/teachers')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('lessons', $row);

        // show() does.
        $this->getJson('/api/teachers/'.Teacher::first()->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.lessons');
    }
}
