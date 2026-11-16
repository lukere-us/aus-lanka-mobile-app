<?php

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isEditableByOrganizer(): bool
    {
        return in_array($this, [self::Draft, self::Published], true);
    }
}
