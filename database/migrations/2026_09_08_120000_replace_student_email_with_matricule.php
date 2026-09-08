<?php

use App\Models\Student;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pupils here are six to twelve years old and are enrolled by the school.
 * They do not have email addresses, so the column is dropped and replaced by
 * the identifier a Tunisian school actually issues: a matricule.
 *
 * Teachers keep their addresses - they are staff, and those accounts are real.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullable, because the number is derived from the row id and so
        // cannot be known until after the insert - see
        // StudentController::store(). A unique index still allows several
        // nulls on both MySQL and SQLite, so nothing collides in the gap.
        Schema::table('students', function (Blueprint $table) {
            $table->string('matricule')->nullable()->unique()->after('name');
        });

        $this->backfillMatricules();

        // The index has to go before the column on MySQL, and dropping it in
        // its own statement keeps SQLite's table rebuild off the same pass.
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_email_unique');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
        });

        // Rebuild an address from the name, the way the factory used to, so
        // the unique index below has something distinct to sit on.
        foreach (DB::table('students')->select('id', 'name')->orderBy('id')->cursor() as $student) {
            DB::table('students')
                ->where('id', $student->id)
                ->update(['email' => Str::slug($student->name, '.').$student->id.'@ecole.tn']);
        }

        Schema::table('students', function (Blueprint $table) {
            $table->unique('email');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_matricule_unique');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('matricule');
        });
    }

    /**
     * Number the existing pupils. The id is already unique, so deriving the
     * matricule from it needs no collision check.
     */
    private function backfillMatricules(): void
    {
        foreach (DB::table('students')->select('id')->orderBy('id')->cursor() as $student) {
            DB::table('students')
                ->where('id', $student->id)
                ->update(['matricule' => Student::matriculeFor($student->id)]);
        }
    }
};
