<?php
namespace Dunichat\Tests;

use PHPUnit\Framework\TestCase;
use WPStub;

/**
 * The widget's front-end JS/CSS are enqueued with a version query string tied
 * to AI_AGENT_VERSION, the way the admin settings screen already does it.
 *
 * Regression: ai_agent_enqueue() passed `null` as the version to
 * wp_enqueue_script('ai-agent-js', ...) and passed no version at all to
 * wp_enqueue_style('ai-agent-css', ...). WordPress appends `?ver=<version>`
 * to an enqueued file's URL only when a version is given; `null` explicitly
 * suppresses it. With no version query string, the widget's script/style URL
 * never changes across plugin updates, so browsers and any CDN/proxy in
 * front of the site keep serving the copy they cached before the update --
 * indefinitely, until a visitor manually clears their cache. A fix landed in
 * the plugin's code (e.g. the visitor_id fix for the "previous conversations"
 * history drawer) can sit on the live site for a long time without actually
 * reaching a returning visitor's browser, because the URL that would tell
 * the cache "this changed" never changes.
 */
final class EnqueueCacheBustingTest extends TestCase
{
    protected function setUp(): void
    {
        WPStub::reset();
    }

    public function test_widget_script_is_versioned_with_the_plugin_version(): void
    {
        ai_agent_enqueue();

        $this->assertArrayHasKey('ai-agent-js', WPStub::$enqueuedScripts);
        $version = WPStub::$enqueuedScripts['ai-agent-js']['version'];

        $this->assertNotNull($version, 'ai-agent.js must not be enqueued with version=null -- that suppresses the ?ver= cache-busting query string entirely.');
        $this->assertSame(AI_AGENT_VERSION, $version);
    }

    public function test_widget_style_is_versioned_with_the_plugin_version(): void
    {
        ai_agent_enqueue();

        $this->assertArrayHasKey('ai-agent-css', WPStub::$enqueuedStyles);
        $version = WPStub::$enqueuedStyles['ai-agent-css']['version'];

        $this->assertNotNull($version);
        $this->assertSame(AI_AGENT_VERSION, $version);
    }
}
