<?php

namespace Cloudflare\APO\Tests\Integration;

use Cloudflare\APO\WordPress\Hooks;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin boots inside WordPress.
 */
class PluginBootTest extends TestCase
{
    public function testPluginIsActiveFromTheCloudflareFolder()
    {
        $this->assertTrue(is_plugin_active('cloudflare/cloudflare.php'));
    }

    public function testPluginConstantsAreDefined()
    {
        $this->assertTrue(defined('CLOUDFLARE_PLUGIN_DIR'));
        $this->assertSame(WP_PLUGIN_DIR . '/cloudflare/', CLOUDFLARE_PLUGIN_DIR);
    }

    public function testProxyAjaxActionIsRegistered()
    {
        $this->assertNotFalse(has_action('wp_ajax_' . Hooks::WP_AJAX_ACTION));
    }
}
