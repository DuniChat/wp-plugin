<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * What the plugin sends to the server when its settings are saved.
 *
 * The option array is not guaranteed to be complete. Anything that reads it
 * raw, adds a key and writes it back -- the admin_init migrations do exactly
 * that -- produces a partial array, and update_option fires the push with it.
 * Two of the hand-written fallbacks for missing keys were values that switch
 * the site off, and this is where that has to be caught.
 */
final class SettingsPushTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
        ai_agent_save_api_key('sk_live_test');
        WPStub::$responses['/sync/settings'] = [
            'response' => ['code' => 200],
            'body' => '{}',
        ];
    }

    /** The PATCH body the plugin would send for a given stored option array. */
    private function pushedPayload(array $stored): array
    {
        WPStub::$requests = [];
        ai_agent_after_settings_saved([], $stored);

        foreach (WPStub::$requests as $request) {
            $method = $request['args']['method'] ?? '';
            if ($method === 'PATCH' && strpos($request['url'], '/sync/settings') !== false) {
                return json_decode($request['args']['body'] ?? '{}', true) ?: [];
            }
        }
        $this->fail('the plugin sent no PATCH to /sync/settings');
    }

    public function test_the_defaults_are_values_the_server_accepts(): void
    {
        $defaults = ai_agent_default_settings();

        $this->assertGreaterThanOrEqual(
            1,
            $defaults['daily_message_limit'],
            'a daily limit of zero means the assistant answers nothing'
        );
        $this->assertStringNotContainsString(
            '/',
            (string) $defaults['model'],
            'the server rejects any model id containing a slash'
        );
    }

    public function test_a_complete_option_array_is_sent_as_saved(): void
    {
        $stored = ai_agent_default_settings();
        $stored['daily_message_limit'] = 250;
        $stored['model'] = 'gpt-4o-mini';
        $stored['organization_name'] = 'فروشگاه';

        $payload = $this->pushedPayload($stored);

        $this->assertSame(250, $payload['daily_message_limit']);
        $this->assertSame('gpt-4o-mini', $payload['selected_model']);
        $this->assertSame('فروشگاه', $payload['organization_name']);
    }

    public function test_a_partial_option_array_never_sends_a_zero_daily_limit(): void
    {
        // Regression: this is the array an admin_init migration leaves behind
        // on a site upgrading from a version that never stored the key. The
        // push sent 0, the server enforces `count >= limit`, and the site
        // answered nothing from that moment on -- with nobody having touched
        // a setting.
        $payload = $this->pushedPayload(['color_light' => '#503AA8']);

        $this->assertGreaterThanOrEqual(
            1,
            $payload['daily_message_limit'],
            'a missing key must mean the default, not a limit of zero'
        );
    }

    public function test_a_partial_option_array_never_sends_an_empty_model(): void
    {
        // The other half of the same bug. An empty selected_model matches no
        // catalogue row, so every chat request is rejected -- and because the
        // server refuses the whole PATCH over one bad field, no other setting
        // saved either.
        $payload = $this->pushedPayload(['color_light' => '#503AA8']);

        $this->assertArrayNotHasKey(
            'selected_model',
            $payload,
            'with no model chosen the key must be omitted, so the server keeps its own'
        );
    }

    public function test_nothing_is_sent_without_an_api_key(): void
    {
        delete_option(AI_AGENT_API_KEY_OPTION);
        WPStub::$requests = [];

        ai_agent_after_settings_saved([], ai_agent_default_settings());

        $this->assertSame([], WPStub::$requests);
    }

    public function test_the_image_sync_checkbox_travels_inside_allowed_statuses(): void
    {
        $stored = ai_agent_default_settings();
        $stored['sync_images'] = true;
        $this->assertSame(['allow-image'], $this->pushedPayload($stored)['allowed_statuses']['image']);

        $stored['sync_images'] = false;
        $this->assertSame(['deny-image'], $this->pushedPayload($stored)['allowed_statuses']['image']);
    }
}
