<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('educator_licenses', function (Blueprint $t) {
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
        });
        Schema::create('clinical_groups', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });
        Schema::create('group_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinical_group_id')->constrained()->restrictOnDelete();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['placement_id', 'start_date', 'end_date']);
        });
        Schema::create('educator_assignments', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->foreignId('educator_id')->constrained()->restrictOnDelete();
            $t->foreignId('educator_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('clinical_group_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('role', 30);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 30)->default('pending');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('replaces_id')->nullable()->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->text('decision_reason')->nullable();
            $t->timestamps();
            $t->index(['placement_id', 'status']);
        });
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->foreignId('clinical_group_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('mentor_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('examiner_assignment_id')->nullable()->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('clinical_location_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->string('activity', 150);
            $t->text('notes')->nullable();
            $t->string('status', 30)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('replaces_id')->nullable()->constrained('schedules')->restrictOnDelete();
            $t->unsignedInteger('replaces_revision')->nullable();
            $t->string('change_kind', 20)->default('schedule');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->index(['placement_id', 'date', 'status']);
        });
        Schema::create('placement_extensions', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->date('old_end_date');
            $t->date('new_end_date');
            $t->foreignId('supporting_file_id')->nullable()->constrained('private_files')->restrictOnDelete();
            $t->json('conflict_snapshot');
            $t->unsignedInteger('placement_revision');
            $t->string('status', 30)->default('pending_ksm');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('ksm_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->text('decision_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('scheduling_histories', function (Blueprint $t) {
            $t->id();
            $t->string('resource_type', 40);
            $t->unsignedBigInteger('resource_id');
            $t->string('event', 60);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->json('before')->nullable();
            $t->json('after');
            $t->timestamp('created_at');
            $t->index(['resource_type', 'resource_id']);
        });
        Schema::create('scheduling_notifications', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('placement_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('message');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach (['scheduling_notifications', 'scheduling_histories', 'placement_extensions', 'schedules', 'educator_assignments', 'group_memberships', 'clinical_groups'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('educator_licenses', fn (Blueprint $t) => $t->dropColumn(['is_active', 'revision']));
    }
};
