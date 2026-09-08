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
 * An HTTP status from the DuniChat API as a message a site owner can act on.
 *
 * 401 only ever means one thing here: the API key is missing or wrong. Saying
 * so directly is more useful than "server replied with error code 401", which
 * reads like something the owner has to debug rather than a field to fill in.
 */
function ai_agent_http_error_message($code, $prefix = 'سرور') {
    $code = intval($code);
    if ($code === 401) {
        return 'کلید API خودتون رو وارد کنین.';
    }
    return $prefix . ' با کد خطای ' . $code . ' پاسخ داد.';
}

/**
 * Gregorian to Jalali (Solar Hijri), the standard integer algorithm.
 *
 * Returns array(year, month, day).
 */
function ai_agent_gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);

    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4)) - ((int) (($gy2 + 99) / 100))
          + ((int) (($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];

    $jy = -1595 + (33 * ((int) ($days / 12053)));
    $days %= 12053;

    $jy += 4 * ((int) ($days / 1461));
    $days %= 1461;

    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + (int) ($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int) (($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return array($jy, $jm, $jd);
}

/**
 * A stored MySQL datetime as a Persian date a site owner can read.
 *
 * Timestamps are stored as ordinary MySQL datetimes -- sortable, comparable,
 * and the same shape WordPress uses everywhere else -- and converted here, at
 * the display boundary, exactly as rial is converted to toman above.
 * Converting at the storage end instead would make every stored value a string
 * nothing can compare, and would break every timestamp already written.
 *
 * Returns the input unchanged if it is not a date, so a malformed option value
 * shows as itself rather than as a wrong date.
 */
function ai_agent_format_jalali_datetime($mysql_datetime, $with_time = true) {

    $value = trim((string) $mysql_datetime);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    list($jy, $jm, $jd) = ai_agent_gregorian_to_jalali(
        (int) date('Y', $timestamp),
        (int) date('n', $timestamp),
        (int) date('j', $timestamp)
    );

    $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    if ($with_time) {
        $out .= ' ' . date('H:i', $timestamp);
    }

    return ai_agent_fa_digits($out);
}

/**
 * Below this balance (in rial) the plugin warns the owner. 50,000 toman is
 * roughly a few days of a small site's usage, which is enough notice to top up
 * before the assistant stops answering.
 */
if (!defined('AI_AGENT_LOW_BALANCE_IRR')) {
    define('AI_AGENT_LOW_BALANCE_IRR', 500000);
}
