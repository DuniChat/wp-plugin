<?php
/**
 * Number and currency formatting for the admin screens.
 *
 * Every figure the plugin shows a site owner is Persian: Persian digits,
 * Persian thousands separators, and toman rather than rial. The API speaks in
 * rial throughout, so the conversion happens here at the display boundary and
 * nowhere else -- doing it at the call sites is how "۰ ریال" ended up next to
 * "0 تومان" on the same screen.
 */

if (!defined('ABSPATH')) exit;

/**
 * Latin digits to Persian, including the thousands separator.
 */
function ai_agent_fa_digits($value) {
    $latin   = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ',');
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٬');
    return str_replace($latin, $persian, (string) $value);
}

/**
 * Persian or Arabic-Indic digits back to Latin, so a value typed on a Persian
 * keyboard still parses as a number.
 */
function ai_agent_en_digits($value) {
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٬', '،');
    $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
    $latin   = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', '');
    $value   = str_replace($persian, $latin, (string) $value);
    return str_replace($arabic, array_slice($latin, 0, 10), $value);
}

/**
 * A rial amount as a grouped toman string in Persian digits.
 *
 * Negative balances are real -- storage is billed nightly and can take an
 * account below zero -- so the sign is kept rather than dropped.
 */
function ai_agent_format_toman($amount_irr, $with_unit = true) {
    $toman  = (int) round(((float) $amount_irr) / 10);
    $sign   = $toman < 0 ? '−' : '';
    $number = ai_agent_fa_digits(number_format(abs($toman)));
    return $sign . $number . ($with_unit ? ' تومان' : '');
}

/** A plain integer, grouped and in Persian digits. */
function ai_agent_format_number($value) {
    return ai_agent_fa_digits(number_format((int) $value));
}

/**
 * Below this balance (in rial) the plugin warns the owner. 50,000 toman is
 * roughly a few days of a small site's usage, which is enough notice to top up
 * before the assistant stops answering.
 */
if (!defined('AI_AGENT_LOW_BALANCE_IRR')) {
    define('AI_AGENT_LOW_BALANCE_IRR', 500000);
}
