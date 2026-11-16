<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Australia-based, sold worldwide: prices are set in the base currency (AUD); customers may pay in any active
 * currency at an admin-set rate that is locked into the order. Venues carry their own country and timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            // Units of this currency per 1 unit of the base currency, e.g. USD 0.65000000 means 1 AUD = 0.65 USD.
            $table->decimal('rate_per_base', 18, 8)->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('rate_updated_at')->nullable();
            $table->foreignId('rate_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('AUD')->after('currency');
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('base_currency');
            $table->decimal('base_total', 12, 2)->default(0)->after('total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('base_unit_price', 12, 2)->nullable()->after('unit_price');
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->char('country', 2)->default('AU')->after('city');
            $table->string('timezone', 64)->default('Australia/Sydney')->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('venues', fn (Blueprint $t) => $t->dropColumn(['country', 'timezone']));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('base_unit_price'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['base_currency', 'exchange_rate', 'base_total']));
        Schema::dropIfExists('currencies');
    }
};
