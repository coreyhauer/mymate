<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-AP Sonar customer counts, synced daily from the UISP wireless-reachability poller
 * (outage.wireless_reachability, keyed per customer by ap_ip). Keyed by ap_ip so it joins
 * to devices.mgmt_ip 1:1. A row means "this AP was seen by the poll" - cust_count may be 0
 * (known AP, no billed customers). No row at all means "never polled" - the geo feed shows
 * that as unknown rather than zero, so a down AP with no data doesn't read as non-urgent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_customer_counts', function (Blueprint $table) {
            $table->string('ap_ip', 45)->primary();
            $table->unsignedInteger('cust_count')->default(0);
            $table->unsignedInteger('up_count')->default(0);
            $table->unsignedInteger('down_count')->default(0);
            $table->timestampTz('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_customer_counts');
    }
};
