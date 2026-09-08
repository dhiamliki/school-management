<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRequest extends FormRequest
{
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
            'student_id' => ['required', 'exists:students,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id', $this->uniqueMark()],
            'date' => ['required', 'date'],
            'status' => ['required', 'string', Rule::in(Attendance::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The unique index already refuses a duplicate mark; checking here turns
     * a 500 into a 422.
     */
    protected function uniqueMark(): mixed
    {
        if ($this->input('lesson_id') === null) {
            return 'nullable';
        }

        return Rule::unique('attendances', 'lesson_id')
            ->where('student_id', $this->input('student_id'))
            ->where('date', $this->date('date')?->toDateString())
            ->ignore($this->route('attendance'));
    }
}
