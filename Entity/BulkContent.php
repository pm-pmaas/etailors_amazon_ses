<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/** Durable transport state; accessed atomically through DBAL, not ORM's unit of work. */
class BulkContent
{
    private $id;
    private $email_id;
    private $scope;
    private $operation;
    private $payload;
    private $requests;
    private $request_bytes;
    private $created_at;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('ses_bulk_contents');
        $builder->createField('id', 'string')->length(64)->isPrimaryKey()->build();
        $builder->createField('email_id', 'integer')->build();
        $builder->createField('scope', 'string')->length(64)->build();
        $builder->createField('operation', 'string')->length(8)->build();
        $builder->createField('payload', 'text')->build();
        $builder->createField('requests', 'integer')->build();
        $builder->createField('request_bytes', 'bigint')->build();
        $builder->createField('created_at', 'integer')->build();
        $builder->addIndex(['email_id'], 'ses_bulkcontent_email');
    }
}
