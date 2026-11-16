<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 150);
            $table->text('synopsis')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->string('classification', 10)->nullable(); // e.g. G, PG, M, MA15+, R18+
            $table->json('genres')->nullable();
            $table->json('languages')->nullable();
            $table->string('poster_url', 500)->nullable();
            $table->string('backdrop_url', 500)->nullable();
            $table->string('trailer_url', 500)->nullable();
            $table->string('status', 20)->default('draft')->index(); // draft | published | unpublished
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('movie_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('feature'); // feature | trailer
            $table->string('provider', 30);
            $table->string('provider_asset_id', 500);       // provider's video id (sample: the stream URL)
            $table->string('status', 20)->default('ready');  // processing | ready | failed
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('subtitles')->nullable();           // [{lang, label, url}]
            $table->timestamps();
            $table->index(['movie_id', 'kind', 'status']);
        });

        Schema::create('rental_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('quality', 10)->default('HD'); // SD | HD | 4K
            $table->decimal('price', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('watch_hours');       // viewing window once started
            $table->unsignedSmallInteger('start_within_days'); // must start within this many days of purchase
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->foreignId('rental_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete(); // one rental per paid item
            $table->string('status', 20)->index(); // ready | active | expired | revoked
            $table->unsignedSmallInteger('watch_hours');
            $table->timestamp('activate_by');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'movie_id', 'status']);
        });

        Schema::create('playback_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'movie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_progress');
        Schema::dropIfExists('rentals');
        Schema::dropIfExists('rental_packages');
        Schema::dropIfExists('movie_assets');
        Schema::dropIfExists('movies');
    }
};
