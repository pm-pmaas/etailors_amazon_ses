<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;

/**
 * Mautic creates plugin tables from entity metadata only on first install; existing installs get the SES bulk outbox here.
 * Outbox tables from an earlier build of this unreleased version get the indexes added since.
 */
final class Version_1_0_42 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        $deliveries = $this->concatPrefix('ses_bulk_deliveries');

        return !$schema->hasTable($this->concatPrefix('ses_bulk_contents'))
            || !$schema->hasTable($deliveries)
            || !$schema->getTable($deliveries)->hasIndex($this->concatPrefix('ses_bulk_state_updated'));
    }

    protected function up(): void
    {
        foreach (DeliveryStore::createSchemaSql($this->entityManager) as $sql) {
            $this->addSql($sql);
        }
    }
}
