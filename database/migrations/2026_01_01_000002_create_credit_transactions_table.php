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
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index(); // topup, generation_hold, generation_settle, generation_refund
            $table->decimal('amount', 16, 8); // positive = credit added/refunded, negative = deducted
            $table->decimal('balance_after', 16, 8);
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable()->index();
            $table->string('description');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
