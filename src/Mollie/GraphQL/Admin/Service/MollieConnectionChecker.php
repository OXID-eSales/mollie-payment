<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Adapter\MollieConnectionAdapterInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `adminMollieConnectionCheck`: Mollie's own judgement of the configured key. Only the HTTP status of a refusal is
 * looked at: 401 and 403 mean the key is wrong, anything else (no answer, 5xx, 429) means Mollie could not be asked.
 * The log names the outcome, never the key and never Mollie's message.
 */
final class MollieConnectionChecker implements MollieConnectionCheckerInterface
{
    private const REFUSING_STATUSES = [401, 403];

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly MollieConnectionAdapterInterface $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(): MollieConnectionStatus
    {
        try {
            $mode = $this->config->getMode();
            if ($this->config->getApiKey() === '') {
                return new MollieConnectionStatus(MollieConnectionStatus::NOT_CONFIGURED, $mode);
            }
        } catch (MollieConfigurationException) {
            return new MollieConnectionStatus(MollieConnectionStatus::NOT_CONFIGURED, '');
        }

        return new MollieConnectionStatus($this->verdict(), $mode);
    }

    private function verdict(): string
    {
        try {
            $this->connection->verifyConnection();
        } catch (MollieConfigurationException) {
            return $this->logged(MollieConnectionStatus::INVALID_KEY);
        } catch (MollieAdapterException $refused) {
            return $this->logged(
                in_array($refused->getCode(), self::REFUSING_STATUSES, true)
                    ? MollieConnectionStatus::INVALID_KEY
                    : MollieConnectionStatus::UNREACHABLE,
                $refused->getCode(),
            );
        } catch (Throwable) {
            return $this->logged(MollieConnectionStatus::UNREACHABLE);
        }

        return MollieConnectionStatus::OK;
    }

    private function logged(string $status, ?int $httpStatus = null): string
    {
        $this->logger->warning('[MollieConnectionChecker] the connection check failed', [
            'status' => $status,
            'httpStatus' => $httpStatus,
        ]);

        return $status;
    }
}
