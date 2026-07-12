<?php

namespace Callcocam\WhatsAppCloud\Tests;

/**
 * The sandbox routes are registered in boot(), and only when the driver is
 * already `sandbox` — deliberately, so a simulator screen can never appear on top
 * of the real wire. That means the driver has to be set BEFORE the app boots, not
 * in a beforeEach.
 *
 * Any test that drives the sandbox through HTTP uses this.
 */
abstract class SandboxTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('whatsapp-cloud.driver', 'sandbox');
    }
}
