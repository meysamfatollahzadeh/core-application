<?php

namespace App\Modules\Login\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePerformancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['theme' => ['required', 'string', Rule::in(['auto', 'light', 'dark'])], 'user_id' => ['prohibited'], 'performances' => ['prohibited']];
    }
}
