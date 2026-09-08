<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
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
            // 'sometimes' so a PATCH that touches only, say, the class does
            // not have to resend the number; present, it must still be a real
            // one and stay unique.
            'matricule' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('students', 'matricule')->ignore($this->route('student'))],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in([Student::MALE, Student::FEMALE])],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ];
    }
}
