<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Optional: left blank, the controller numbers the pupil from
            // their row id once it exists.
            'matricule' => ['nullable', 'string', 'max:255', Rule::unique('students', 'matricule')],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in([Student::MALE, Student::FEMALE])],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ];
    }
}
