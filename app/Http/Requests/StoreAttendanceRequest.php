<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
     * The database refuses a second mark for the same pupil, lesson and day.
     * Checking it here turns that into a 422 the form can show instead of the
     * integrity error the insert would otherwise raise.
     *
     * Full-day marks (no lesson) are exempt on purpose: they are allowed to
     * repeat within a day, which is why the column is nullable in the first
     * place.
     */
    protected function uniqueMark(): mixed
    {
        if ($this->input('lesson_id') === null) {
            return 'nullable';
        }

        $date = $this->date('date')?->toDateString();

        return Rule::unique('attendances', 'lesson_id')
            ->where('student_id', $this->input('student_id'))
            // whereDate, not a plain compare: the date cast writes a full
            // 'Y-m-d 00:00:00' timestamp, which an equality test on 'Y-m-d'
            // would never match on a driver that stores it verbatim.
            ->where(fn ($query) => $query->whereDate('date', $date))
            ->ignore($this->route('attendance'));
    }
}
