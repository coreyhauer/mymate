<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RF/link-health raw samples pulled from LibreNMS (see
 * scratchpad/link-health-design/backhaul-link-health-design.md §2d), ~5-minute cadence via
 * PullLibreNmsRfMetricsJob. RANGE-partitioned by day, same shape and retention machinery as
 * interface_samples/device_metric_samples (App\Actions\History\ManageHistoryPartitions -
 * rf_link_samples is added to its TABLES list, so it inherits the same operator-editable
 * `history.retention_days` window, 14 days by default).
 *
 * One row per (device_id, ts, sensor_index). sensor_index exists to disambiguate
 * multi-carrier radios (e.g. Aviat WTM 1+1 diversity, sensor_index 'Carrier1/1' vs
 * 'Carrier1/2') and is NOT NULL DEFAULT ''. In practice PullLibreNmsRfMetrics always writes
 * '' as of this migration - live LibreNMS data showed sensor_index doesn't line up across
 * sensor classes for standard airOS (rssi indexes by antenna chain, noise-floor by something
 * else entirely on the same device), so the ingestion stage resolves each metric class to one
 * value per device rather than trying to key on it (see that class's docblock for the full
 * story). The column stays in the schema for a future revision that wants true per-carrier
 * rows once a reliable cross-class carrier signal exists.
 *
 * Deliberate deviation from the design doc: it proposed `PRIMARY KEY (device_id, ts)`, but
 * the two existing samples tables this pattern is modelled on (interface_samples,
 * device_metric_samples) carry NO primary key at all - just a plain btree index - precisely
 * because a high-volume append-only table doesn't want unique-constraint overhead on bulk
 * inserts, and a strict PK on (device_id, ts) would also collide for any multi-carrier device
 * reporting >1 sensor_index in the same poll tick. This migration follows the established
 * local precedent (index, no PK) rather than the design doc's literal DDL.
 *
 * device_id is a My Mate device id (joined by mgmt_ip against LibreNMS's device - LibreNMS
 * stores the IP directly in its `hostname` column for this fleet, see
 * App\Services\Import\LibreNms\LibreNmsMysqlSource::wirelessSensors). No FK, same reasoning
 * as interface_samples: high-volume, append-only, drop-by-partition - an FK would slow bulk
 * inserts and complicate drops; orphans age out with retention.
 *
 * `capacity` and `ccq` are deliberately never pulled/stored: capacity is a dead airOS OID
 * (98% of readings are exactly 0) and ccq is an unreliable parsing artifact for airOS (43% of
 * readings are exactly 33) - see the design doc §1a. `snr_db` is native where LibreNMS has an
 * `snr` sensor, else derived as rssi_dbm - noise_floor_dbm at write time (`snr_source` records
 * which, 'native' or 'derived').
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE rf_link_samples (
                device_id bigint NOT NULL,
                ts timestamp(0) without time zone NOT NULL,
                sensor_index varchar(64) NOT NULL DEFAULT '',
                rssi_dbm double precision,
                noise_floor_dbm double precision,
                snr_db double precision,
                snr_source varchar(8),
                rate_mbps double precision,
                channel_util_pct double precision,
                tx_power_dbm double precision,
                distance_mi double precision,
                freq_mhz double precision,
                if_errors_in bigint,
                if_errors_out bigint,
                source_lastupdate timestamp(0) without time zone
            ) PARTITION BY RANGE (ts)
        SQL);

        // Partition pruning + this index serve "device X over [from,to]" queries, same as
        // interface_samples_iface_ts_idx.
        DB::statement('CREATE INDEX rf_link_samples_device_ts_idx ON rf_link_samples (device_id, ts)');

        // Seed daily partitions for [yesterday .. +3 days] so writes work immediately after
        // migrate; ManageHistoryPartitions keeps rolling them forward + drops old.
        $day = now()->startOfDay()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $date = $day->copy()->addDays($i);
            $name = 'rf_link_samples_'.$date->format('Ymd');
            $from = $date->format('Y-m-d 00:00:00');
            $to = $date->copy()->addDay()->format('Y-m-d 00:00:00');
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF rf_link_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }

    public function down(): void
    {
        // CASCADE drops every attached daily partition with the parent.
        DB::statement('DROP TABLE IF EXISTS rf_link_samples CASCADE');
    }
};
