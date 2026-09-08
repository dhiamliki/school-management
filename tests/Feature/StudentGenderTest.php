<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\TunisianNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The optional gender on the roster: how it is validated, what the factory
 * records, and the line between recovering the seed's own intent and guessing
 * at a name somebody typed in.
 */
class StudentGenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_factory_records_a_gender_matching_the_name_it_chose(): void
    {
        foreach (Student::factory()->count(40)->create() as $student) {
            $this->assertSame(
                TunisianNames::genderFor($student->name),
                $student->gender,
                "{$student->name} was recorded with a gender its given name does not carry",
            );
            $this->assertNotNull($student->gender, 'every factory name comes from the gendered list');
        }
    }

    /**
     * The list is an authoring aid, not a name-to-gender oracle. A name it
     * does not know gets null rather than a guess - the same reasoning that
     * keeps gender off the teacher roster entirely.
     */
    public function test_an_unknown_given_name_is_left_unrecorded(): void
    {
        $this->assertNull(TunisianNames::genderFor('Alexandra Petrova'));
        $this->assertNull(TunisianNames::genderFor(''));

        // A surname of more than one word must not throw the match off.
        $this->assertSame(TunisianNames::MALE, TunisianNames::genderFor('Youssef Ben Ali'));
        $this->assertSame(TunisianNames::FEMALE, TunisianNames::genderFor('Zeineb Ben Salah'));
    }

    public function test_gender_is_optional_when_creating_a_pupil(): void
    {
        $class = SchoolClass::factory()->create();

        $this->postJson('/api/students', [
            'name' => 'Sans Genre',
            'school_class_id' => $class->id,
        ])->assertCreated()->assertJsonPath('data.gender', null);
    }

    public function test_gender_is_accepted_and_returned(): void
    {
        $response = $this->postJson('/api/students', [
            'name' => 'Salma Trabelsi',
            'gender' => Student::FEMALE,
        ])->assertCreated()->assertJsonPath('data.gender', 'F');

        $this->patchJson('/api/students/'.$response->json('data.id'), [
            'name' => 'Salma Trabelsi',
            'gender' => Student::MALE,
        ])->assertOk()->assertJsonPath('data.gender', 'M');
    }

    public function test_an_unrecognised_gender_is_rejected(): void
    {
        $this->postJson('/api/students', [
            'name' => 'Valeur Invalide',
            'gender' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('gender');
    }
}
