
## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Failing tests first: language id appears in success/cancel URLs and reflects the active session language |
| **DevOps-first** | Pre-commit must pass (PHPCS, PHPStan max, PHPMD, PHPUnit Unit + Integration) |
| **SOLID / SRP** | Language resolution is a single responsibility — extracted into its own service, not inlined in handlers |
| **SOLID / DIP** | `StripePaymentHandler` depends on `LanguageResolverInterface`, not on `Registry::getLang()` directly |
| **LSP** | OXID implementation honors interface contract — always returns a non-negative `int`, never throws on missing session |
| **DRY** | Single source of truth for "active language id". Both Path A (legacy controller) and Path B (OPC handler) use the same service |
| **No overengineering** | We do **not** add a frontend lang forward (data attribute → JSON body → request param) yet. We add it only if QA shows `getBaseLanguage()` returning 0 in legitimate AJAX calls |
