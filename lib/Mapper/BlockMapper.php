<?php

namespace EzSystems\EzFlowMigrationToolkit\Mapper;

use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Block\Item;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Block\ScheduleBlock\History;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Block\ScheduleBlock\Queue;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\Block\ScheduleBlock\ValidItems;
use EzSystems\LandingPageFieldTypeBundle\FieldType\LandingPage\Model\BlockValue;
use Symfony\Component\Filesystem\Filesystem;

class BlockMapper
{
    private $legacyBlockConfiguration;
    private $twig;

    public function __construct($legacyBlockConfiguration, $twig)
    {
        $this->legacyBlockConfiguration = $legacyBlockConfiguration;
        $this->twig = $twig;
    }

    public function getLegacyBlockConfiguration()
    {
        return $this->legacyBlockConfiguration;
    }

    public function map($block)
    {
        $definition = $this->getLegacyBlockConfiguration()[$block->getType()];

        if ($definition['ManualAddingOfItems'] === 'enabled' && isset($definition['NumberOfValidItems'])) {
            $items = [];

            foreach ($block->getItems() as $item) {
                // Items with ts_visible = 0 needs to be skipped. These had been in legacy block queue.
                if (!empty($item['ts_visible'])) {
                    $items[] = new Item($item['object_id'], \DateTime::createFromFormat('U', $item['ts_visible'])->getTimestamp(), null, null);
                }
            }
            
            $attributes = [
                'queue' => new Queue([]),
                'validItems' => new ValidItems($items),
                'history' => new History([]),
                'slots' => $definition['NumberOfValidItems'],
            ];
            
            if ($block->hasOverflow()) {
                $attributes['overflow'] = $block->getOverflow();
            }

            return new BlockValue(
                $block->getId(),
                'schedule',
                $block->getView(),
                $attributes
            );
        }

        return false;
    }

    public function generateBlockClass($type)
    {
        $path = 'src/MigrationBundle/LandingPage/Block';
        $filePath = "{$path}/Legacy{$type}Block.php";

        $filesystem = new Filesystem();
        $filesystem->mkdir($path);

        $definition = $this->legacyBlockConfiguration[$type];

        if (!$filesystem->exists($filePath)) {
            $class = $this->twig->render('EzFlowMigrationToolkit:php:Block.php.twig', [
                'type' => $type,
                'attributes' => isset($definition['CustomAttributes']) ? $definition['CustomAttributes'] : [],
            ]);

            $filesystem->dumpFile($filePath, $class);
        }

        $className = '\MigrationBundle\LandingPage\Block\Legacy' . $type . 'Block';

        return new $className;
    }

    public function generateBlockView($template, $type, $view)
    {
        $path = 'src/MigrationBundle/Resources/views/blocks';

        if ($template == 'schedule') {
            $definition = $this->getLegacyBlockConfiguration()[$type];

            $count = isset($definition['NumberOfValidItems']) ? $definition['NumberOfValidItems'] : 4;

            $type = $template;
            $filePath = "{$path}/{$type}_{$view}.html.twig";
        }
        else {
            $count = 0;
            $typeName = 'legacy_'.strtolower($type);
            $filePath = "{$path}/{$typeName}_{$view}.html.twig";
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($path);

        if (!$filesystem->exists($filePath)) {
            $twig = $this->twig->render('EzFlowMigrationToolkit:twig:' . $template . '_default.html.twig', [
                'type' => $type,
                'view' => $view,
                'count' => $count,
            ]);

            $filesystem->dumpFile($filePath, $twig);
        }
    }
}