<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.type' => ['required', 'string', 'max:50'],
            'items.*.id' => ['required'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9\-_]{8,64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Send an Idempotency-Key header (8-64 letters, digits, - or _), unique per checkout attempt.',
            'idempotency_key.regex' => 'The Idempotency-Key header must be 8-64 letters, digits, - or _.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
