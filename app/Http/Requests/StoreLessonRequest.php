<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
        ];
    }
}
