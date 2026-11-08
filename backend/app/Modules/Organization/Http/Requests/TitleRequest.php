<?php

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'], 'parent_position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id']];
    }
}
