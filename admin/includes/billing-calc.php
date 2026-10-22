<?php
/**
 * admin/includes/billing-calc.php
 * -----------------------------------------------------------------------
 * Single source of truth (server side) for the Payments & Billing maths.
 * Used by admin/api/payments.php. The browser-side twin is
 * admin/assets/js/billing-calc.js — both use the SAME integer-centavo
 * algorithm, so they always agree to the centavo.
 *
 * Rules
 *   per-person share   = accommodation fee ÷ total guests
 *   discount per guest = per-person share × discount % ÷ 100
 *   total discount     = discount per guest × guests with discount
 *                        (never more than the accommodation fee)
 *   final amount due   = accommodation fee + extra guest fees
 *                        + other charges − total discount
 *
 * Only the accommodation fee is discounted. Extra guest fees and other
 * charges are always charged in full.
 *
 * Rounding: every step is rounded half-up to whole centavos (2 decimals)
 * and later steps use the rounded value, so the breakdown shown to the
 * admin can be re-checked on a calculator and always adds up.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

/** Highest discount percentage the system accepts. Lower it (e.g. 20.0) to restrict. */
const BIENMARKO_MAX_DISCOUNT_PERCENT = 100.0;

/** Largest single peso amount accepted (fits DECIMAL(10,2) with headroom for sums). */
const BIENMARKO_MAX_AMOUNT = 9999999.99;

/**
 * Round half-up to a whole number. The inner round() to 4 decimals strips
 * float noise first (so 1.005 × 100 = 100.49999999999999 still becomes 101),
 * exactly like roundHalfUp() in billing-calc.js.
 */
function bienmarko_billing_half_up(float $x): int
{
    return (int) round(round($x, 4), 0, PHP_ROUND_HALF_UP);
}

/**
 * Validate a monetary input. Blank/null counts as 0.
 * Returns [centavos|null, error|null].
 */
function bienmarko_billing_money($value, string $label): array
{
    if ($value === null || $value === '') {
        return [0, null];
    }
    if (is_bool($value) || !is_numeric($value)) {
        return [null, "{$label} must be a valid amount."];
    }
    $f = (float) $value;
    if (!is_finite($f)) {
        return [null, "{$label} must be a valid amount."];
    }
    if ($f < 0) {
        return [null, "{$label} cannot be negative."];
    }
    if ($f > BIENMARKO_MAX_AMOUNT) {
        return [null, "{$label} is too large."];
    }
    return [bienmarko_billing_half_up($f * 100), null];
}

/** Half-up integer division for non-negative integers. */
function bienmarko_billing_div_round(int $num, int $den): int
{
    return intdiv(2 * $num + $den, 2 * $den);
}

/** Centavos → pesos, rounded to 2 decimals. */
function bienmarko_billing_pesos(int $cents): float
{
    return round($cents / 100, 2);
}

/**
 * Calculate a full bill.
 *
 * @param array{
 *   accommodation_fee?: mixed, extra_guest_fee?: mixed, other_charges?: mixed,
 *   total_guests?: mixed, discount_percent?: mixed, discount_guests?: mixed,
 *   amount_paid?: mixed
 * } $in
 *
 * @return array{ok: bool, errors: string[], ...}  When ok, also contains:
 *   accommodation_fee, extra_guest_fee, other_charges, total_guests,
 *   per_person_share, discount_percent, discount_guests,
 *   discount_per_guest, total_discount, final_amount,
 *   amount_paid, balance, payment_status ('unpaid'|'partial'|'paid')
 */
function bienmarko_billing_calculate(array $in): array
{
    $errors = [];

    [$feeC,   $e] = bienmarko_billing_money($in['accommodation_fee'] ?? 0, 'Accommodation fee');     if ($e) $errors[] = $e;
    [$extraC, $e] = bienmarko_billing_money($in['extra_guest_fee']   ?? 0, 'Extra guest fee');         if ($e) $errors[] = $e;
    [$otherC, $e] = bienmarko_billing_money($in['other_charges']     ?? 0, 'Other charges');           if ($e) $errors[] = $e;
    [$paidC,  $e] = bienmarko_billing_money($in['amount_paid']       ?? 0, 'Amount paid');             if ($e) $errors[] = $e;

    /* Total guests: whole number, at least 1 */
    $guestsRaw = $in['total_guests'] ?? 0;
    $guests    = 0;
    if (!is_numeric($guestsRaw) || (float) $guestsRaw != floor((float) $guestsRaw)) {
        $errors[] = 'Total guests must be a whole number.';
    } else {
        $guests = (int) $guestsRaw;
        if ($guests < 1) {
            $errors[] = 'Total guests must be greater than zero.';
        }
    }

    /* Discount percentage: 0 … max */
    $pctRaw = $in['discount_percent'] ?? 0;
    if ($pctRaw === null || $pctRaw === '') {
        $pctRaw = 0;
    }
    $pctH = 0; // percentage in hundredths of a percent (20.00% → 2000)
    if (is_bool($pctRaw) || !is_numeric($pctRaw) || !is_finite((float) $pctRaw)) {
        $errors[] = 'Discount percentage must be a valid number.';
    } else {
        $pct = (float) $pctRaw;
        if ($pct < 0) {
            $errors[] = 'Discount percentage cannot be negative.';
        } elseif ($pct > BIENMARKO_MAX_DISCOUNT_PERCENT) {
            $errors[] = 'Discount percentage cannot exceed ' . rtrim(rtrim(number_format(BIENMARKO_MAX_DISCOUNT_PERCENT, 2, '.', ''), '0'), '.') . '%.';
        } else {
            $pctH = bienmarko_billing_half_up($pct * 100);
        }
    }

    /* Guests with discount: whole number, 0 … total guests */
    $dgRaw = $in['discount_guests'] ?? 0;
    if ($dgRaw === null || $dgRaw === '') {
        $dgRaw = 0;
    }
    $dg = 0;
    if (is_bool($dgRaw) || !is_numeric($dgRaw) || (float) $dgRaw != floor((float) $dgRaw)) {
        $errors[] = 'Guests with discount must be a whole number.';
    } else {
        $dg = (int) $dgRaw;
        if ($dg < 0) {
            $errors[] = 'Guests with discount cannot be negative.';
        } elseif ($guests >= 1 && $dg > $guests) {
            $errors[] = "Guests with discount ({$dg}) cannot exceed the total number of guests ({$guests}).";
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    /* ---- the maths, all in integer centavos ---- */
    $shareC       = bienmarko_billing_div_round($feeC, $guests);                 // fee ÷ guests
    $discPerC     = bienmarko_billing_div_round($shareC * $pctH, 10000);          // share × pct ÷ 100
    $totalDiscC   = min($discPerC * $dg, $feeC);                                 // can't exceed the accommodation fee
    $finalC       = $feeC + $extraC + $otherC - $totalDiscC;
    $balanceC     = max(0, $finalC - $paidC);

    if ($paidC <= 0)          { $status = 'unpaid'; }
    elseif ($paidC >= $finalC) { $status = 'paid'; }
    else                       { $status = 'partial'; }

    return [
        'ok'                 => true,
        'errors'             => [],
        'accommodation_fee'  => bienmarko_billing_pesos($feeC),
        'extra_guest_fee'    => bienmarko_billing_pesos($extraC),
        'other_charges'      => bienmarko_billing_pesos($otherC),
        'total_guests'       => $guests,
        'per_person_share'   => bienmarko_billing_pesos($shareC),
        'discount_percent'   => round($pctH / 100, 2),
        'discount_guests'    => $dg,
        'discount_per_guest' => bienmarko_billing_pesos($discPerC),
        'total_discount'     => bienmarko_billing_pesos($totalDiscC),
        'final_amount'       => bienmarko_billing_pesos($finalC),
        'amount_paid'        => bienmarko_billing_pesos($paidC),
        'balance'            => bienmarko_billing_pesos($balanceC),
        'payment_status'     => $status,
    ];
}