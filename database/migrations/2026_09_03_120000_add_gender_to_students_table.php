<?php

use App\Support\TunisianNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an optional gender to the roster, and fill it in for the pupils the
     * factory already invented.
     *
     * Nullable on purpose. A school that types a pupil in by hand may not have
     * the field to hand, and "non renseigné" is an honest answer the dashboard
     * can show; a default of 'M' would quietly invent data.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->char('gender', 1)->nullable()->after('birth_date');
        });

        $this->backfillSeededPupils();
    }

    /**
     * Set the gender for pupils whose given name came from the factory's own
     * list.
     *
     * This is not name-guessing: TunisianNames is the list the factory picks
     * from, so this recovers the gender the generator already had in mind and
     * stores it. Any name that is not in that list - a real pupil entered by
     * the school - is left null rather than assigned a coin flip.
     *
     * Written as one UPDATE per gender rather than a row-by-row loop: this
     * runs against a table with hundreds of pupils, and there is no reason to
     * pay a round trip each.
     */
    private function backfillSeededPupils(): void
    {
        foreach ([
            TunisianNames::MALE => TunisianNames::MALE_FIRST_NAMES,
            TunisianNames::FEMALE => TunisianNames::FEMALE_FIRST_NAMES,
        ] as $gender => $firstNames) {
            DB::table('students')
                ->whereNull('gender')
                ->where(function ($query) use ($firstNames) {
                    foreach ($firstNames as $first) {
                        // The given name is the first word; surnames such as
                        // "Ben Ali" are more than one, so an exact match on
                        // the whole column would never hit.
                        $query->orWhere('name', 'like', $first.' %');
                    }
                })
                ->update(['gender' => $gender]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
