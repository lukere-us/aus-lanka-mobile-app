<?php

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Identity\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'slug')],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            // Only a Super Admin may grant or remove the Super Admin role.
            $touchesSuperAdmin = in_array(Role::SUPER_ADMIN, $this->input('roles', []), true)
                || $this->route('user')->isSuperAdmin();
            if ($touchesSuperAdmin && ! $this->user()->isSuperAdmin()) {
                $validator->errors()->add('roles', 'Only a Super Admin can change Super Admin access.');
            }
        }];
    }
}
