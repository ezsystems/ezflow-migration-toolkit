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
    const DEFAULT_LAYOUT = 'default';
    const DEFAULT_ZONE_ID = 'default_id';
    const DEFAULT_ZONE_NAME = 'default';

    private $layout;

    private $zones = [];

    /**
     * @var string
     */
    private $xml;

    private $blockMapper;

    private $name = 'Legacy';

    /**
     * @var \EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\XmlConverter
     */
    private $xmlConverter;

    /**
     * @var \EzSystems\LandingPageFieldTypeBundle\Registry\BlockTypeRegistry
     */
    private $blockTypeRegistry;

    public function __construct(
        $ezpage,
        $xmlConverter,
        $blockTypeRegistry,
        $blockMapper
    ) {
        $this->xml = $ezpage['data_text'];
        $this->blockMapper = $blockMapper;
        $this->xmlConverter = $xmlConverter;
        $this->blockTypeRegistry = $blockTypeRegistry;

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
            // If @id key exists, then it means that there is only one zone,
            // and the array should be packed into the new array to make it sequential
            if (array_key_exists('@id', $page->zone)) {
                $page->zone = [$page->zone];
            }
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
        // Returns XML of empty ezlandingpage to keep database consistent
        // since XML of empty ezpage differs from XML of empty ezlandingpage
        if (!count($this->getZones())) {
            return $this->xmlConverter->toXml(
                new LandingPage(
                    $this->getName(),
                    self::DEFAULT_LAYOUT,
                    [new LandingZone(self::DEFAULT_ZONE_ID, self::DEFAULT_ZONE_NAME)]
                )
            );
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

                    $this->blockTypeRegistry->addBlockType('legacy_'.strtolower($block->getType()), $blockDefinition);

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

        return $this->xmlConverter->toXml($landingPage);
    }
}
