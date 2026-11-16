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
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('internal_reference');
            $table->string('external_load_reference')->nullable();
            $table->string('broker_or_customer_name');
            $table->foreignUuid('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('driver_contact')->nullable();
            $table->string('pickup_location')->nullable();
            $table->string('delivery_location')->nullable();
            $table->dateTime('pickup_appointment')->nullable();
            $table->dateTime('delivery_appointment')->nullable();
            $table->dateTime('actual_arrival_at')->nullable();
            $table->dateTime('actual_departure_at')->nullable();
            $table->decimal('base_rate', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'internal_reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'pickup_appointment']);
            $table->index(['organization_id', 'carrier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
