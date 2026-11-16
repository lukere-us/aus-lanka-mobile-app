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
            // International E.164, e.g. +61412345678 (AU), +94771234567 (LK)
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter the mobile number in international format, e.g. +61412345678.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }
}
