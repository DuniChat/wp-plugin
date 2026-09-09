<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * The update-check status line shown under the plugin in the plugins list.
 *
 * Regression: a successful check that found nothing to update, and a
 * successful check where the server gave a relative download URL (missing
 * API_PUBLIC_BASE_URL on the DuniChat side), both stored ok=false and were
 * shown as "ناموفق" (failed) -- indistinguishable from a real connection
 * failure. An admin reading "failed" went looking for a network problem that
 * did not exist, instead of what was actually wrong: nobody had uploaded a
 * new zip, or the server's own configuration was incomplete.
 */
final class UpdaterStatusTest extends TestCase
{
    private \Dunichat_Updater $updater;

    protected function setUp(): void
    {
        WPStub::reset();
        $this->updater = new \Dunichat_Updater(AI_AGENT_PATH . 'ai-agent.php');
    }

    private function rowMetaText(): string
    {
        $links = $this->updater->row_meta([], 'dunichat/ai-agent.php');
        return end($links);
    }

    public function test_no_check_yet_says_so_plainly(): void
    {
        $this->assertStringContainsString('هنوز بررسی نشده', $this->rowMetaText());
    }

    public function test_a_real_connection_failure_says_failed(): void
    {
        update_option('dunichat_updater_status', [
            'time' => time(), 'reason' => 'error', 'code' => 0, 'version' => '',
        ]);
        $this->assertStringContainsString('ناموفق', $this->rowMetaText());
    }

    public function test_a_successful_check_with_nothing_new_does_not_say_failed(): void
    {
        update_option('dunichat_updater_status', [
            'time' => time(), 'reason' => 'no_release', 'code' => 200, 'version' => '',
        ]);
        $text = $this->rowMetaText();
        $this->assertStringNotContainsString('ناموفق', $text);
        $this->assertStringContainsString('ارتباط برقرار شد', $text);
    }

    public function test_a_missing_public_base_url_on_the_server_is_named_not_called_a_failure(): void
    {
        update_option('dunichat_updater_status', [
            'time' => time(), 'reason' => 'bad_package_url', 'code' => 200, 'version' => '',
        ]);
        $text = $this->rowMetaText();
        $this->assertStringNotContainsString('ناموفق', $text);
        $this->assertStringContainsString('پیکربندی سرور ناقص است', $text);
    }

    public function test_a_real_update_is_reported_with_its_version(): void
    {
        update_option('dunichat_updater_status', [
            'time' => time(), 'reason' => 'ok', 'code' => 200, 'version' => '9.9.9',
        ]);
        $text = $this->rowMetaText();
        $this->assertStringContainsString('موفق', $text);
        $this->assertStringContainsString('9.9.9', $text);
    }

    public function test_an_old_style_stored_status_still_renders(): void
    {
        // Before this change, only a boolean 'ok' was stored. A site that
        // upgrades the plugin still has the old shape sitting in its options
        // table until the next check overwrites it.
        update_option('dunichat_updater_status', [
            'time' => time(), 'ok' => false, 'code' => 500,
        ]);
        $this->assertStringContainsString('ناموفق', $this->rowMetaText());
    }
}
