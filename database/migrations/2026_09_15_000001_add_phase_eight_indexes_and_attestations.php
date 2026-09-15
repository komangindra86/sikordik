<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attendances', 'attendance_summaries'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('approval')->nullable());
        }
        Schema::table('placements', fn (Blueprint $t) => $t->index(['department_id', 'status', 'start_date'], 'placements_dashboard_idx'));
        Schema::table('attendances', fn (Blueprint $t) => $t->index(['placement_id', 'status'], 'attendance_dashboard_idx'));
        Schema::table('schedules', fn (Blueprint $t) => $t->index(['placement_id', 'date', 'status'], 'schedule_dashboard_idx'));
        Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['created_at', 'id'], 'audit_report_idx'));
    }

    public function down(): void
    {
        foreach (['attendances', 'attendance_summaries'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('approval'));
        }
        foreach (['placements' => 'placements_dashboard_idx', 'attendances' => 'attendance_dashboard_idx', 'schedules' => 'schedule_dashboard_idx', 'audit_logs' => 'audit_report_idx'] as $table => $index) {
            // InnoDB can discard an implicit FK index once this composite index
            // covers it. Restore a supporting index before dropping the composite.
            if (in_array($table, ['placements', 'attendances', 'schedules'])) {
                $column = $table === 'placements' ? 'department_id' : 'placement_id';
                $covered = collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] !== $index && ($i['columns'][0] ?? null) === $column);
                if (! $covered) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($column, $table.'_'.$column.'_foreign'));
                }
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
        }
    }
};
