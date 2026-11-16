<?php

namespace App\Modules\Admin\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
            'reason' => ['required_if:status,'.User::STATUS_SUSPENDED, 'nullable', 'string', 'max:500'],
        ];
    }
}
