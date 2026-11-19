<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Finance\Models\Payout;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Tickets\Models\Ticket;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The local sample data goes through the real services, so it must keep working as they change. */
class SampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_data_builds_a_consistent_history_once(): void
    {
        $admin = $this->userWithRole(Role::SUPER_ADMIN);

        $this->seed(SampleDataSeeder::class);

        $this->assertGreaterThanOrEqual(45, Order::whereNotNull('paid_at')->count());
        $this->assertSame(0, Order::where('created_at', '>', now())->count(), 'nothing dated in the future');
        $this->assertSame(['AUD', 'LKR', 'USD'], Order::distinct()->orderBy('currency')->pluck('currency')->all());
        $this->assertEqualsCanonicalizing(
            ['cancelled', 'completed', 'draft', 'pending_review', 'published'],
            Event::distinct()->pluck('status')->map(fn ($s) => $s instanceof EventStatus ? $s->value : $s)->all(),
        );
        $this->assertGreaterThan(20, Ticket::where('status', Ticket::USED)->count());
        $this->assertSame(0, Ticket::where('status', Ticket::VALID)->whereHas('event', fn ($q) => $q->where('status', EventStatus::Cancelled))->count(),
            'cancelled event fully refunded');
        $this->assertSame(2, Refund::where('status', Refund::REQUESTED)->count());
        $this->assertSame(1, Refund::where('status', Refund::REJECTED)->count());
        $this->assertGreaterThan(0, Rental::where('status', Rental::EXPIRED)->count());
        $this->assertSame(1, Payout::count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // The admin's dashboard and organizer balance agree with what was sold.
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.needs_attention.refund_requests', 2)
            ->assertJsonPath('data.needs_attention.organizers_pending', 1)
            ->assertJsonPath('data.needs_attention.events_pending_review', 1);

        // Running it again changes nothing.
        $users = User::count();
        $this->seed(SampleDataSeeder::class);
        $this->assertSame($users, User::count());
    }
}
