<?php

final class SmsSegmentCalculator
{
    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const EXTENSION = "^{}\\[~]|€";

    public static function analyze(string $message): array
    {
        $characters = self::characters($message);
        $septets = 0;
        $gsm = true;
        foreach ($characters as $character) {
            if (str_contains(self::BASIC, $character)) {
                $septets++;
            } elseif (str_contains(self::EXTENSION, $character)) {
                $septets += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        $characterCount = count($characters);
        if ($gsm) {
            $segments = $septets === 0 ? 0 : ($septets <= 160 ? 1 : (int) ceil($septets / 153));
            return ['encoding' => 'gsm7', 'character_count' => $characterCount, 'unit_count' => $septets, 'segment_count' => $segments];
        }

        $units = self::utf16Units($message);
        $segments = $units === 0 ? 0 : ($units <= 70 ? 1 : (int) ceil($units / 67));
        return ['encoding' => 'ucs2', 'character_count' => $characterCount, 'unit_count' => $units, 'segment_count' => $segments];
    }

    private static function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function utf16Units(string $value): int
    {
        if (function_exists('mb_convert_encoding')) {
            return intdiv(strlen(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')), 2);
        }
        $units = 0;
        foreach (self::characters($value) as $char) {
            $code = self::codePoint($char);
            $units += $code > 0xFFFF ? 2 : 1;
        }
        return $units;
    }

    private static function codePoint(string $char): int
    {
        $bytes = array_values(unpack('C*', $char));
        $first = $bytes[0];
        if ($first < 0x80) return $first;
        if (($first & 0xE0) === 0xC0) return (($first & 0x1F) << 6) | ($bytes[1] & 0x3F);
        if (($first & 0xF0) === 0xE0) return (($first & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
        return (($first & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
    }
}
