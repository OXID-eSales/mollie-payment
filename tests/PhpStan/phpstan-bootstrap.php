<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * PHPStan bootstrap for the Mollie module.
 *
 * OXID generates virtual `{Core}_parent` classes at runtime — they don't exist on disk.
 * We stub them for PHPStan so level-max analysis of class extensions passes without
 * per-file suppressions. Keep this list minimal and justified.
 */

declare(strict_types=1);

if (!class_exists('OxidEsales\\Payments\\Mollie\\Core\\ViewConfig_parent', false)) {
    class_alias(
        \OxidEsales\Eshop\Core\ViewConfig::class,
        'OxidEsales\\Payments\\Mollie\\Core\\ViewConfig_parent',
    );
}
if (!class_exists('OxidEsales\\Payments\\Mollie\\Controller\\PaymentController_parent', false)) {
    class_alias(
        \OxidEsales\Eshop\Application\Controller\PaymentController::class,
        'OxidEsales\\Payments\\Mollie\\Controller\\PaymentController_parent',
    );
}
if (!class_exists('OxidEsales\\Payments\\Mollie\\Controller\\MollieOrderController_parent', false)) {
    class_alias(
        \OxidEsales\Eshop\Application\Controller\OrderController::class,
        'OxidEsales\\Payments\\Mollie\\Controller\\MollieOrderController_parent',
    );
}

// GRAPH-QL / MS4 (2026-10-06, copied from Stripe PS4) — the GraphQL controller implements graphql-base /
// GraphQLite contracts that ship with the shop, not with this module's vendor.
// Minimal stubs so the controller is loadable here.
if (!class_exists(\TheCodingMachine\GraphQLite\Types\ID::class, false)) {
    eval(
        'namespace TheCodingMachine\\GraphQLite\\Types; '
        . 'class ID { public function __construct(private mixed $value) {} public function val(): mixed { return $this->value; } public function __toString(): string { return (string) $this->value; } }'
    );
}
if (!class_exists(\TheCodingMachine\GraphQLite\Annotations\Mutation::class, false)) {
    eval(
        'namespace TheCodingMachine\\GraphQLite\\Annotations; '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Mutation { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Query { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Logged { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Right { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_CLASS)] class Type { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Field { public function __construct(mixed ...$args) {} }'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Base\DataType\User::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\DataType; '
        . 'class User { '
        . '  public function __construct(private string $userId = "", private bool $anonymous = false) {} '
        . '  public function id(): \\TheCodingMachine\\GraphQLite\\Types\\ID { return new \\TheCodingMachine\\GraphQLite\\Types\\ID($this->userId); } '
        . '  public function isAnonymous(): bool { return $this->anonymous; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Base\Service\Authentication::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\Service; '
        . 'class Authentication { '
        . '  public function isLogged(): bool { return false; } '
        . '  public function getUser(): \\OxidEsales\\GraphQL\\Base\\DataType\\User { return new \\OxidEsales\\GraphQL\\Base\\DataType\\User(); } '
        . '}'
    );
}
if (!interface_exists(\OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\Framework; '
        . 'interface NamespaceMapperInterface { public function getControllerNamespaceMapping(): array; public function getTypeNamespaceMapping(): array; }'
    );
}
if (!class_exists(\GraphQL\Error\Error::class, false)) {
    eval('namespace GraphQL\\Error; class Error extends \\Exception { public function isClientSafe(): bool { return true; } }');
}
if (!class_exists(\OxidEsales\GraphQL\Base\Exception\Error::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\Exception; '
        . 'abstract class Error extends \\GraphQL\\Error\\Error { '
        . '  public function __construct(string $message, protected $code = 0, ?\\Throwable $previous = null, protected string $category = "Exception", protected array $extensions = []) { parent::__construct($message, 0, $previous); } '
        . '  public function getCategory(): string { return $this->category; } '
        . '  public function getExtensions(): array { return $this->extensions; } '
        . '} '
        . 'class ErrorCategories { public const PERMISSIONERRORS = "permissionerror"; public const TOKENERRORS = "tokenerror"; public const CONFIGURATIONERROR = "configurationerror"; public const REQUESTERROR = "requesterror"; }'
    );
}
