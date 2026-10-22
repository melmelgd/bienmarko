/**
 * admin/assets/js/billing-calc.js
 * -----------------------------------------------------------------------
 * Browser-side twin of admin/includes/billing-calc.php. Both use the same
 * integer-centavo algorithm so the live preview in the billing modal and
 * the amount saved by the server always agree to the centavo.
 *
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
 * Works in the browser (window.BillingCalc) and in Node (module.exports),
 * so the same file can be unit-tested.
 * -----------------------------------------------------------------------
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BillingCalc = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX_PERCENT = 100;       // keep in sync with BIENMARKO_MAX_DISCOUNT_PERCENT (PHP)
    var MAX_AMOUNT  = 9999999.99; // keep in sync with BIENMARKO_MAX_AMOUNT (PHP)

    /* Float-noise guard, then half-up to a whole number (matches PHP). */
    function roundHalfUp(x) {
        return Math.round(parseFloat(x.toFixed(4)));
    }

    function isBlank(v) {
        return v === null || v === undefined || (typeof v === 'string' && v.trim() === '');
    }

    function isNumeric(v) {
        if (typeof v === 'boolean') return false;
        if (typeof v === 'number') return isFinite(v);
        if (typeof v === 'string') return v.trim() !== '' && isFinite(Number(v));
        return false;
    }

    /* Returns [centavos|null, error|null]. Blank counts as 0. */
    function money(value, label) {
        if (isBlank(value)) return [0, null];
        if (!isNumeric(value)) return [null, label + ' must be a valid amount.'];
        var f = Number(value);
        if (f < 0)          return [null, label + ' cannot be negative.'];
        if (f > MAX_AMOUNT) return [null, label + ' is too large.'];
        return [roundHalfUp(f * 100), null];
    }

    /* Half-up integer division for non-negative integers. */
    function divRound(num, den) {
        return Math.floor((2 * num + den) / (2 * den));
    }

    function pesos(cents) {
        return Math.round(cents) / 100;
    }

    /**
     * @param {Object} input  accommodation_fee, extra_guest_fee, other_charges,
     *                        total_guests, discount_percent, discount_guests, amount_paid
     * @param {Object} [opts] { maxPercent }
     * @returns {Object} { ok, errors[] } and, when ok, the same fields as the PHP version.
     */
    function calculate(input, opts) {
        input = input || {};
        var maxPct = (opts && typeof opts.maxPercent === 'number') ? opts.maxPercent : MAX_PERCENT;
        var errors = [];
        var r, feeC, extraC, otherC, paidC;

        r = money(input.accommodation_fee, 'Accommodation fee'); feeC   = r[0]; if (r[1]) errors.push(r[1]);
        r = money(input.extra_guest_fee,   'Extra guest fee');   extraC = r[0]; if (r[1]) errors.push(r[1]);
        r = money(input.other_charges,     'Other charges');     otherC = r[0]; if (r[1]) errors.push(r[1]);
        r = money(input.amount_paid,       'Amount paid');       paidC  = r[0]; if (r[1]) errors.push(r[1]);

        /* Total guests: whole number, at least 1 */
        var guests = 0;
        if (!isNumeric(input.total_guests) || Number(input.total_guests) !== Math.floor(Number(input.total_guests))) {
            errors.push('Total guests must be a whole number.');
        } else {
            guests = Number(input.total_guests);
            if (guests < 1) errors.push('Total guests must be greater than zero.');
        }

        /* Discount percentage: 0 … max */
        var pctRaw = isBlank(input.discount_percent) ? 0 : input.discount_percent;
        var pctH = 0; // hundredths of a percent (20.00% -> 2000)
        if (!isNumeric(pctRaw)) {
            errors.push('Discount percentage must be a valid number.');
        } else {
            var pct = Number(pctRaw);
            if (pct < 0) {
                errors.push('Discount percentage cannot be negative.');
            } else if (pct > maxPct) {
                errors.push('Discount percentage cannot exceed ' + String(maxPct) + '%.');
            } else {
                pctH = roundHalfUp(pct * 100);
            }
        }

        /* Guests with discount: whole number, 0 … total guests */
        var dgRaw = isBlank(input.discount_guests) ? 0 : input.discount_guests;
        var dg = 0;
        if (!isNumeric(dgRaw) || Number(dgRaw) !== Math.floor(Number(dgRaw))) {
            errors.push('Guests with discount must be a whole number.');
        } else {
            dg = Number(dgRaw);
            if (dg < 0) {
                errors.push('Guests with discount cannot be negative.');
            } else if (guests >= 1 && dg > guests) {
                errors.push('Guests with discount (' + dg + ') cannot exceed the total number of guests (' + guests + ').');
            }
        }

        if (errors.length) return { ok: false, errors: errors };

        /* ---- the maths, all in integer centavos ---- */
        var shareC     = divRound(feeC, guests);
        var discPerC   = divRound(shareC * pctH, 10000);
        var uncappedC  = discPerC * dg;
        var totalDiscC = Math.min(uncappedC, feeC);
        var finalC     = feeC + extraC + otherC - totalDiscC;
        var balanceC   = Math.max(0, finalC - paidC);
        var status     = paidC <= 0 ? 'unpaid' : (paidC >= finalC ? 'paid' : 'partial');

        return {
            ok: true,
            errors: [],
            accommodation_fee:  pesos(feeC),
            extra_guest_fee:    pesos(extraC),
            other_charges:      pesos(otherC),
            total_guests:       guests,
            per_person_share:   pesos(shareC),
            discount_percent:   pctH / 100,
            discount_guests:    dg,
            discount_per_guest: pesos(discPerC),
            total_discount:     pesos(totalDiscC),
            discount_capped:    uncappedC > feeC,
            final_amount:       pesos(finalC),
            amount_paid:        pesos(paidC),
            balance:            pesos(balanceC),
            payment_status:     status
        };
    }

    /* ---------- formatting helpers ---------- */

    /* 1234.5 -> "₱1,234.50" (fixed locale so output is identical everywhere) */
    function peso(n) {
        var fixed = (Math.round(Number(n) * 100) / 100).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return '\u20B1' + parts.join('.');
    }

    /* 20 -> "20%", 12.5 -> "12.5%" */
    function pctLabel(p) {
        return (Math.round(Number(p) * 100) / 100) + '%';
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : (many || one + 's'));
    }

    /**
     * Human-readable calculation lines built from a successful calculate()
     * result — nothing is hard-coded, every number comes from the data.
     */
    function explain(c) {
        var lines = [];

        lines.push(peso(c.accommodation_fee) + ' \u00F7 ' + plural(c.total_guests, 'guest') +
                   ' = ' + peso(c.per_person_share) + ' per guest');

        if (c.discount_guests > 0 && c.discount_percent > 0) {
            lines.push(peso(c.per_person_share) + ' \u00D7 ' + pctLabel(c.discount_percent) +
                       ' = ' + peso(c.discount_per_guest) + ' discount per eligible guest');
            lines.push(peso(c.discount_per_guest) + ' \u00D7 ' + plural(c.discount_guests, 'eligible guest') +
                       ' = ' + peso(c.total_discount) + ' total discount' +
                       (c.discount_capped ? ' (limited to the accommodation fee)' : ''));
        } else {
            lines.push('No guest is receiving a discount, so the total discount is ' + peso(0) + '.');
        }

        /* Plain "A − B = C" when there are no extras; labelled terms when there are. */
        var hasExtras = c.extra_guest_fee > 0 || c.other_charges > 0;
        var terms = [peso(c.accommodation_fee) + (hasExtras ? ' accommodation' : '')];
        if (c.extra_guest_fee > 0) terms.push('+ ' + peso(c.extra_guest_fee) + ' extra guest fees');
        if (c.other_charges   > 0) terms.push('+ ' + peso(c.other_charges)   + ' other charges');
        if (c.total_discount  > 0) terms.push('\u2212 ' + peso(c.total_discount) + (hasExtras ? ' discount' : ''));
        lines.push(terms.join(' ') + ' = ' + peso(c.final_amount) + ' final amount');

        return lines;
    }

    return {
        MAX_PERCENT: MAX_PERCENT,
        MAX_AMOUNT: MAX_AMOUNT,
        calculate: calculate,
        explain: explain,
        peso: peso,
        pctLabel: pctLabel
    };
});