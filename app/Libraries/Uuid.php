<?php

namespace App\Libraries;

/**
 * RFC 4122 version-4 UUIDs for sales and journal entries.
 * Devices generate their own when selling offline (Phase 2); the
 * server generates one when the client didn't send any.
 */
class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
