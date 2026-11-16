<?php

namespace Tests\Feature;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Venue;
use App\Modules\Identity\Models\Role;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\TestCase;

class OrganizerEventTest extends TestCase
{
    use BuildsEvents, RefreshDatabase;

    private function eventPayload(Venue $venue): array
    {
        return ['title' => 'Galle Jazz Night', 'category' => 'concert', 'venue_id' => $venue->id, 'starts_at' => now()->addDays(20)->toIso8601String()];
    }

    public function test_customer_applies_and_admin_approval_grants_organizer_role(): void
    {
        $applicant = $this->userWithRole(Role::CUSTOMER);
        $this->actingAs($applicant)->postJson('/api/v1/organizer/apply', ['name' => 'Galle Events', 'contact_email' => 'hi@galle.lk'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->actingAs($applicant)->postJson('/api/v1/organizer/apply', ['name' => 'Again', 'contact_email' => 'x@y.lk'])
            ->assertStatus(409);

        $organizer = Organizer::firstOrFail();
        $this->actingAs($this->userWithRole(Role::CUSTOMER))->patchJson("/api/v1/admin/organizers/{$organizer->id}/status", ['status' => 'approved'])
            ->assertForbidden();
        $this->actingAs($this->userWithRole(Role::EVENT_MANAGER))->patchJson("/api/v1/admin/organizers/{$organizer->id}/status", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertTrue($applicant->fresh()->hasRole(Role::ORGANIZER));
        $this->assertDatabaseHas('audit_logs', ['action' => 'organizer.status_changed', 'auditable_id' => $organizer->id]);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $applicant = $this->userWithRole(Role::CUSTOMER);
        $this->actingAs($applicant)->postJson('/api/v1/organizer/apply', ['name' => 'X Events', 'contact_email' => 'x@x.lk']);
        $id = Organizer::value('id');

        $this->actingAs($this->userWithRole(Role::ADMINISTRATOR))->patchJson("/api/v1/admin/organizers/{$id}/status", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');
    }

    public function test_organizer_builds_event_submits_and_admin_publishes_it(): void
    {
        $organizer = $this->approvedOrganizer();
        $owner = $organizer->owner;
        $venue = Venue::create(['name' => 'Galle Fort Hall', 'address' => 'Galle Fort', 'city' => 'Galle']);

        $eventId = $this->actingAs($owner)->postJson('/api/v1/organizer/events', $this->eventPayload($venue))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/organizer/events/{$eventId}/submit")
            ->assertUnprocessable()->assertJsonPath('error.code', 'no_ticket_types');

        $this->actingAs($owner)->postJson("/api/v1/organizer/events/{$eventId}/ticket-types", [
            'name' => 'VIP', 'kind' => 'vip', 'price' => '7500.00', 'quantity_total' => 50,
        ])->assertCreated()->assertJsonPath('data.price', ['amount' => '7500.00', 'currency' => 'LKR']);

        $this->actingAs($owner)->postJson("/api/v1/organizer/events/{$eventId}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->userWithRole(Role::EVENT_MANAGER))->postJson("/api/v1/admin/events/{$eventId}/review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $slug = Event::find($eventId)->slug;
        $this->getJson('/api/v1/events?city=Galle&category=concert')->assertOk()->assertJsonPath('data.0.slug', $slug)
            ->assertJsonPath('data.0.ticket_types.0.sale_state', 'on_sale')
            ->assertJsonMissingPath('data.0.ticket_types.0.quantity_sold');
        $this->getJson("/api/v1/events/{$slug}")->assertOk()->assertJsonPath('data.venue.city', 'Galle');
    }

    public function test_unapproved_organizer_cannot_submit_for_publication(): void
    {
        $organizer = $this->approvedOrganizer();
        $organizer->update(['status' => Organizer::PENDING]);
        $type = $this->publishedEvent(organizer: $organizer);
        $type->event->update(['status' => 'draft']);

        $this->actingAs($organizer->owner)->postJson("/api/v1/organizer/events/{$type->event_id}/submit")
            ->assertForbidden()->assertJsonPath('error.code', 'organizer_not_approved');
    }

    public function test_organizers_cannot_see_or_edit_each_others_events(): void
    {
        $theirs = $this->publishedEvent();
        $me = $this->approvedOrganizer($this->userWithRole(Role::ORGANIZER));

        $this->actingAs($me->owner)->getJson("/api/v1/organizer/events/{$theirs->event_id}")->assertNotFound();
        $this->actingAs($me->owner)->patchJson("/api/v1/organizer/events/{$theirs->event_id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->actingAs($me->owner)->getJson('/api/v1/organizer/events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ticket_total_cannot_drop_below_sold_and_held(): void
    {
        $type = $this->publishedEvent(stock: 10);
        $type->forceFill(['quantity_sold' => 6, 'quantity_reserved' => 2])->save();

        $this->actingAs($type->event->organizer->owner)
            ->patchJson("/api/v1/organizer/events/{$type->event_id}/ticket-types/{$type->id}", ['quantity_total' => 7])
            ->assertUnprocessable()->assertJsonPath('error.code', 'quantity_below_committed');
        $this->actingAs($type->event->organizer->owner)
            ->patchJson("/api/v1/organizer/events/{$type->event_id}/ticket-types/{$type->id}", ['quantity_total' => 8])
            ->assertOk();
    }

    public function test_owner_adds_gate_staff_who_get_scanner_access_only(): void
    {
        $organizer = $this->approvedOrganizer();
        $staff = $this->userWithRole(Role::CUSTOMER, ['email' => 'gate1@colombolive.lk']);

        $this->actingAs($organizer->owner)->postJson('/api/v1/organizer/staff', ['email' => 'gate1@colombolive.lk', 'role' => 'gate_staff'])
            ->assertCreated();

        $staff = $staff->fresh();
        $this->assertTrue($staff->can('scanner.checkin'));
        $this->assertFalse($staff->can('organizer.events.manage'));
        $this->actingAs($staff)->getJson('/api/v1/organizer/events')->assertForbidden();
    }
}
