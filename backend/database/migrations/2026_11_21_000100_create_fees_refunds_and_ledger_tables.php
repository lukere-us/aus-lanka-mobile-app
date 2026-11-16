<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('organizers', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_bps')->nullable()->after('status_reason'); // override of the platform rate
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_payment_id', 100)->nullable()->after('provider_reference'); // e.g. Stripe payment_intent, needed to refund
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('base_fee_total', 12, 2)->default(0)->after('base_total');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->index(); // requested | rejected | processing | succeeded | failed
            $table->decimal('amount', 12, 2);      // in the order's currency
            $table->char('currency', 3);
            $table->decimal('base_amount', 12, 2); // AUD
            $table->boolean('includes_fees')->default(false);
            $table->string('reason', 500);
            $table->string('review_note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('provider_refund_id', 100)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('base_amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('reference', 120); // bank transfer reference
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Append-only money ledger per organizer, in the base currency. Balance = SUM(amount).
        Schema::create('organizer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30); // sale | commission | refund | commission_reversal | payout | adjustment
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_key', 80)->unique(); // makes posting idempotent, e.g. "sale:item:12"
            $table->string('description', 255);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organizer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_ledger_entries');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('base_fee_total'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('provider_payment_id'));
        Schema::table('organizers', fn (Blueprint $t) => $t->dropColumn('commission_bps'));
        Schema::dropIfExists('platform_settings');
    }
};
