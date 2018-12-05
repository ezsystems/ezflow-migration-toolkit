<?php

namespace EzSystems\EzFlowMigrationToolkit\Legacy;

use eZ\Publish\Core\Persistence\Database\DatabaseHandler;
use PDO;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\PropertyAccess\Exception\InvalidArgumentException;

class Model
{
    private $handler;

    public function __construct(DatabaseHandler $handler)
    {
        $this->handler = $handler;
    }

    public function getDatabaseHandler()
    {
        return $this->handler;
    }

    public function getPages()
    {
        $select = $this->handler->createSelectQuery();

        $select
            ->select([
                'id',
                'contentobject_id',
                'data_text',
                'version',
            ])
            ->from('ezcontentobject_attribute')
            ->where(
                $select->expr->eq(
                    $this->handler->quoteColumn('data_type_string', 'ezcontentobject_attribute'),
                    $select->bindValue('ezpage', null, PDO::PARAM_STR)
                )
            )
            ->where('data_text IS NOT NULL');

        $query = $select->prepare();
        $query->execute();

        return $query->fetchAll();
    }

    public function updateEzPage($id, $xml)
    {
        $update = $this->handler->createUpdateQuery();

        $update
            ->update('ezcontentobject_attribute')
            ->set(
                $this->handler->quoteColumn('data_text', 'ezcontentobject_attribute'),
                $update->bindValue($xml, null, PDO::PARAM_STR)
            )
            ->set(
                $this->handler->quoteColumn('data_type_string', 'ezcontentobject_attribute'),
                $update->bindValue('ezlandingpage', null, PDO::PARAM_STR)
            )
            ->where(
                $update->expr->eq(
                    $this->handler->quoteColumn('id', 'ezcontentobject_attribute'),
                    $update->bindValue($id, null, PDO::PARAM_INT)
                )

            );

        $query = $update->prepare();
        $query->execute();
    }

    public function replacePageFieldType()
    {
        $update = $this->handler->createUpdateQuery();

        $update
            ->update('ezcontentclass_attribute')
            ->set(
                $this->handler->quoteColumn('data_type_string', 'ezcontentclass_attribute'),
                $update->bindValue('ezlandingpage', null, PDO::PARAM_STR)
            )
            ->where(
                $update->expr->eq(
                    $this->handler->quoteColumn('data_type_string', 'ezcontentclass_attribute'),
                    $update->bindValue('ezpage', null, PDO::PARAM_STR)
                )
            )
            ->where(
                $update->expr->eq(
                    $this->handler->quoteColumn('identifier', 'ezcontentclass_attribute'),
                    $update->bindValue('page', null, PDO::PARAM_STR)
                )
            );

        $query = $update->prepare();
        $query->execute();
    }

    public function getBlockItems($blockId)
    {
        $select = $this->handler->createSelectQuery();

        $select
            ->select('*')
            ->from('ezm_pool')
            ->where(
                $select->expr->eq(
                    $this->handler->quoteColumn('block_id', 'ezm_pool'),
                    $select->bindValue($blockId, null, PDO::PARAM_STR)
                )
            )
            ->orderBy($this->handler->quoteColumn('priority', 'ezm_pool'));

        $query = $select->prepare();
        $query->execute();

        return $query->fetchAll();
    }
}