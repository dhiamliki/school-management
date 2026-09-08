<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
    ];

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** Reached through their lessons, so distinct() matters. */
    public function schoolClasses(): HasManyThrough
    {
        return $this->hasManyThrough(
            SchoolClass::class,
            Lesson::class,
            'teacher_id',
            'id',
            'id',
            'school_class_id',
        );
    }
}
