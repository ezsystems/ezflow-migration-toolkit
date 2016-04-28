<?php

namespace EzSystems\EzFlowMigrationToolkit\HelperObject;

use EzSystems\EzFlowMigrationToolkit\Report;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\BlockValue;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Zone as LandingZone;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Page as LandingPage;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\PropertyAccess\Exception\InvalidArgumentException;

class Page
{
    private $layout;

    private $zones = [];

    private $xml;

    private $blockMapper;

    private $container;

    private $name = 'Legacy';

    public function __construct(
        $ezpage,
        $container,
        $blockMapper)
    {
        $this->xml = $ezpage['data_text'];
        $this->blockMapper = $blockMapper;
        $this->container = $container;

        $serializer = new Serializer([new ObjectNormalizer()], [new XmlEncoder()]);

        try {
            $page = new PageValue();
            
            if ($this->xml) {
                $page = $serializer->deserialize($this->xml, 'EzSystems\EzFlowMigrationToolkit\HelperObject\PageValue', 'xml');
            }
        }
        catch(InvalidArgumentException $e) {
            // Not valid or empty page
            $page = new PageValue();
        }

        $this->layout = $page->zone_layout;

        if (is_array($page->zone)) {
            foreach ($page->zone as $legacyZone) {
                $zone = new Zone($legacyZone, $blockMapper);

                $this->zones[] = $zone;
            }
        }
    }

    public function getBlockMapper()
    {
        return $this->blockMapper;
    }

    public function getLayout()
    {
        return $this->layout;
    }

    public function getZones()
    {
        return $this->zones;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getLandingPage(&$configuration)
    {
        if (!count($this->getZones())) {
            return false;
        }

        $configuration['layouts'][$this->getLayout()] = [
            'identifier' => $this->getLayout(),
            'name' => $this->getLayout(),
            'description' => $this->getLayout(),
            'thumbnail' => '/bundles/migrationbundle/images/layouts/' . $this->getLayout() . '.png',
            'template' => 'MigrationBundle:layouts:' . $this->getLayout() . '.html.twig',
            'zones' => [],
        ];

        $zones = [];
        
        Report::write(count($this->getZones()) . " in " . $this->getLayout() . " layout found");

        foreach ($this->getZones() as $zone) {
            $configuration['layouts'][$this->getLayout()]['zones'][$zone->getId()] = [
                'name' => $zone->getId()
            ];

            $blocks = [];

            foreach ($zone->getBlocks() as $block) {
                Report::write("Trying to map {$block->getType()} block to one of used in eZ Studio");
                
                $studioBlock = $this->blockMapper->map($block);
                $legacyDefinition = $this->blockMapper->getLegacyBlockConfiguration()[$block->getType()];

                if (!$studioBlock) {
                    Report::write("Mapping not found, preparing placeholder block in: src/MigrationBundle/LandingPage/Block/Legacy{$block->getType()}Block.php");
                    
                    $blockDefinition = $this->blockMapper->generateBlockClass($block->getType());

                    $blockRegistry = $this->container->get('ezpublish.landing_page.registry.block_type');
                    $blockRegistry->addBlockType('legacy_'.strtolower($block->getType()), $blockDefinition);

                    $studioBlock = new BlockValue(
                        $block->getId(),
                        'legacy_' . strtolower($block->getType()),
                        $block->getView(),
                        $block->getAttributes()
                    );

                    Report::write("Generate service configuration for new block type");
                    $configuration['services']['migration.landing_page.block.legacy_' . strtolower($block->getType())] = [
                        'class' => 'MigrationBundle\LandingPage\Block\Legacy' . $block->getType() . 'Block',
                        'arguments' => ['@ezpublish.api.service.content'],
                        'tags' => [[
                          'name' => 'landing_page_field_type.block_type',
                          'alias' => $studioBlock->getType(),
                        ],],
                    ];
                }
                else {
                    Report::write("Mapping found, migrating block as " . $studioBlock->getType());
                }

                $studioBlock->setName($block->getName());

                if (!isset($configuration['blocks'][$studioBlock->getType()])) {
                    $configuration['blocks'][$studioBlock->getType()] = [
                        'views' => [],
                    ];
                }
                
                if (!isset($configuration['blocks'][$studioBlock->getType()]['views'][$studioBlock->getView()])) {
                    $configuration['blocks'][$studioBlock->getType()]['views'][$studioBlock->getView()] = [
                        'template' => 'MigrationBundle:blocks:' . $studioBlock->getType(). '_' . $studioBlock->getView() . '.html.twig',
                        'name' => $studioBlock->getType() . ' ' . $studioBlock->getView() . ' view',
                    ];

                    if ($legacyDefinition['ManualAddingOfItems'] == 'enabled') {
                        $this->blockMapper->generateBlockView('schedule', $block->getType(), $block->getView());
                    }
                    else {
                        $this->blockMapper->generateBlockView('block', $block->getType(), $block->getView());
                    }
                }

                $blocks[] = $studioBlock;
            }

            $zones[] = new LandingZone($zone->getId(), $zone->getId(), $blocks);
        }

        $landingPage = new LandingPage(
            $this->getName(),
            $this->getLayout(),
            $zones
        );

        return $this->container->get('ezpublish.fieldtype.ezlandingpage.xml_converter')->toXml($landingPage);
    }
}
