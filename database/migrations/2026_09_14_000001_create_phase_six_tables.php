<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('exam_type', 100);
            foreach (['institution', 'study_program', 'participant_type', 'department'] as $key) {
                $t->foreignId($key.'_id')->nullable()->constrained()->restrictOnDelete();
            }
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('calculation', 20)->default('none');
            $t->decimal('pass_mark', 10, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('assessment_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_template_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->string('input_type', 20);
            $t->decimal('minimum', 10, 2)->nullable();
            $t->decimal('maximum', 10, 2)->nullable();
            $t->decimal('weight', 10, 2)->nullable();
            $t->decimal('pass_mark', 10, 2)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('position');
            $t->boolean('required');
            $t->unique(['assessment_template_id', 'position']);
        });
        Schema::create('assessments', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->foreignId('assessment_template_id')->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->date('date');
            $t->string('mode', 20);
            $t->foreignId('author_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('mentor_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('current_version')->default(1);
            $t->unsignedInteger('published_version')->nullable();
            $t->timestamps();
            $t->unique(['placement_id', 'assessment_template_id', 'title', 'date'], 'assessment_identity_unique');
        });
        Schema::create('assessment_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('private_file_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['assessment_id', 'version']);
        });
        Schema::create('assessment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 30);
            $t->text('note')->nullable();
            $t->json('approval')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['assessment_id', 'version', 'action']);
        });
        Schema::create('grade_appeals', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->foreignId('private_file_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('file_hash', 64)->nullable();
            $t->string('status', 20)->default('submitted');
            $t->text('response')->nullable();
            $t->unsignedInteger('corrected_version')->nullable();
            $t->timestamps();
            $t->unique(['assessment_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach (['grade_appeals', 'assessment_events', 'assessment_versions', 'assessments', 'assessment_components', 'assessment_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
