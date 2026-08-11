<?php

namespace Database\Seeders;

use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeacherSeeder extends Seeder
{
    /**
     * One teacher per subject taught at the school.
     *
     * @var array<string, string>
     */
    protected array $teachers = [
        'Mathématiques' => 'Salma Ben Ali',
        'Physique' => 'Karim Trabelsi',
        'Français' => 'Amel Bouazizi',
        'Anglais' => 'Nadia Chaabane',
        'Histoire' => 'Youssef Mansour',
        'SVT' => 'Leïla Gharbi',
    ];

    /**
     * Seed the teachers table.
     */
    public function run(): void
    {
        foreach ($this->teachers as $subject => $name) {
            Teacher::factory()->subject($subject)->create([
                'name' => $name,
                'email' => $this->emailFor($name),
            ]);
        }
    }

    /**
     * Build a school address from the teacher's name.
     */
    protected function emailFor(string $name): string
    {
        return Str::slug($name, '.').'@ecole.tn';
    }
}
