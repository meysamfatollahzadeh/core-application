<?php

namespace App\Modules\Personnel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Modules\Access\Services\EffectivePermissions::class)->allows($this->user()->id, 'personnel.create');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'building_area_id' => ['required', 'integer', 'exists:building_areas,id']];
    }
}
