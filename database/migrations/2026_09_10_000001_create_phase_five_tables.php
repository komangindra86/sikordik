<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbooks', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('placement_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('type', 100);
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('author_assignment_id')->nullable()->constrained('educator_assignments')->restrictOnDelete();
            $t->foreignId('reviewer_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('current_version')->default(1);
            $t->timestamps();
            $t->index(['placement_id', 'kind', 'status']);
        });
        Schema::create('logbook_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('logbook_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('private_file_id')->nullable()->constrained('private_files')->restrictOnDelete();
            $t->foreignId('reviewer_assignment_id')->constrained('educator_assignments')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['logbook_id', 'version']);
        });
        Schema::create('logbook_reviews', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('logbook_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 20);
            $t->text('note')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->json('approval')->nullable();
            $t->timestamp('created_at');
            $t->index(['logbook_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_reviews');
        Schema::dropIfExists('logbook_versions');
        Schema::dropIfExists('logbooks');
    }
};
