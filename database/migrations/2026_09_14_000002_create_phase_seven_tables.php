<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placements', function (Blueprint $t) {
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('archived_at')->nullable()->index();
        });
        Schema::create('survey_forms', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('name', 150);
            $t->string('url', 500);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('survey_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->foreignId('survey_form_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('token', 32)->unique();
            $t->string('status', 20)->default('issued');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['placement_id', 'kind']);
        });
        Schema::create('completion_requests', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->json('approval')->nullable();
            $t->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['placement_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completion_requests');
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_forms');
        Schema::table('placements', fn (Blueprint $t) => $t->dropColumn(['completed_at', 'archived_at']));
    }
};
