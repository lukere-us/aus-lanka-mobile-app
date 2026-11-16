<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->nullable()->constrained()->nullOnDelete(); // null = platform venue
            $table->string('name', 150);
            $table->string('address', 255);
            $table->string('city', 80)->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('category', 40)->index();
            $table->string('image_url', 500)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('doors_open_at')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('review_note', 500)->nullable();
            $table->text('terms')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 20); // general | early_bird | vip | vvip | custom
            $table->string('description', 300)->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3);
            // Inventory counters (F04): available = total - sold - reserved, never negative. Changed only under row lock.
            $table->unsignedInteger('quantity_total');
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedInteger('quantity_reserved')->default(0);
            $table->unsignedSmallInteger('max_per_order')->default(10);
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('status', 20)->default('active'); // active | hidden
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ticket_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 20)->index(); // held | converted | released
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reservations');
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('events');
        Schema::dropIfExists('venues');
    }
};
