<?php

namespace App\Modules\Login\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BackchannelLogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['logout_token' => ['required', 'string', 'max:16384']];
    }
}
