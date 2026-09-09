<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The starter-question chips a visitor sees before typing.
 *
 * Regression: when the admin left this list empty, the plugin used to guess
 * several questions on its own from other settings -- including "how do I
 * message you on Telegram?" the moment an owner filled in a Telegram *contact
 * handle*, which is not a bot and never was. The assistant has no Telegram
 * bot to answer that with, so the guessed question invited exactly the
 * question it could not answer. Now: nothing shown unless the admin wrote it.
 */
final class StarterQuestionsTest extends TestCase
{
    public function test_nothing_is_guessed_when_the_admin_left_it_empty(): void
    {
        $starters = ai_agent_build_starters([
            'organization_name' => 'فروشگاه نمونه',
            'support_phones' => ['09120000000'],
            'instagram_id' => 'example',
            'telegram_id' => 'example',
        ]);
        $this->assertSame([], $starters);
    }

    public function test_no_telegram_question_is_ever_guessed(): void
    {
        $starters = ai_agent_build_starters(['telegram_id' => 'example']);
        $this->assertSame([], $starters);
    }

    public function test_only_the_admins_own_questions_are_shown(): void
    {
        $starters = ai_agent_build_starters([
            'starter_questions' => ['قیمت محصول X چنده؟', 'ارسال به شهرستان دارید؟'],
            'telegram_id' => 'example',
        ]);
        $this->assertSame(
            [
                ['label' => 'قیمت محصول X چنده؟', 'prompt' => 'قیمت محصول X چنده؟'],
                ['label' => 'ارسال به شهرستان دارید؟', 'prompt' => 'ارسال به شهرستان دارید؟'],
            ],
            $starters
        );
    }

    public function test_blank_entries_are_dropped(): void
    {
        $starters = ai_agent_build_starters(['starter_questions' => ['', '  ', 'سوال واقعی']]);
        $this->assertCount(1, $starters);
        $this->assertSame('سوال واقعی', $starters[0]['label']);
    }

    public function test_a_missing_key_is_the_same_as_an_empty_one(): void
    {
        $this->assertSame([], ai_agent_build_starters([]));
    }
}
