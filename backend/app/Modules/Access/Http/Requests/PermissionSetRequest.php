<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PermissionSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['permission_ids' => ['present', 'array', 'max:100'], 'permission_ids.*' => ['required', 'integer', 'distinct', 'exists:permissions,id']];
    }
}
