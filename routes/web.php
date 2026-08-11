<?php

use App\Http\Controllers\LessonController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::apiResource('teachers', TeacherController::class);
    Route::apiResource('school-classes', SchoolClassController::class);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('lessons', LessonController::class);
    Route::apiResource('timetables', TimetableController::class);
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|up).*$');
