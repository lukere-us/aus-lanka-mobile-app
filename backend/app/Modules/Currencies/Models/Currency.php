<?php

namespace App\Modules\Currencies\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'rate_per_base', 'is_base', 'is_active', 'rate_updated_at', 'rate_updated_by'];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'rate_per_base' => 'decimal:8',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'rate_updated_at' => 'datetime',
        ];
    }

    public function toApi(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'rate_per_base' => $this->rate_per_base,
            'is_base' => $this->is_base,
            'is_active' => $this->is_active,
            'rate_updated_at' => $this->rate_updated_at?->toIso8601ZuluString(),
        ];
    }
}
