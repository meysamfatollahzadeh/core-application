<?php

namespace App\Modules\Personnel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['user_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:users,id'], 'position_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:positions,id'], 'report_position_ids' => ['present', 'array', 'max:100'], 'report_position_ids.*' => ['required', 'integer', 'distinct', 'exists:positions,id']];
    }
}
