<?php

namespace App\Modules\Login\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['browser_binding' => ['required', 'string', 'min:43', 'max:128']];
    }
}
