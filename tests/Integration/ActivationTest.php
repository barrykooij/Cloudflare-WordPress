<?php

namespace Cloudflare\APO\Tests\Integration;

use Cloudflare\APO\API\Plugin;
use Cloudflare\APO\WordPress\DataStore;

/**
 * Plugin activation and deactivation.
 */
class ActivationTest extends IntegrationTestCase
{
    public function testActivationSucceedsOnASupportedWordPressVersion()
    {
        do_action('activate_cloudflare/cloudflare.php', false);

        $this->assertTrue($this->pluginHooks()->activate());
        $this->assertTrue(is_plugin_active('cloudflare/cloudflare.php'));
    }

    public function testDeactivationRemovesStoredCredentialsAndSettings()
    {
        $this->setPluginSetting(Plugin::SETTING_AUTOMATIC_PLATFORM_OPTIMIZATION, 'on');
        $this->setPluginSetting(Plugin::SETTING_PLUGIN_SPECIFIC_CACHE, 'on');

        do_action('deactivate_cloudflare/cloudflare.php', false);

        $this->assertFalse(get_option(DataStore::API_KEY));
        $this->assertFalse(get_option(DataStore::EMAIL));
        $this->assertFalse(get_option(DataStore::CACHED_DOMAIN_NAME));
        $this->assertFalse(get_option(Plugin::SETTING_AUTOMATIC_PLATFORM_OPTIMIZATION));
        $this->assertFalse(get_option(Plugin::SETTING_PLUGIN_SPECIFIC_CACHE));
    }
}
