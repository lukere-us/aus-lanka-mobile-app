<?php

namespace Tests\Unit;

use App\Modules\Orders\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(OrderStatus::PendingPayment->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::Expired->canTransitionTo(OrderStatus::Paid), 'late verified payment is honoured');
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Refunded));
    }

    public function test_forbidden_transitions(): void
    {
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::PendingPayment));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Expired));
        $this->assertFalse(OrderStatus::Refunded->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::Expired->canTransitionTo(OrderStatus::PendingPayment));
    }
}
