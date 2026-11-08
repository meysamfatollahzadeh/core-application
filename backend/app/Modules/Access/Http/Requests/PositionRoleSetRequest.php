<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PositionRoleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['grants' => ['present', 'array', 'max:100'], 'grants.*' => ['required', 'array:role_id,scope'], 'grants.*.role_id' => ['required', 'integer', 'exists:roles,id'], 'grants.*.scope' => ['required', 'in:company,global']];
    }
}
