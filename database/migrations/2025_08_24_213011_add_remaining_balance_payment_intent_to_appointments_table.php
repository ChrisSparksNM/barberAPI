<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('remaining_balance_payment_intent_id')->nullable()->after('payment_intent_id');
            $table->index('remaining_balance_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['remaining_balance_payment_intent_id']);
            $table->dropColumn('remaining_balance_payment_intent_id');
        });
    }
};
