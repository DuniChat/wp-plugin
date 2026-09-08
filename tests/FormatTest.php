<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The display helpers. These are what a site owner reads in the admin screens,
 * and a wrong number here reads as a billing bug.
 */
final class FormatTest extends TestCase
{
    public function test_latin_digits_become_persian(): void
    {
        $this->assertSame('۱۲۳۴', ai_agent_fa_digits('1234'));
        $this->assertSame('۱٬۲۳۴', ai_agent_fa_digits('1,234'));
    }

    public function test_persian_and_arabic_digits_come_back_to_latin(): void
    {
        $this->assertSame('1234', ai_agent_en_digits('۱۲۳۴'));
        $this->assertSame('1234', ai_agent_en_digits('١٢٣٤'));
        // A number typed on a Persian keyboard must survive the round trip,
        // separators and all, or it parses as something else entirely.
        $this->assertSame('1234567', ai_agent_en_digits('۱٬۲۳۴٬۵۶۷'));
    }

    public function test_rial_is_shown_as_grouped_toman(): void
    {
        $this->assertSame('۱٬۰۰۰ تومان', ai_agent_format_toman(10000));
        $this->assertSame('۱٬۰۰۰', ai_agent_format_toman(10000, false));
    }

    public function test_a_negative_balance_keeps_its_sign(): void
    {
        // Storage is billed nightly and can take an account below zero.
        // Dropping the sign would show a debt as credit.
        $shown = ai_agent_format_toman(-25000);
        $this->assertStringStartsWith('−', $shown);
        $this->assertStringContainsString('۲٬۵۰۰', $shown);
    }

    public function test_rial_rounds_to_the_nearest_toman(): void
    {
        $this->assertSame('۱٬۰۰۰ تومان', ai_agent_format_toman(9996));
        $this->assertSame('۰ تومان', ai_agent_format_toman(4));
    }

    public function test_a_401_says_the_key_is_wrong_rather_than_quoting_a_number(): void
    {
        $message = ai_agent_http_error_message(401);
        $this->assertStringNotContainsString('401', $message);
        $this->assertNotSame('', $message);
    }

    /** @dataProvider knownDates */
    public function test_gregorian_dates_convert_to_the_right_jalali_date($g, $expected): void
    {
        $this->assertSame($expected, ai_agent_gregorian_to_jalali(...$g));
    }

    public static function knownDates(): array
    {
        return [
            'nowruz 1403'      => [[2024, 3, 20], [1403, 1, 1]],
            'first of 1400'    => [[2021, 3, 21], [1400, 1, 1]],
            'a leap-year end'  => [[2025, 3, 20], [1403, 12, 30]],
            'mid-year'         => [[2024, 9, 22], [1403, 7, 1]],
        ];
    }

    public function test_a_value_that_is_not_a_date_is_returned_untouched(): void
    {
        // A malformed option should show as itself, not as a wrong date.
        $this->assertSame('', ai_agent_format_jalali_datetime(''));
        $this->assertSame('not-a-date', ai_agent_format_jalali_datetime('not-a-date'));
    }

    public function test_a_stored_timestamp_renders_in_persian_digits(): void
    {
        $shown = ai_agent_format_jalali_datetime('2024-03-20 08:30:00');
        $this->assertStringContainsString('۱۴۰۳', $shown);
        $this->assertStringNotContainsString('2024', $shown);
    }
}
