<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // Null means a full-day mark, so losing a lesson must not lose it.
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date');
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->timestamps();

            // One mark per pupil per lesson per day. NULLs compare distinct in
            // a unique index, so full-day rows (null lesson_id) slip through
            // this and are kept unique by AttendanceController::bulk() instead.
            $table->unique(['student_id', 'lesson_id', 'date']);

            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
