<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * Voice-to-text: the widget always showed the generic "نمی‌توانم به سرویس
 * پاسخ‌گویی وصل شوم" message with nothing in any log to say why, because
 * ai_agent_transcribe_audio() (unlike ai_agent_api_request()) never called
 * error_log() on a failed request. This checks the real behaviour: the
 * function still returns the same user-facing text on failure, and now also
 * writes a diagnostic line an admin can find.
 */
final class VoiceTranscribeTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
        ai_agent_save_api_key('sk_live_test');
    }

    private function serverReplies(array $body, int $code): void
    {
        WPStub::$responses['/chat/transcribe'] = [
            'response' => ['code' => $code],
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function test_a_backend_failure_still_surfaces_the_servers_message_to_the_caller(): void
    {
        $this->serverReplies([
            'detail' => 'در حال حاضر نمی‌توانم به سرویس پاسخ‌گویی وصل شوم. لطفاً چند لحظه‌ی دیگر دوباره تلاش کنید.',
        ], 502);

        $tmp = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($tmp, 'fake-audio-bytes');

        $result = ai_agent_transcribe_audio($tmp, 'voice.webm', 3.2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('نمی‌توانم به سرویس پاسخ‌گویی وصل شوم', $result['error']);

        unlink($tmp);
    }

    public function test_a_failed_transcription_request_writes_a_diagnostic_log_line(): void
    {
        $this->serverReplies(['detail' => 'خطای موقت سرویس تبدیل صوت.'], 502);

        $tmp = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($tmp, 'fake-audio-bytes');

        $logFile = tempnam(sys_get_temp_dir(), 'errlog');
        $previousLogFile = ini_set('error_log', $logFile);

        ai_agent_transcribe_audio($tmp, 'voice.webm', 3.2);

        ini_set('error_log', $previousLogFile);
        $logged = file_get_contents($logFile);

        $this->assertStringContainsString('AI_AGENT_DEBUG transcribe', $logged);
        $this->assertStringContainsString('502', $logged);

        unlink($tmp);
        unlink($logFile);
    }
}
