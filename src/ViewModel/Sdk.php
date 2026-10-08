<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Configuration for the window.hyvaAffirm loader: Affirm's documented _affirm_config for the current store.
 */
class Sdk implements ArgumentInterface
{
    /** The loader template, for hyva_js_template_dependencies and BlockJsDependencies. */
    public const LOADER_TEMPLATE = 'Hyva_AstoundAffirm::js/sdk-loader.phtml';

    /** Page JS dependency priority of the loader: before scripts with the default priority (10). */
    public const LOADER_PRIORITY = 5;

    /** @var array{public_api_key: string, script: string, locale: string, country_code: string}|null */
    private ?array $loaderConfig = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * True when affirm.js can be loaded: the gateway is active, a public key exists for the mode and currency,
     * and the currency is supported.
     */
    public function isConfigured(): bool
    {
        try {
            return $this->config->isActive()
                && $this->config->isCurrencyValid()
                && $this->getLoaderConfig()['public_api_key'] !== '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: affirm.js configuration unavailable.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * @return array{public_api_key: string, script: string, locale: string, country_code: string}
     */
    public function getLoaderConfig(): array
    {
        if ($this->loaderConfig === null) {
            $this->loaderConfig = [
                'public_api_key' => $this->config->getPublicApiKey(),
                'script' => $this->config->getScriptUrl(),
                'locale' => $this->config->getLocale(),
                'country_code' => $this->config->getCountryCode(),
            ];
        }

        return $this->loaderConfig;
    }
}
