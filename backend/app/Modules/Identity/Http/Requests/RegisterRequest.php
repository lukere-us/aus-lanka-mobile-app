<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            // Sri Lankan numbers in E.164, e.g. +94771234567
            'phone' => ['nullable', 'string', 'regex:/^\+94\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a Sri Lankan mobile number in the format +94XXXXXXXXX.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }
}
