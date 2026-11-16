<?php

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventRequest extends FormRequest
{
    public const CATEGORIES = ['concert', 'theatre', 'comedy', 'festival', 'sports', 'family', 'conference', 'other'];

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => [$required, 'in:'.implode(',', self::CATEGORIES)],
            'venue_id' => [$required, 'integer', 'exists:venues,id'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'starts_at' => [$required, 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'doors_open_at' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:21'],
        ];
    }
}
