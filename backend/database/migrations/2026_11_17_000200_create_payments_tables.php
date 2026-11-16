<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 20)->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->text('checkout_url')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 100);
            $table->string('type', 60);
            $table->string('provider_reference', 100)->nullable()->index();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};
