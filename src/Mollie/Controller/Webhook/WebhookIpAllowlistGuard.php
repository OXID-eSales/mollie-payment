<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Rejects incoming webhook calls whose client IP isn't in the configured allowlist.
 *
 * NOT wired into the default guard chain (see services.yaml). Mollie, unlike PayPal, publishes
 * no fixed webhook source IP ranges — an always-on allowlist here would need constant upkeep and
 * risks false-rejecting legitimate deliveries after an undocumented Mollie infra change. Kept
 * available (default OFF, empty allowlist = disabled) for merchants who want to pin webhook
 * delivery to a reverse-proxy/CDN range they control. Empty allowlist is a permanent pass-through.
 */
final class WebhookIpAllowlistGuard implements WebhookRequestGuardInterface
{
    /**
     * @param list<string> $allowedCidrs
     */
    public function __construct(private readonly array $allowedCidrs = [])
    {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        if ($this->allowedCidrs === []) {
            return WebhookGuardResult::pass();
        }

        $ip = trim($request->clientIp);
        if ($ip === '') {
            return WebhookGuardResult::reject(400, 'missing_client_ip');
        }

        foreach ($this->allowedCidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return WebhookGuardResult::pass();
            }
        }

        return WebhookGuardResult::reject(403, 'ip_not_allowed');
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $subnetLong = ip2long($subnet);
        $ipLong = ip2long($ip);
        if ($subnetLong === false || $ipLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
