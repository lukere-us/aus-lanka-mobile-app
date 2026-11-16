<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\TicketType;
use Illuminate\Foundation\Http\FormRequest;

class TicketTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'kind' => [$required, 'in:'.implode(',', TicketType::KINDS)],
            'description' => ['nullable', 'string', 'max:300'],
            'price' => [$required, 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'quantity_total' => [$required, 'integer', 'min:1', 'max:200000'],
            'max_per_order' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],
            'status' => ['sometimes', 'in:active,hidden'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
