<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->foreignId('clinical_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('mentor_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('original_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->string('attendance_status', 20);
            $t->text('activity');
            $t->text('notes')->nullable();
            $t->string('status', 30)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->boolean('admin_only')->default(false);
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['placement_id', 'date']);
        });
        Schema::create('attendance_summaries', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 20);
            $t->json('snapshot');
            $t->string('fingerprint', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->unique(['placement_id', 'version']);
        });
        Schema::create('attendance_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->unique(['attendance_id', 'user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reminders');
        Schema::dropIfExists('attendance_summaries');
        Schema::dropIfExists('attendances');
    }
};
