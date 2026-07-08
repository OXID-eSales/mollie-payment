<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Command;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Service\OxpaidReconciliationServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entrypoint for {@see OxpaidReconciliationServiceInterface}: self-heals a single order's
 * OXPAID when the Mollie API reports the payment as paid but a missed/delayed webhook left OXPAID
 * on the zero-date. This is the caller the service's own docblock said was deferred "until an
 * admin or console entrypoint actually needs it".
 *
 * Usage:
 *   bin/oe-console mollie:reconcile-oxpaid <orderId>
 */
#[AsCommand(
    name: 'mollie:reconcile-oxpaid',
    description: 'Reconcile an order\'s OXPAID timestamp with its Mollie payment status',
)]
final class ReconcileOxpaidCommand extends Command
{
    public function __construct(
        private readonly OxpaidReconciliationServiceInterface $reconciliationService,
        private readonly ContractRepositoryInterface $contractRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('orderId', InputArgument::REQUIRED, 'OXID id of the order to reconcile');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderIdArg = $input->getArgument('orderId');
        $orderId = is_scalar($orderIdArg) ? (string) $orderIdArg : '';

        $providerOrderId = $this->resolveProviderOrderId($orderId);
        if ($providerOrderId === null) {
            $io->error(sprintf('No Mollie payment is linked to order "%s".', $orderId));
            return Command::FAILURE;
        }

        $healed = $this->reconciliationService->reconcile($orderId, $providerOrderId);

        return $this->report($io, $orderId, $healed);
    }

    private function resolveProviderOrderId(string $orderId): ?string
    {
        $contract = $this->contractRepository->findByOrderId($orderId);
        if ($contract === null) {
            return null;
        }

        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            return null;
        }

        return $providerOrderId;
    }

    private function report(SymfonyStyle $io, string $orderId, bool $healed): int
    {
        if ($healed) {
            $io->success(sprintf('OXPAID healed for order "%s".', $orderId));
            return Command::SUCCESS;
        }

        $io->note(sprintf('Nothing to reconcile for order "%s" (already consistent).', $orderId));
        return Command::SUCCESS;
    }
}
