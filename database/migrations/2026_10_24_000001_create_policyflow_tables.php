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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 50);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 30); // DOMESTIC, INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->unsignedInteger('travel_days');
            $table->unsignedInteger('customer_age');
            $table->string('plan_code', 30); // BASIC, PLUS, PREMIUM
            $table->decimal('sum_insured', 12, 2);
            $table->decimal('base_daily_rate', 8, 2);
            $table->decimal('age_multiplier', 4, 2);
            $table->decimal('destination_multiplier', 4, 2);
            $table->decimal('coverage_multiplier', 4, 2);
            $table->decimal('premium_amount', 10, 2);
            $table->string('status', 30)->default('RECEIVED');
            $table->string('provider_reference', 100)->nullable();
            $table->string('policy_number', 100)->nullable()->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Uniqueness enforced at DB level for intake idempotency
            $table->unique(['agent_id', 'client_request_id'], 'unique_agent_client_req');
            $table->index(['status', 'created_at']);
            $table->index('agent_id');
        });

        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('scenario', 50)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('status', 50); // SUCCESS, TEMPORARY_FAILURE, TIMEOUT, etc.
            $table->text('error_message')->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['policy_request_id', 'attempt_number']);
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique(); // DB constraint guarantees idempotency of callbacks
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->string('status', 30)->default('PROCESSED');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });

        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['policy_request_id', 'created_at']);
        });

        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 50);
            $table->string('policy_number', 100)->unique();
            $table->string('provider_reference', 100)->unique();
            $table->string('plan_code', 30);
            $table->decimal('sum_insured', 12, 2);
            $table->decimal('premium_amount', 10, 2);
            $table->string('status', 30)->default('ISSUED');
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id'], 'unique_mock_agent_client_req');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
        Schema::dropIfExists('policy_state_history');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('issuance_attempts');
        Schema::dropIfExists('policy_requests');
    }
};
