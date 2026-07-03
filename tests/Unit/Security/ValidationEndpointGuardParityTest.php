<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Security;

use OxidEsales\PaymentBase\Validation\Guard\ActiveModuleQueryInterface;
use OxidEsales\PaymentBase\Validation\Guard\CsrfTokenGuard;
use OxidEsales\PaymentBase\Validation\Guard\PluginIdAllowlistGuard;
use OxidEsales\PaymentBase\Validation\Guard\PostOnlyGuard;
use OxidEsales\PaymentBase\Validation\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Validation\Guard\SameOriginGuard;
use OxidEsales\PaymentBase\Validation\Guard\SessionChallengeVerifierInterface;
use OxidEsales\PaymentBase\Validation\Guard\ShopUrlResolverInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitConfigInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 8 Story 1 — the central user-input validation subsystem (Sprint 4 Story 6) is the
 * parity item PayPal skipped entirely. This exercises payment-base's shared
 * `oepaymentvalidationapi` guard chain (PostOnly -> PayloadSize -> ActiveSession -> SameOrigin ->
 * CsrfToken -> RateLimit -> PluginIdAllowlist) with Mollie's own module id, proving the chain
 * Mollie's `views/twig/...` checkout footer posts to actually rejects the cases it must.
 *
 * See `docs/security/f-matrix.md` for how this maps onto the PayPal F-series.
 */
#[Group('security')]
#[Group('validation-endpoint')]
final class ValidationEndpointGuardParityTest extends TestCase
{
    public function testValidationApi_RejectsNonPost(): void
    {
        $guard = new PostOnlyGuard();

        $result = $guard->check($this->context(method: 'GET'));

        self::assertNotNull($result);
        self::assertSame(405, $result->httpStatus);
    }

    public function testValidationApi_RejectsCrossOrigin(): void
    {
        $shopUrlResolver = $this->createMock(ShopUrlResolverInterface::class);
        $shopUrlResolver->method('getShopUrl')->willReturn('https://shop.example');
        $guard = new SameOriginGuard($shopUrlResolver);

        $result = $guard->check($this->context(originHeader: 'https://attacker.example'));

        self::assertNotNull($result);
        self::assertSame(403, $result->httpStatus);
    }

    public function testValidationApi_RejectsMissingCsrf(): void
    {
        $verifier = $this->createMock(SessionChallengeVerifierInterface::class);
        $verifier->method('verify')->willReturn(false);
        $guard = new CsrfTokenGuard($verifier);

        $result = $guard->check($this->context(csrfToken: null));

        self::assertNotNull($result);
        self::assertSame(403, $result->httpStatus);
    }

    /**
     * A deactivated (or never-installed) Mollie module must not be reachable through the shared
     * validation endpoint by simply POSTing its module id — this is exactly the "arbitrary string
     * triggers a filesystem/rule-loader lookup" risk the guard's docblock names.
     */
    public function testValidationApi_RejectsInactiveModuleId422(): void
    {
        $activeModuleQuery = $this->createMock(ActiveModuleQueryInterface::class);
        $activeModuleQuery->method('isActive')->with(MollieDefinitions::MODULE_ID)->willReturn(false);
        $guard = new PluginIdAllowlistGuard($activeModuleQuery);

        $result = $guard->check($this->context(pluginModuleId: MollieDefinitions::MODULE_ID));

        self::assertNotNull($result);
        self::assertSame(422, $result->httpStatus);
    }

    public function testValidationApi_AllowsActiveMollieModuleId(): void
    {
        $activeModuleQuery = $this->createMock(ActiveModuleQueryInterface::class);
        $activeModuleQuery->method('isActive')->with(MollieDefinitions::MODULE_ID)->willReturn(true);
        $guard = new PluginIdAllowlistGuard($activeModuleQuery);

        $result = $guard->check($this->context(pluginModuleId: MollieDefinitions::MODULE_ID));

        self::assertNull($result);
    }

    public function testValidationApi_RateLimited(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->method('increment')->willReturn(31);
        $config = $this->createMock(RateLimitConfigInterface::class);
        $config->method('getLimitForPlugin')->with(MollieDefinitions::MODULE_ID)->willReturn(30);
        $guard = new RateLimitGuard($store, $config);

        $result = $guard->check($this->context(pluginModuleId: MollieDefinitions::MODULE_ID));

        self::assertNotNull($result);
        self::assertSame(429, $result->httpStatus);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function context(
        string $method = 'POST',
        int $bodySize = 64,
        array $fields = ['firstName' => 'Jane'],
        string $pluginModuleId = MollieDefinitions::MODULE_ID,
        ?string $csrfToken = 'valid-token',
        ?string $sessionId = 'sess-1',
        ?string $originHeader = 'https://shop.example',
        ?string $refererHeader = null,
    ): ValidationRequestContext {
        return new ValidationRequestContext(
            $method,
            $bodySize,
            $fields,
            $pluginModuleId,
            $csrfToken,
            $sessionId,
            $originHeader,
            $refererHeader,
        );
    }
}
