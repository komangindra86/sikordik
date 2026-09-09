<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_sequences', function (Blueprint $t) {
            $t->unsignedSmallInteger('year')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        Schema::create('participants', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number')->unique();
            $t->string('name');
            $t->string('normalized_name')->index();
            $t->date('birth_date')->nullable();
            $t->string('nik', 16)->nullable()->unique();
            $t->string('nim', 100)->nullable();
            $t->string('email')->nullable()->index();
            $t->foreignId('institution_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->index(['institution_id', 'nim']);
        });
        Schema::create('incoming_letters', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('institution_id')->constrained()->restrictOnDelete();
            $t->string('number');
            $t->string('normalized_number', 191);
            $t->date('letter_date');
            $t->unsignedSmallInteger('year');
            $t->string('subject');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['institution_id', 'year', 'normalized_number'], 'letters_sender_year_number_unique');
        });
        Schema::create('document_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('participant_type_id')->constrained();
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('study_program_id')->nullable()->constrained();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->string('code', 60);
            $t->string('label');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('placements', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('participant_id')->constrained()->restrictOnDelete();
            $t->foreignId('incoming_letter_id')->constrained()->restrictOnDelete();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('study_program_id')->constrained();
            $t->foreignId('participant_type_id')->constrained();
            $t->foreignId('department_id')->constrained();
            $t->json('snapshot');
            $t->date('start_date');
            $t->date('end_date');
            $t->date('actual_end_date')->nullable();
            $t->string('status', 60)->default('draft')->index();
            $t->unsignedInteger('revision')->default(1);
            $t->string('ksm_status')->default('pending');
            $t->string('kordik_status')->default('pending');
            $t->string('document_status')->default('pending');
            $t->string('activity_status')->default('not_started');
            $t->string('completion_status')->default('pending');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['participant_id', 'start_date', 'end_date']);
        });
        Schema::create('placement_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('placement_id')->constrained();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->string('action');
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
        Schema::create('private_files', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('resource_type', 30);
            $t->unsignedBigInteger('resource_id');
            $t->string('category', 60);
            $t->unsignedInteger('version');
            $t->string('path')->unique();
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->string('scan_status')->default('pending');
            $t->timestamp('scanned_at')->nullable();
            $t->boolean('participant_visible')->default(false);
            $t->boolean('deidentified')->default(false);
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
            $t->unique(['resource_type', 'resource_id', 'category', 'version'], 'file_resource_version_unique');
        });
        Schema::create('placement_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('placement_id')->constrained();
            $t->string('code', 60);
            $t->string('label');
            $t->foreignId('private_file_id')->nullable()->constrained();
            $t->string('status')->default('pending');
            $t->date('valid_until')->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['placement_id', 'code']);
        });
        Schema::create('overlap_exceptions', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained();
            $t->foreignId('conflicting_placement_id')->constrained('placements');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->foreignId('private_file_id')->constrained();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->string('status')->default('pending');
            $t->text('decision_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('participant_imports', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('institution_id')->constrained();
            $t->string('sha256', 64);
            $t->timestamps();
            $t->unique(['created_by', 'institution_id', 'sha256'], 'import_retry_unique');
        });
        Schema::create('participant_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('participant_import_id')->constrained();
            $t->unsignedInteger('row_number');
            $t->json('payload');
            $t->json('issues');
            $t->string('status')->default('pending');
            $t->foreignId('participant_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['participant_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        foreach (['participant_import_rows', 'participant_imports', 'overlap_exceptions', 'placement_documents', 'private_files', 'placement_histories', 'placements', 'document_templates', 'incoming_letters', 'participants', 'participant_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
