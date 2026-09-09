<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * Reading settings back from the server, and the sanitiser that guards what
 * the settings form writes.
 *
 * The server is the source of truth after a save: it normalises phone numbers
 * and handles, and the plugin must show what the assistant will actually
 * quote rather than the owner's draft.
 */
final class SettingsSyncTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
        ai_agent_save_api_key('sk_live_test');
    }

    private function serverReplies(array $body, int $code = 200): void
    {
        WPStub::$responses['/sync/settings'] = [
            'response' => ['code' => $code],
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function test_server_values_land_in_the_local_settings(): void
    {
        $this->serverReplies([
            'selected_model' => 'gpt-4o-mini',
            'assistant_tone' => 'friendly',
            'organization_name' => 'فروشگاه نمونه',
            'daily_message_limit' => 250,
            'response_timeout_seconds' => 30,
            'sync_hour' => 5,
        ]);

        $result = ai_agent_sync_settings_from_server();
        $this->assertSame('success', $result['status']);

        $settings = ai_agent_get_settings();
        $this->assertSame('gpt-4o-mini', $settings['model']);
        $this->assertSame('friendly', $settings['assistant_tone']);
        $this->assertSame('فروشگاه نمونه', $settings['organization_name']);
        $this->assertSame(30, $settings['timeout']);
        $this->assertSame(5, $settings['sync_hour']);
    }

    public function test_an_out_of_range_sync_hour_is_clamped_not_stored(): void
    {
        $this->serverReplies(['selected_model' => 'gpt-4o-mini', 'sync_hour' => 99]);
        ai_agent_sync_settings_from_server();
        $this->assertSame(23, ai_agent_get_settings()['sync_hour']);
    }

    public function test_an_error_body_is_reported_rather_than_applied(): void
    {
        $this->serverReplies(['detail' => 'کلید API نامعتبر است'], 401);

        $result = ai_agent_sync_settings_from_server();

        $this->assertSame('error', $result['status']);
        $this->assertNotSame('', $result['message']);
    }

    public function test_a_response_of_the_wrong_shape_is_refused(): void
    {
        // Anything but the settings payload -- a proxy's HTML error page, say.
        $this->serverReplies(['something' => 'else']);
        $this->assertSame('error', ai_agent_sync_settings_from_server()['status']);
    }

    public function test_an_empty_model_from_the_server_does_not_wipe_the_local_one(): void
    {
        update_option('ai_agent_settings', ['model' => 'gpt-4o-mini']);
        $this->serverReplies(['selected_model' => '', 'assistant_tone' => 'neutral']);

        ai_agent_sync_settings_from_server();

        $this->assertSame('gpt-4o-mini', ai_agent_get_settings()['model']);
    }

    // ------------------------------------------------------------ sanitiser

    public function test_the_sanitiser_keeps_the_previous_model_when_none_is_submitted(): void
    {
        update_option('ai_agent_settings', ['model' => 'gpt-4o-mini']);

        $output = ai_agent_sanitize_settings(['model' => '']);

        // Substituting a fixed id here replaced the owner's real choice with
        // one the server would refuse, and then no setting saved at all.
        $this->assertSame('gpt-4o-mini', $output['model']);
    }

    public function test_the_sanitiser_never_writes_a_slashed_model_id(): void
    {
        $output = ai_agent_sanitize_settings([]);
        $this->assertStringNotContainsString('/', (string) $output['model']);
    }

    public function test_the_sanitiser_keeps_the_stored_key_when_the_field_is_blank(): void
    {
        // The form renders the key masked, so an empty box means "unchanged",
        // not "delete it".
        update_option('ai_agent_settings', ['api_key' => 'sk_live_existing']);
        $output = ai_agent_sanitize_settings(['api_key' => '']);
        $this->assertSame('sk_live_existing', $output['api_key']);
    }

    public function test_the_daily_limit_is_never_negative(): void
    {
        $this->assertSame(0, ai_agent_sanitize_settings(['daily_message_limit' => -20])['daily_message_limit']);
    }
}
