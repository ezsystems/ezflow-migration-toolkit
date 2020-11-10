<?php

namespace EzSystems\EzFlowMigrationToolkit\HelperObject;

use EzSystems\EzFlowMigrationToolkit\Legacy\Model;
use EzSystems\EzFlowMigrationToolkit\Legacy\Wrapper;
use EzSystems\EzFlowMigrationToolkit\UUID;

class Block
{
    private $id;
    
    private $name;
    
    private $type;
    
    private $view;
    
    private $attributes;
    
    private $items;
    
    private $definition;
    
    private $overflow;
    
    private $ttl;

    public function __construct($block, $blockMapper)
    {
        $model = new Model(Wrapper::$handler);
        
        $legacyId = explode('_', $block['@id'])[1];
        

        $this->id = $this->generateId($block['@id']);
        $this->name = $block['name'];
        $this->type  = $block['type'];
        $this->view = isset($block['view']) && !empty($block['view']) ? $block['view'] : 'default';
        $this->overflow = isset($block['overflow_id']) && !empty($block['overflow_id']) ? $this->generateId($block['overflow_id']) : null;
        $this->ttl = isset($block['ttl']) && !empty($block['ttl']) ? $block['ttl'] : '0';
        $this->attributes = isset($block['custom_attributes']) ? $block['custom_attributes'] : [];
        
        if (isset($block['overflow_id']) && !empty($block['overflow_id'])) {
            $this->attributes['overflow'] = $this->generateId($block['overflow_id']);
        }
       
        $this->items = $model->getBlockItems($legacyId);
        $this->definition = $blockMapper->getLegacyBlockConfiguration()[$this->type];
    }

    public function getDefinition()
    {
        return $this->definition;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getView()
    {
        return $this->view;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getItems()
    {
        return $this->items;
    }

    public function getOverflow()
    {
        return $this->overflow;
    }
    
    public function getTtl()
    {
        return $this->ttl;
    }

    public function hasOverflow()
    {
        return (bool)$this->overflow;
    }
    
    private function generateId($legacyId)
    {
        return 'b-' . UUID::v3(Wrapper::getUuidSeed(), $legacyId);
    }
}

