<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\GraphQL;

// CI runs this suite with the shop bootstrap, which does not autoload the module's Tests\ namespace.
require_once __DIR__ . '/FixedClassFinderTrait.php';

use GraphQL\GraphQL;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\GraphQL\Base\DataType\User;
use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\Payments\Mollie\GraphQL\Controller\MollieCheckout;
use OxidEsales\Payments\Mollie\GraphQL\Input\MollieGuestInputFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * 140b B1 through the real GraphQLite schema: an anonymous token (not "logged") that carries the
 * PAYMENT_CHECKOUT right reaches the mutations; one without it is refused before the controller runs.
 * Authentication and authorization are the two services GraphQLite asks, stubbed to what graphql-base answers
 * for an `oxidanonymous` token with and without the right.
 */
#[Group('integration')]
final class CheckoutAccessWithAnonymousTokenTest extends TestCase
{
    use FixedClassFinderTrait;

    private const START = 'mutation { mollieCheckoutStart(basketId: "b-1", confirmTermsAndConditions: true, returnUrl: "https://app.example.com/r") { contractId } }';
    private const GUEST_START = 'mutation { mollieCheckoutStart(basketId: "b-1", confirmTermsAndConditions: true, returnUrl: "https://app.example.com/r", '
        . 'guest: { email: "ada@example.com", invoiceAddress: { firstName: "Ada", lastName: "L", street: "Main St", streetNumber: "1", '
        . 'zipCode: "79098", city: "Freiburg", countryId: "c-1" } }) { contractId } }';

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SchemaFactory::class)) {
            self::markTestSkipped('graphql-base / GraphQLite is not installed');
        }
    }

    public function testCheckoutStart_AnonymousTokenWithRight_IsAccepted(): void
    {
        $checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $checkout->expects($this->once())->method('start')
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'mollie', null, 'https://www.mollie.com/checkout/tr_1', null, 'redirect'));

        $result = $this->execute(self::START, true, $checkout);

        self::assertSame([], $result['errors'] ?? [], 'the anonymous token reaches the controller');
        self::assertSame('ctr-1', $result['data']['mollieCheckoutStart']['contractId']);
    }

    public function testCheckoutStart_AnonymousTokenWithoutRight_IsRefused(): void
    {
        $checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $checkout->expects($this->never())->method('start');

        $result = $this->execute(self::START, false, $checkout);

        self::assertNotEmpty($result['errors'] ?? [], 'a token without PAYMENT_CHECKOUT gets no checkout');
        self::assertNull($result['data']['mollieCheckoutStart'] ?? null);
    }

    public function testCheckoutStart_AnonymousGuestStartThroughTheSchema_ReachesTheServiceWithTheGuest(): void
    {
        $checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $checkout->expects($this->once())->method('start')
            ->with($this->callback(static fn($request): bool => $request->guest?->email === 'ada@example.com'
                && $request->guest->invoiceAddress->countryId === 'c-1'))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'mollie', null, 'https://www.mollie.com/checkout/tr_1', null, 'redirect'));

        $result = $this->execute(self::GUEST_START, true, $checkout);

        self::assertSame([], $result['errors'] ?? []);
    }

    public function testCheckoutStart_AccountExists_SurfacesTheStableErrorCodeInTheExtensions(): void
    {
        $checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $checkout->method('start')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::ACCOUNT_EXISTS, 'log in')
        );

        $result = $this->execute(self::GUEST_START, true, $checkout);

        self::assertSame('account_exists', $result['errors'][0]['extensions']['errorCode']);
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(string $query, bool $hasRight, HeadlessCheckoutServiceInterface $checkout): array
    {
        $userModel = $this->createMock(EshopUserModel::class);
        $userModel->method('getId')->willReturn('anonymous-user-id');
        $authentication = $this->createMock(Authentication::class);
        $authentication->method('getUser')->willReturn(new User($userModel, true));
        $controller = new MollieCheckout($checkout, $authentication);

        $factory = new SchemaFactory(new Psr16Cache(new ArrayAdapter()), $this->containerHolding($controller));
        $factory->setFinder($this->finderOver([
            MollieCheckout::class,
            MollieGuestInputFactory::class,
            CheckoutStartResult::class,
            CheckoutReturnResult::class,
            CheckoutCancelResult::class,
        ]));
        $factory->addControllerNamespace('OxidEsales\\Payments\\Mollie\\GraphQL\\Controller');
        $factory->addTypeNamespace('OxidEsales\\PaymentBase\\GraphQL\\DataType');
        $factory->addTypeNamespace('OxidEsales\\Payments\\Mollie\\GraphQL\\Input');
        $factory->setAuthenticationService($this->notLoggedIn());
        $factory->setAuthorizationService($this->grantingPaymentCheckout($hasRight));

        return GraphQL::executeQuery($factory->createSchema(), $query)->toArray();
    }

    private function notLoggedIn(): AuthenticationServiceInterface
    {
        return new class implements AuthenticationServiceInterface {
            public function isLogged(): bool
            {
                return false;
            }

            public function getUser(): object|null
            {
                return null;
            }
        };
    }

    private function grantingPaymentCheckout(bool $granted): AuthorizationServiceInterface
    {
        return new class ($granted) implements AuthorizationServiceInterface {
            public function __construct(private readonly bool $granted)
            {
            }

            public function isAllowed(string $right, mixed $subject = null): bool
            {
                return $this->granted && $right === 'PAYMENT_CHECKOUT';
            }
        };
    }

    private function containerHolding(MollieCheckout $controller): ContainerInterface
    {
        return new class ($controller) implements ContainerInterface {
            public function __construct(private readonly MollieCheckout $controller)
            {
            }

            public function get(string $id): MollieCheckout
            {
                return $this->controller;
            }

            public function has(string $id): bool
            {
                return $id === MollieCheckout::class;
            }
        };
    }
}
