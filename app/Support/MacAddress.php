<?php

namespace App\Support;

/**
 * Normalise a hardware address to one canonical form: lowercase colon-separated hex
 * (`aa:bb:cc:dd:ee:ff`). Callers hand it whatever their source returns verbatim -
 * RouterOS `/interface/print`'s `mac-address` ("AA:BB:CC:DD:EE:FF"), or ext-snmp's
 * `ifPhysAddress` (a PhysAddress OCTET STRING, which different net-snmp builds render as
 * "48:8f:5a:12:34:56", "48 8F 5A 12 34 56", or "0x488f5a123456") - stripping every
 * non-hex character first makes the parse build-independent instead of chasing formats.
 *
 * Blank on anything that isn't exactly 6 octets of hex, and on the all-zero address
 * (RouterOS reports "00:00:00:00:00:00" for interfaces that don't have one, e.g. some
 * bridges/tunnels) - a placeholder MAC is not an identity and must never collide with a
 * real one in the distinct/sorted list `DeviceResource` builds.
 */
class MacAddress
{
    public static function normalize(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $raw = preg_replace('/^0x/i', '', trim($raw)) ?? $raw;
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $raw) ?? '');

        if (strlen($hex) !== 12 || $hex === str_repeat('0', 12)) {
            return '';
        }

        return implode(':', str_split($hex, 2));
    }
}
