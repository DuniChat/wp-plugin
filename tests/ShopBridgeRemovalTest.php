<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * The WooCommerce basket and order bridge is gone, and must stay gone.
 *
 * Removing the code is only half of it. Three things would otherwise sit in
 * wp_options forever: the on/off setting, the flag from the migration that
 * turned it on by default, and the bridge secret. The secret is the one that
 * matters -- a shared secret nothing consumes any more is one that only waits
 * to leak.
 */
final class ShopBridgeRemovalTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
    }

    /** @dataProvider goneFunctions */
    public function test_the_bridge_functions_are_gone(string $name): void
    {
        $this->assertFalse(
            function_exists($name),
            "$name still exists; the shop bridge was supposed to be removed"
        );
    }

    public static function goneFunctions(): array
    {
        return array_map(fn($n) => [$n], [
            'ai_agent_shop_bridge_secret',
            'ai_agent_shop_token_for_current_user',
            'ai_agent_user_id_for_shop_token',
            'ai_agent_register_shop_bridge_route',
            'ai_agent_shop_bridge_handler',
            'ai_agent_shop_account_summary',
            'ai_agent_shop_cart_snapshot',
            'ai_agent_shop_recent_orders',
            'ai_agent_shop_cart_modify',
            'ai_agent_woocommerce_active',
        ]);
    }

    public function test_the_bridge_file_is_gone(): void
    {
        $this->assertFileDoesNotExist(AI_AGENT_PATH . 'includes/shop-bridge.php');
    }

    public function test_no_rest_route_is_registered_for_the_bridge(): void
    {
        $this->assertFalse(
            WPStub::hasHook('rest_api_init', 'ai_agent_register_shop_bridge_route')
        );
    }

    public function test_the_defaults_no_longer_carry_the_setting(): void
    {
        $this->assertArrayNotHasKey('shop_bridge_enabled', ai_agent_default_settings());
    }

    public function test_the_migration_deletes_the_leftover_secret(): void
    {
        // Exactly what an upgrading site has in the table.
        update_option('ai_agent_shop_bridge_secret', 'a-real-shared-secret');
        update_option('ai_agent_shop_bridge_default_on', 1);
        update_option('ai_agent_settings', [
            'color_light' => '#503AA8',
            'shop_bridge_enabled' => 1,
        ]);

        ai_agent_maybe_purge_shop_bridge();

        $this->assertFalse(get_option('ai_agent_shop_bridge_secret'));
        $this->assertFalse(get_option('ai_agent_shop_bridge_default_on'));
        $this->assertArrayNotHasKey('shop_bridge_enabled', get_option('ai_agent_settings'));
    }

    public function test_the_migration_leaves_every_other_setting_alone(): void
    {
        update_option('ai_agent_settings', [
            'color_light' => '#503AA8',
            'daily_message_limit' => 250,
            'shop_bridge_enabled' => 1,
        ]);

        ai_agent_maybe_purge_shop_bridge();

        $settings = get_option('ai_agent_settings');
        $this->assertSame('#503AA8', $settings['color_light']);
        $this->assertSame(250, $settings['daily_message_limit']);
    }

    public function test_the_migration_runs_once_and_is_safe_to_repeat(): void
    {
        update_option('ai_agent_shop_bridge_secret', 'a-real-shared-secret');
        ai_agent_maybe_purge_shop_bridge();
        $this->assertSame(1, get_option('ai_agent_shop_bridge_purged'));

        // A second run must not throw, and must not resurrect anything.
        ai_agent_maybe_purge_shop_bridge();
        $this->assertFalse(get_option('ai_agent_shop_bridge_secret'));
    }

    public function test_the_migration_survives_a_site_with_no_settings_row(): void
    {
        $this->assertFalse(get_option('ai_agent_settings'));
        ai_agent_maybe_purge_shop_bridge();
        $this->assertFalse(get_option('ai_agent_settings'));
    }

    public function test_the_chat_request_carries_no_shop_token(): void
    {
        ai_agent_save_api_key('sk_live_test');
        $source = file_get_contents(AI_AGENT_PATH . 'includes/api.php');
        $this->assertStringNotContainsString('shop_token', $source);
    }
}
