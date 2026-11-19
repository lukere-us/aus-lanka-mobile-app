<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Movies\Models\Movie;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Models\Order;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Services\RentalIssuer;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class MovieRentalTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private RentalPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->seed(DemoContentSeeder::class);
        $this->package = RentalPackage::firstOrFail(); // AUD 4.99, 48 h, start within 30 days
    }

    private function rent(?User $as = null): Rental
    {
        $as ??= $this->customer;
        $ref = $this->checkout([['type' => 'rental_package', 'id' => $this->package->id, 'quantity' => 1]], as: $as)
            ->assertCreated()->assertJsonPath('data.total.amount', '4.99')->json('data.reference');
        $this->providerSends(Order::where('reference', $ref)->with('latestPayment')->first(), 'succeeded')->assertOk();

        return Rental::where('user_id', $as->id)->latest('id')->firstOrFail();
    }

    private function play(Rental $rental, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->customer)->postJson("/api/v1/rentals/{$rental->id}/play");
    }

    public function test_catalogue_shows_published_movies_with_rental_options(): void
    {
        Movie::create(['slug' => 'draft-film', 'title' => 'Draft film', 'status' => Movie::DRAFT]);

        $this->getJson('/api/v1/movies')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Big Buck Bunny (demo)')
            ->assertJsonPath('data.0.rental_packages.0.price', ['amount' => '4.99', 'currency' => 'AUD'])
            ->assertJsonPath('data.0.rental_packages.0.watch_hours', 48)
            ->assertJsonMissingPath('data.0.assets');
        $this->getJson('/api/v1/movies?genre=Animation')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/movies/draft-film')->assertNotFound();
    }

    public function test_content_manager_builds_and_publishes_a_movie_but_customers_cannot(): void
    {
        $cm = $this->userWithRole(Role::CONTENT_MANAGER);
        $id = $this->actingAs($cm)->postJson('/api/v1/admin/movies', ['title' => 'Kandy Nights', 'genres' => ['Drama'], 'languages' => ['Sinhala']])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actingAs($cm)->patchJson("/api/v1/admin/movies/{$id}", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'no_video');
        $this->actingAs($cm)->postJson("/api/v1/admin/movies/{$id}/assets", ['kind' => 'feature', 'provider' => 'sample', 'provider_asset_id' => 'https://example.com/a.m3u8'])->assertOk();
        $this->actingAs($cm)->patchJson("/api/v1/admin/movies/{$id}", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'no_rental_package');
        $this->actingAs($cm)->postJson("/api/v1/admin/movies/{$id}/packages", ['name' => '72-hour rental', 'price' => '6.99', 'watch_hours' => 72, 'start_within_days' => 14])
            ->assertCreated()->assertJsonPath('data.price.currency', 'AUD');
        $this->actingAs($cm)->patchJson("/api/v1/admin/movies/{$id}", ['status' => 'published'])->assertOk();

        $this->getJson('/api/v1/movies?language=Sinhala')->assertJsonPath('data.0.title', 'Kandy Nights');
        $this->actingAs($cm)->getJson("/api/v1/admin/movies/{$id}")
            ->assertOk()->assertJsonPath('data.title', 'Kandy Nights')->assertJsonPath('data.rental_packages.0.name', '72-hour rental');
        $this->actingAs($this->customer)->getJson("/api/v1/admin/movies/{$id}")->assertForbidden();
        $this->actingAs($this->customer)->postJson('/api/v1/admin/movies', ['title' => 'Nope'])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'movie.status_changed']);
    }

    public function test_paying_creates_exactly_one_rental_waiting_to_start(): void
    {
        $rental = $this->rent();

        $this->assertSame(Rental::READY, $rental->status);
        $this->assertTrue($rental->activate_by->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        $this->assertSame(0, app(RentalIssuer::class)->issueForOrder($rental->order_id));
        $this->assertSame(1, Rental::count());

        $this->actingAs($this->customer)->getJson('/api/v1/rentals')->assertOk()
            ->assertJsonPath('data.0.status', 'ready')->assertJsonPath('data.0.movie.title', 'Big Buck Bunny (demo)');
    }

    public function test_cannot_rent_the_same_movie_twice_while_a_rental_is_live(): void
    {
        $this->rent();

        $this->checkout([['type' => 'rental_package', 'id' => $this->package->id, 'quantity' => 1]])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_rented');
        $this->checkout([['type' => 'rental_package', 'id' => $this->package->id, 'quantity' => 2]], as: $this->userWithRole(Role::CUSTOMER))
            ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_quantity');
    }

    public function test_first_play_starts_the_window_and_returns_a_short_lived_signed_link(): void
    {
        $rental = $this->rent();

        $response = $this->play($rental)->assertOk()->assertJsonPath('data.format', 'hls')->assertJsonPath('data.resume_position_seconds', 0);

        $rental->refresh();
        $this->assertSame(Rental::ACTIVE, $rental->status);
        $this->assertTrue($rental->expires_at->between(now()->addHours(48)->subMinute(), now()->addHours(48)->addMinute()));

        $url = $response->json('data.manifest_url');
        $this->assertStringContainsString('/api/v1/dev/stream/', $url);
        $this->assertStringNotContainsString('test-streams.mux.dev', $url); // the real file location is never handed out
        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(config('video.sample_stream_url'));

        $this->travel(config('video.link_ttl_minutes') + 1)->minutes();
        $this->get(parse_url($url, PHP_URL_PATH))->assertForbidden();
        $this->play($rental)->assertOk(); // the app simply asks for a fresh link
    }

    public function test_tampered_link_is_rejected(): void
    {
        $url = $this->play($this->rent())->json('data.manifest_url');

        $this->get(parse_url($url, PHP_URL_PATH).'x')->assertForbidden();
    }

    public function test_only_the_renter_can_play(): void
    {
        $rental = $this->rent();

        $this->play($rental, $this->userWithRole(Role::CUSTOMER))->assertNotFound();
    }

    public function test_playback_stops_when_the_window_ends_and_link_never_outlives_rental(): void
    {
        $rental = $this->rent();
        $this->play($rental)->assertOk();

        $this->travel(47 * 60 + 55)->minutes();
        $late = $this->play($rental)->assertOk();
        $this->assertSame($late->json('data.rental_expires_at'), $late->json('data.link_expires_at'));

        $this->travel(10)->minutes();
        $this->play($rental)->assertForbidden()->assertJsonPath('error.code', 'rental_expired');
        $this->assertSame(Rental::EXPIRED, $rental->fresh()->status);

        // Expired rentals can be rented again.
        $this->checkout([['type' => 'rental_package', 'id' => $this->package->id, 'quantity' => 1]])->assertCreated();
    }

    public function test_unstarted_rental_expires_after_the_start_window(): void
    {
        $rental = $this->rent();

        $this->travel(31)->days();
        $this->artisan('rentals:expire')->assertSuccessful();

        $this->assertSame(Rental::EXPIRED, $rental->fresh()->status);
        $this->play($rental)->assertForbidden();
    }

    public function test_progress_is_saved_and_returned_for_resume(): void
    {
        $rental = $this->rent();
        $this->play($rental);

        $this->actingAs($this->customer)->putJson("/api/v1/rentals/{$rental->id}/progress", ['position_seconds' => 312])->assertOk();

        $this->play($rental)->assertJsonPath('data.resume_position_seconds', 312);
        $this->actingAs($this->customer)->getJson("/api/v1/rentals/{$rental->id}")->assertJsonPath('data.resume_position_seconds', 312);
    }

    public function test_dev_player_page_and_demo_command_work(): void
    {
        $url = $this->play($this->rent())->assertOk()->json('data.test_player_url');
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk()->assertSee('hls.min.js', false);
        $this->get('/api/v1/dev/player?src='.urlencode('https://evil.example/x.m3u8'))->assertNotFound();

        $this->artisan('demo:rental-link', ['--email' => 'demo2@example.com'])
            ->expectsOutputToContain('/api/v1/dev/player?src=')->assertSuccessful();
    }
}
