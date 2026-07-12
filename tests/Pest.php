<?php

use Callcocam\WhatsAppCloud\Tests\SandboxTestCase;
use Callcocam\WhatsAppCloud\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Its own suite, because it boots a different driver. The sandbox routes are only
// registered when the driver is already `sandbox` — that guard is the point, so
// the config has to be in place before the app boots, not in a beforeEach.
uses(SandboxTestCase::class)->in('Sandbox');
