<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksTimetableConflicts;
use App\Models\Timetable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimetableRequest extends FormRequest
{
    use ChecksTimetableConflicts;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lesson_id' => ['required', 'exists:lessons,id'],
            'day_of_week' => ['required', 'string', Rule::in(Timetable::DAYS)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string'],
        ];
    }
}
