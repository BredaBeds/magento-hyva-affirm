<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\ViewModel\Sdk;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SdkTest extends TestCase
{
    private Config&MockObject $config;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->configMock();
        $this->config->method('getScriptUrl')->willReturn('https://cdn1-sandbox.affirm.com/js/v2/affirm.js');
        $this->config->method('getLocale')->willReturn('en_US');
        $this->config->method('getCountryCode')->willReturn('USA');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testLoaderConfigIsAffirmsDocumentedConfig(): void
    {
        $this->config->expects($this->once())->method('getPublicApiKey')->willReturn('PUBLIC');
        $sdk = new Sdk($this->config, $this->logger);

        $expected = [
            'public_api_key' => 'PUBLIC',
            'script' => 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js',
            'locale' => 'en_US',
            'country_code' => 'USA',
        ];
        $this->assertSame($expected, $sdk->getLoaderConfig());
        $this->assertSame($expected, $sdk->getLoaderConfig());
    }

    public function testConfiguredNeedsActiveGatewayCurrencyAndKey(): void
    {
        $this->config->method('isActive')->willReturnOnConsecutiveCalls(true, false, true);
        $this->config->method('isCurrencyValid')->willReturnOnConsecutiveCalls(true, false);
        $this->config->method('getPublicApiKey')->willReturn('PUBLIC');

        $this->assertTrue((new Sdk($this->config, $this->logger))->isConfigured());
        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    public function testMissingKeyMeansNotConfigured(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->config->method('isCurrencyValid')->willReturn(true);
        $this->config->method('getPublicApiKey')->willReturn('');

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    public function testErrorsMeanNotConfigured(): void
    {
        $this->config->method('isActive')->willThrowException(new \RuntimeException('store'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    /**
     * Config mock whose canLoadAffirmJs() stays real: it combines the stubbed isActive, key and currency checks.
     *
     * @return Config&MockObject
     */
    private function configMock(): Config
    {
        $stubbed = array_diff(get_class_methods(Config::class), ['__construct', 'canLoadAffirmJs']);

        return $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_values($stubbed))
            ->getMock();
    }
}
