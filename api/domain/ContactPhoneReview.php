<?php

/** Explicit, import-only South African mobile-number reconstruction. */
final class ContactPhoneReview
{
    public static function analyze(string $input): array
    {
        $raw = trim($input);
        $value = preg_replace('/[\s().-]/u', '', $raw);
        $result = static fn(?string $normalized, string $proposed, string $reason): array => compact('normalized', 'proposed', 'reason');
        if ($raw === '') return $result(null, '', 'The phone number is missing.');
        if (!preg_match('/^[+0-9\s().-]+$/uD', $raw)) return $result(null, '', 'Contains letters or unsupported characters. Check the original number.');
        if (preg_match('/^0[678][0-9]{8}$/D', $value)) return $result('+27' . substr($value, 1), '', '');
        if (preg_match('/^\+?27[678][0-9]{8}$/D', $value)) return $result('+' . ltrim($value, '+'), '', '');
        if (preg_match('/^[678][0-9]{8}$/D', $value)) return $result(null, '0' . $value, 'The leading zero appears to be missing.');
        if (preg_match('/^\+?270[678][0-9]{8}$/D', $value)) return $result(null, '0' . preg_replace('/^\+?270/', '', $value), 'The local zero was kept after country code 27.');
        if (preg_match('/^0027[678][0-9]{8}$/D', $value)) return $result(null, '0' . substr($value, 4), 'Replace the international dialling prefix 00 with the South African format.');
        return $result(null, '', 'Use a South African mobile number: 10 digits starting with 06, 07 or 08, or 27 followed by the 9-digit mobile number. We cannot safely guess missing digits.');
    }

    /** Validate every decision before the controller writes any contact. */
    public static function prepare(array $header, array $rows, string $phoneColumn, mixed $decisions): array
    {
        $column = array_search(strtolower(trim($phoneColumn)), $header, true);
        if ($column === false) throw new InvalidArgumentException('Choose a phone column before reviewing numbers.');
        if (!is_array($decisions) || !array_is_list($decisions) || count($decisions) > 10000) throw new InvalidArgumentException('Invalid number review decisions.');
        $byRow = [];
        foreach ($decisions as $decision) {
            if (!is_array($decision) || !is_int($decision['row'] ?? null) || $decision['row'] < 2
                || $decision['row'] > count($rows) + 1 || isset($byRow[$decision['row']])
                || !in_array($decision['action'] ?? null, ['accept', 'reject'], true)
                || !is_string($decision['original'] ?? null)) throw new InvalidArgumentException('Invalid or repeated number review decision.');
            $byRow[$decision['row']] = $decision;
        }
        $prepared = [];
        foreach ($rows as $index => $row) {
            $number = $index + 2;
            if (count($row) !== count($header)) throw new InvalidArgumentException("Row $number does not match the column headings.");
            $original = trim((string)$row[$column]);
            $analysis = self::analyze($original);
            $decision = $byRow[$number] ?? null;
            if ($analysis['normalized'] !== null) {
                if ($decision !== null) throw new InvalidArgumentException("Row $number changed since its review. Review it again.");
                $prepared[$number] = ['phone'=>$analysis['normalized'], 'original'=>$original, 'corrected'=>false];
                continue;
            }
            if ($decision === null || $decision['original'] !== $original) throw new InvalidArgumentException("Review the phone number on row $number before importing.");
            if ($decision['action'] === 'reject') { $prepared[$number] = null; continue; }
            if (!is_string($decision['value'] ?? null)) throw new InvalidArgumentException("Enter a corrected number on row $number.");
            $corrected = self::analyze($decision['value']);
            if ($corrected['normalized'] === null) throw new InvalidArgumentException("The correction on row $number is not a valid South African mobile format.");
            $prepared[$number] = ['phone'=>$corrected['normalized'], 'original'=>$original, 'corrected'=>true];
        }
        return $prepared;
    }
}
