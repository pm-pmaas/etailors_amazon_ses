<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/** Durable transport state; accessed atomically through DBAL, not ORM's unit of work. */
class BulkDelivery
{
    private $id;
    private $content_id;
    private $email_id;
    private $tracking_hash;
    private $scope;
    private $entry;
    private $state;
    private $event;
    private $reason;
    private $message_id;
    private $attempts;
    private $next_attempt;
    private $updated_at;
    private $created_at;
    private $claim;
    private $synced;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('ses_bulk_deliveries');
        $builder->createField('id', 'string')->length(64)->isPrimaryKey()->build();
        $builder->createField('content_id', 'string')->length(64)->build();
        $builder->createField('email_id', 'integer')->build();
        $builder->createField('tracking_hash', 'string')->length(191)->build();
        $builder->createField('scope', 'string')->length(64)->build();
        $builder->createField('entry', 'text')->build();
        $builder->createField('state', 'string')->length(16)->build();
        $builder->createField('event', 'string')->length(24)->build();
        $builder->createField('reason', 'string')->length(128)->build();
        $builder->createField('message_id', 'string')->length(191)->build();
        $builder->createField('attempts', 'integer')->build();
        $builder->createField('next_attempt', 'integer')->build();
        $builder->createField('updated_at', 'integer')->build();
        $builder->createField('created_at', 'integer')->build();
        $builder->createField('claim', 'string')->length(32)->build();
        $builder->createField('synced', 'boolean')->build();
        $builder->addIndex(['email_id'], 'ses_bulkdelivery_email');
        $builder->addIndex(['scope', 'state', 'next_attempt'], 'ses_bulk_retry');
        $builder->addIndex(['content_id'], 'ses_bulk_content');
        $builder->addIndex(['message_id'], 'ses_bulk_message');
        $builder->addIndex(['synced', 'state'], 'ses_bulk_sync');
        $builder->addIndex(['state', 'updated_at'], 'ses_bulk_state_updated');
    }
}
