<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMINISTRATOR = 'administrator';

    public const FINANCE_MANAGER = 'finance_manager';

    public const CONTENT_MANAGER = 'content_manager';

    public const EVENT_MANAGER = 'event_manager';

    public const ORGANIZER = 'organizer';

    public const GATE_STAFF = 'gate_staff';

    public const CUSTOMER = 'customer';

    protected $fillable = ['slug', 'name', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
