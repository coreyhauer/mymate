<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suggested device->site corrections awaiting a human decision.
 *
 * Nothing in here moves a device. A proposal is a claim plus its evidence; approving
 * one calls DeviceSiteAssigner, which is still the only writer of `devices.site_id`.
 * That split is the point - detection can be automated and wrong, application cannot.
 *
 * `signals` records WHY, per signal, so a reviewer can judge the claim instead of
 * trusting a score - and so per-signal precision can be measured from real approve/
 * reject decisions later. Nothing gets promoted to automatic application on the
 * strength of looking sound; the Aug 2026 sweep had four separate false-positive
 * classes (link subnets spanning two sites, hard-coded /29 offsets, similar surnames
 * that were different people, public IPs) that all looked convincing first.
 *
 * One open proposal per device, enforced by a partial unique index: a regenerated run
 * supersedes the previous suggestion rather than stacking duplicates in the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_site_proposals', function (Blueprint $table) {
            $table->id();
            $table->integer('device_id');
            $table->integer('current_site_id')->nullable();  // snapshot when proposed
            $table->integer('suggested_site_id')->nullable();
            $table->string('confidence', 12);                // 'high' | 'medium'
            $table->json('signals');                         // per-signal evidence
            $table->text('rationale');
            $table->string('status', 12)->default('open');   // open|approved|rejected|superseded
            $table->uuid('run_id');                          // generator run

            $table->integer('decided_by')->nullable();
            $table->string('decided_by_name')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // The move that an approval produced, so queue -> audit log is traceable.
            $table->unsignedBigInteger('applied_change_id')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'confidence'], 'dsp_status_conf_idx');
            $table->index('device_id', 'dsp_device_idx');
            $table->index('run_id', 'dsp_run_idx');
        });

        // Only one OPEN proposal per device; decided rows are unconstrained history.
        DB::statement("CREATE UNIQUE INDEX dsp_one_open_per_device ON device_site_proposals (device_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('device_site_proposals');
    }
};
