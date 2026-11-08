<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'], 'key' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[a-z][a-z0-9.-]*$/', 'unique:roles,key']];
    }
}
