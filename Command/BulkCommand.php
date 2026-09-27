<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Transport\Dsn;

#[AsCommand(name: 'mautic:ses:bulk', description: 'Install, inspect and recover the SES bulk delivery outbox.')]
final class BulkCommand extends Command
{
    public function __construct(private DeliveryStore $store, private AmazonSesTransportFactory $factory, private CoreParametersHelper $parameters)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'install, status, retry, sync-stats or prune')
            ->addOption('email-id', null, InputOption::VALUE_REQUIRED, 'Filter status by Mautic email ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum recipients per recovery/reconciliation run, rows per prune batch', '1000')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Prune rows finished more than this many days ago', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        if (!in_array($action, ['install', 'status', 'retry', 'sync-stats', 'prune'], true)) {
            $io->error('Action must be install, status, retry, sync-stats or prune.');

            return Command::INVALID;
        }
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $emailId = $input->getOption('email-id');
        $olderThan = filter_var($input->getOption('older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $limit || false === $olderThan || (null !== $emailId && false === filter_var($emailId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            $io->error('Use a positive email ID, a limit between 1 and 10000 and a positive number of days.');

            return Command::INVALID;
        }
        if ('install' === $action) {
            $this->store->install();
            $io->success('SES bulk tables are ready.');

            return Command::SUCCESS;
        }
        $this->store->assertInstalled();
        if ('status' === $action) {
            $summary = $this->store->summary(null === $emailId ? null : (int) $emailId);
            if ($input->getOption('json')) {
                $output->writeln(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $io->table(['Mode', 'Submission state', 'SES event', 'Reason', 'Recipients', 'Attempts'], array_map('array_values', $summary['recipients']));
                $io->table(['Mode', 'Requests attempted', 'Estimated request bytes'], array_map('array_values', $summary['requests']));
                $io->note('Accepted is not delivered. Unknown outcomes are never retried automatically. Counts cover identified recipients processed with bulk=auto.');
            }

            return Command::SUCCESS;
        }
        if ('prune' === $action) {
            $deleted = $this->store->prune($olderThan, $limit);
            $io->success(sprintf('Deleted %d deliveries and %d contents finished more than %d days ago.', $deleted['deliveries'], $deleted['contents'], $olderThan));

            return Command::SUCCESS;
        }
        if ('retry' === $action) {
            $dsn = Dsn::fromString(str_replace('%%', '%', (string) $this->parameters->get('mailer_dsn')));
            $transport = $this->factory->create($dsn);
            if (!$transport instanceof AmazonSesTransport) {
                throw new \LogicException('The configured transport is not the e-tailors SES transport.');
            }
            $count = $transport->retryBulk($limit);
            $io->writeln(sprintf('Processed up to %d due recipients. Inspect status for their outcomes.', $count));
        }
        $sync = $this->store->syncFailures($limit);
        $io->success(sprintf('Reconciled %d failed recipient statistics. No contacts were added to DNC.', $sync['reconciled']));
        if ($sync['without_statistic']) {
            $io->note(sprintf('%d failed recipients have had no Mautic email statistic for a day (for example, the email was deleted) and are no longer reconciled.', $sync['without_statistic']));
        }

        return Command::SUCCESS;
    }
}
