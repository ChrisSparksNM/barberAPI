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
            $table->decimal('tip_amount', 8, 2)->default(0)->after('remaining_amount');
            $table->string('tip_payment_intent_id')->nullable()->after('tip_amount');
            $table->timestamp('tip_paid_at')->nullable()->after('tip_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['tip_amount', 'tip_payment_intent_id', 'tip_paid_at']);
        });
    }
};