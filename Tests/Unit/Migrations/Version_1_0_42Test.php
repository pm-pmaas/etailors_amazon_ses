<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Migrations;

use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use MauticPlugin\AmazonSesBundle\Migrations\Version_1_0_42;
use MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk\DeliveryStoreTest;
use PHPUnit\Framework\TestCase;

class Version_1_0_42Test extends TestCase
{
    public function testCreatesTheOutboxTablesOnlyWhenMissing(): void
    {
        $em = DeliveryStoreTest::manager();
        $db = $em->getConnection();
        $migration = new Version_1_0_42($em, '');
        self::assertTrue($migration->shouldExecute());
        $migration->execute();
        self::assertTrue($db->createSchemaManager()->tablesExist(['ses_bulk_contents', 'ses_bulk_deliveries']));
        self::assertFalse($migration->shouldExecute());
        self::assertSame([], DeliveryStore::createSchemaSql($em));

        (new DeliveryStore($em))->enqueue(DeliveryStoreTest::delivery(), 'scope');
        (new Version_1_0_42($em, ''))->execute();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
    }

    public function testCreatesOnlyTheMissingTable(): void
    {
        $em = DeliveryStoreTest::manager();
        $db = $em->getConnection();
        (new DeliveryStore($em))->install();
        $db->executeStatement('DROP TABLE ses_bulk_deliveries');
        $migration = new Version_1_0_42($em, '');
        self::assertTrue($migration->shouldExecute());
        $migration->execute();
        self::assertTrue($db->createSchemaManager()->tablesExist(['ses_bulk_contents', 'ses_bulk_deliveries']));
        self::assertFalse($migration->shouldExecute());
    }

    public function testAddsTheIndexMissingFromAnExistingTable(): void
    {
        $em = DeliveryStoreTest::manager();
        DeliveryStoreTest::installWithout($em, 'ses_bulk_state_updated');
        $migration = new Version_1_0_42($em, '');
        self::assertTrue($migration->shouldExecute());
        $migration->execute();
        self::assertArrayHasKey('ses_bulk_state_updated', $em->getConnection()->createSchemaManager()->listTableIndexes('ses_bulk_deliveries'));
        self::assertFalse($migration->shouldExecute());
    }
}
