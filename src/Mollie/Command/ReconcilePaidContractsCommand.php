<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Command;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractStateQueryInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * MOL-17: contracts left at `committed` although Mollie reports the payment `paid` - the lost update
 * between the shopper's return leg and the paid webhook, before optimistic versioning existed. The
 * admin Refund form needs `fulfilled`, so these orders could not be refunded. This runs the very same
 * fulfilment the webhook runs ({@see WebhookContractFulfillmentHandlerInterface::handlePaymentPaid()}),
 * once per stuck contract whose Mollie payment is paid. Idempotent: a fulfilled contract is a no-op.
 */
#[AsCommand(
    name: 'mollie:reconcile-paid',
    description: 'Fulfil Mollie contracts stuck at "committed" whose payment Mollie reports as paid',
)]
final class ReconcilePaidContractsCommand extends Command
{
    public function __construct(
        private readonly ContractStateQueryInterface $contracts,
        private readonly MolliePaymentsAdapterInterface $payments,
        private readonly MollieStatusMapper $statusMapper,
        private readonly WebhookContractFulfillmentHandlerInterface $fulfillment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be fulfilled, change nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of contracts to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $stuck = $this->contracts->findByStateAndProvider('committed', MollieDefinitions::PROVIDER_NAME, $limit);
        $io->writeln(sprintf(
            '%d committed Mollie contract(s) to inspect%s',
            count($stuck),
            $dryRun ? ' (dry run)' : '',
        ));

        $fulfilled = 0;
        foreach ($stuck as $contract) {
            $fulfilled += $this->reconcile($io, $contract, $dryRun) ? 1 : 0;
        }

        $io->success(sprintf('%d contract(s) %s', $fulfilled, $dryRun ? 'would be fulfilled' : 'fulfilled'));

        return Command::SUCCESS;
    }

    private function reconcile(SymfonyStyle $io, PaymentContractInterface $contract, bool $dryRun): bool
    {
        $paymentId = (string) $contract->getProviderOrderId();
        $label = sprintf('contract %s / order %s / %s', $contract->getId(), $contract->getOrderId() ?? '-', $paymentId);
        if ($paymentId === '') {
            $io->writeln("  skip  $label: no Mollie payment linked");

            return false;
        }

        try {
            $outcome = $this->statusMapper->map($this->payments->getPayment($paymentId)->status);
        } catch (Throwable $e) {
            $io->writeln("  skip  $label: Mollie unreachable (" . $e->getMessage() . ')');

            return false;
        }

        if ($outcome !== MollieOutcome::PAID) {
            $io->writeln("  keep  $label: Mollie says " . $outcome->name . ', not paid');

            return false;
        }

        if ($dryRun) {
            $io->writeln("  would fulfil  $label");

            return true;
        }

        $result = $this->fulfillment->handlePaymentPaid($paymentId);
        $io->writeln(sprintf('  %s  %s', str_pad($result->name, 8), $label));

        return $result->name === 'Acted';
    }
}
