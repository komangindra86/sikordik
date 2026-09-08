<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('education_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'education_level_id']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('clinical_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('participant_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('module', 60)->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('user_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 30);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('educators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('employee_number', 60)->nullable()->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('professional_title')->nullable();
            $table->boolean('can_mentor')->default(false);
            $table->boolean('can_examine')->default(false);
            $table->boolean('can_supervise')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['department_id', 'name']);
        });

        Schema::create('educator_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('educator_id')->constrained()->cascadeOnDelete();
            $table->string('license_type', 40);
            $table->string('license_number', 100);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['license_type', 'license_number']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80)->index();
            $table->string('auditable_type', 100)->nullable();
            $table->string('auditable_id', 100)->nullable();
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('educator_licenses');
        Schema::dropIfExists('educators');
        Schema::dropIfExists('user_scopes');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('participant_types');
        Schema::dropIfExists('clinical_locations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('study_programs');
        Schema::dropIfExists('education_levels');
        Schema::dropIfExists('institutions');
    }
};
