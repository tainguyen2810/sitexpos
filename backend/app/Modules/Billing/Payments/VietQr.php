<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

/**
 * VietQR (NAPAS 247, EMVCo Merchant-Presented QR) payload for a bank
 * transfer to an account. The QR only carries payment INSTRUCTIONS — it is
 * never treated as proof that money arrived (PAYMENT-RECONCILIATION.md).
 *
 * TLV fields: 00 format "01" · 01 "12" (dynamic, one amount) · 38 merchant
 * account [00 GUID A000000727 · 01 {00 bank BIN · 01 account} · 02 QRIBFTTA]
 * · 53 currency 704 (VND) · 54 amount · 58 VN · 62 {08 transfer memo}
 * · 63 CRC-16/CCITT-FALSE over everything up to and including "6304".
 */
final class VietQr
{
    public const NAPAS_GUID = 'A000000727';

    public static function payload(string $bankBin, string $accountNo, int $amountVnd, string $memo): string
    {
        $bankBin = preg_replace('/\D/', '', $bankBin) ?? '';
        $accountNo = preg_replace('/[^0-9A-Za-z]/', '', $accountNo) ?? '';
        // Memo: ASCII letters/digits/spaces only (bank apps strip the rest anyway).
        $memo = trim(mb_substr(preg_replace('/[^0-9A-Za-z ]/', '', $memo) ?? '', 0, 25));

        $beneficiary = self::tlv('00', $bankBin).self::tlv('01', $accountNo);
        $merchant = self::tlv('00', self::NAPAS_GUID).self::tlv('01', $beneficiary).self::tlv('02', 'QRIBFTTA');

        $data = self::tlv('00', '01')
            .self::tlv('01', '12')
            .self::tlv('38', $merchant)
            .self::tlv('53', '704')
            .self::tlv('54', (string) max(0, $amountVnd))
            .self::tlv('58', 'VN')
            .self::tlv('62', self::tlv('08', $memo))
            .'6304';

        return $data.self::crc16($data);
    }

    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Parse a payload back into TLV fields (used by tests / support).
     *
     * @return array<string, string>
     */
    public static function parse(string $payload): array
    {
        $out = [];
        $i = 0;
        while ($i + 4 <= strlen($payload)) {
            $id = substr($payload, $i, 2);
            $len = (int) substr($payload, $i + 2, 2);
            $out[$id] = substr($payload, $i + 4, $len);
            $i += 4 + $len;
        }

        return $out;
    }

    private static function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}
