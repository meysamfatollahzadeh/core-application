<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role_ids' => ['present', 'array', 'max:100'], 'role_ids.*' => ['required', 'integer', 'distinct', 'exists:roles,id']];
    }
}
