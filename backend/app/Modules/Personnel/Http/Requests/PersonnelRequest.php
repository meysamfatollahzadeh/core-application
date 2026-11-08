<?php

namespace App\Modules\Personnel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'building_area_id' => ['sometimes', 'required', 'integer', 'exists:building_areas,id']];
    }
}
