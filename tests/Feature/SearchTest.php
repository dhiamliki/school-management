<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The header search: one lookup across pupils, teachers and classes. */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_it_finds_pupils_teachers_and_classes_in_one_call(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'Bouazizi 3A']);
        Student::factory()->create(['name' => 'Salma Bouazizi', 'school_class_id' => $class->id]);
        Teacher::factory()->create(['name' => 'Amel Bouazizi', 'subject' => 'Mathématiques']);

        $hits = collect($this->getJson('/api/search?q=Bouazizi')->assertOk()->json('data'));

        $this->assertSame(
            ['student', 'teacher', 'school_class'],
            $hits->pluck('type')->all(),
            'results should be grouped pupils, then teachers, then classes',
        );

        $student = $hits->firstWhere('type', 'student');
        $this->assertSame('Salma Bouazizi', $student['label']);
        $this->assertSame('Bouazizi 3A', $student['meta']);
        $this->assertSame('/students/'.$student['id'], $student['to']);

        $this->assertSame('Mathématiques', $hits->firstWhere('type', 'teacher')['meta']);
        $this->assertSame('1 élèves', $hits->firstWhere('type', 'school_class')['meta']);
    }

    public function test_a_pupil_is_found_by_matricule(): void
    {
        Student::factory()->create(['name' => 'Salma Trabelsi', 'matricule' => 'E-00420']);

        $this->getJson('/api/search?q=00420')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Salma Trabelsi');
    }

    public function test_it_matches_anywhere_in_the_name(): void
    {
        Teacher::factory()->create(['name' => 'Hédi Jelassi']);

        $this->getJson('/api/search?q=elass')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Hédi Jelassi');
    }

    /** One character matches most of the school and tells nobody anything. */
    public function test_a_single_character_returns_nothing(): void
    {
        Student::factory()->create(['name' => 'Salma Trabelsi']);

        $this->getJson('/api/search?q=S')->assertOk()->assertJsonCount(0, 'data');
    }

    /** A wildcard the user typed is a character to look for, not a pattern. */
    public function test_like_wildcards_are_escaped(): void
    {
        Student::factory()->create(['name' => 'Salma Trabelsi']);

        $this->getJson('/api/search?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_term_is_required(): void
    {
        $this->getJson('/api/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_search_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/search?q=Bouazizi')->assertUnauthorized();
    }
}
