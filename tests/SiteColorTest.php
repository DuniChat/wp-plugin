<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * Picking the assistant's colour from the host theme, and lifting it for dark
 * mode. The dark colour is computed in two places -- once at activation and
 * once on save -- and they have to agree, or the colour visibly changes the
 * first time an owner presses Save.
 */
final class SiteColorTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
    }

    public function test_greys_black_and_white_are_not_treated_as_brand_colours(): void
    {
        // Nearly every theme has these in its palette. Accepting them means
        // always picking the text colour instead of the brand colour.
        $this->assertFalse(ai_agent_is_usable_brand_color('#ffffff'));
        $this->assertFalse(ai_agent_is_usable_brand_color('#000000'));
        $this->assertFalse(ai_agent_is_usable_brand_color('#808080'));
        $this->assertFalse(ai_agent_is_usable_brand_color('#f7f7f8'));
    }

    public function test_a_real_brand_colour_is_accepted(): void
    {
        $this->assertTrue(ai_agent_is_usable_brand_color('#C96442'));
        $this->assertTrue(ai_agent_is_usable_brand_color('#503AA8'));
    }

    public function test_a_colour_too_pale_or_too_dark_to_read_is_rejected(): void
    {
        $this->assertFalse(ai_agent_is_usable_brand_color('#fffbe6'));
        $this->assertFalse(ai_agent_is_usable_brand_color('#020104'));
    }

    public function test_a_malformed_colour_is_rejected_rather_than_guessed(): void
    {
        $this->assertFalse(ai_agent_is_usable_brand_color('not-a-colour'));
        $this->assertFalse(ai_agent_is_usable_brand_color(''));
        $this->assertFalse(ai_agent_is_usable_brand_color('#12345'));
    }

    public function test_lightening_moves_a_colour_towards_white(): void
    {
        $this->assertSame('#ffffff', ai_agent_lighten_hex('#000000', 1));
        $this->assertSame('#000000', ai_agent_lighten_hex('#000000', 0));
        $this->assertSame('#808080', ai_agent_lighten_hex('#000000', 0.502));
    }

    public function test_lightening_clamps_instead_of_overshooting(): void
    {
        $this->assertSame('#ffffff', ai_agent_lighten_hex('#3366cc', 5));
        $this->assertSame('#3366cc', ai_agent_lighten_hex('#3366cc', -2));
    }

    public function test_every_channel_stays_two_hex_digits(): void
    {
        // Regression shape: dechex(5) is "5", and an unpadded channel makes a
        // string that is not a colour at all.
        $this->assertMatchesRegularExpression(
            '/^#[0-9a-f]{6}$/',
            ai_agent_lighten_hex('#010203', 0.01)
        );
    }

    public function test_activation_seeds_both_colours_from_one_lift_constant(): void
    {
        // The two colours must be derived with the same constant everywhere:
        // when they were not, the dark colour changed the first time the owner
        // saved, for no reason they could see.
        WPStub::$options['__theme_mod_primary_color'] = '#3366cc';
        ai_agent_seed_color_from_site();
        $settings = get_option('ai_agent_settings');

        $this->assertArrayHasKey('color_light', $settings);
        $this->assertArrayHasKey('color_dark', $settings);
        $this->assertSame(
            ai_agent_lighten_hex($settings['color_light'], AI_AGENT_DARK_LIFT),
            $settings['color_dark']
        );
    }

    public function test_a_site_with_no_detectable_colour_keeps_the_brand_default(): void
    {
        ai_agent_seed_color_from_site();

        // Nothing detected means nothing written -- the packaged default
        // stands rather than an invented colour.
        $this->assertSame([], get_option('ai_agent_settings', []));
    }

    public function test_seeding_does_not_overwrite_a_colour_the_owner_chose(): void
    {
        WPStub::$options['__theme_mod_primary_color'] = '#3366cc';
        update_option('ai_agent_settings', ['color_light' => '#123456', 'color_dark' => '#654321']);
        ai_agent_seed_color_from_site();

        $settings = get_option('ai_agent_settings');
        $this->assertSame('#123456', $settings['color_light']);
        $this->assertSame('#654321', $settings['color_dark']);
    }
}
