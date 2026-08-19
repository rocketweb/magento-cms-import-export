<?php
/*
 * RocketWeb
 *
 *  NOTICE OF LICENSE
 *
 *  This source file is subject to the Open Software License (OSL 3.0)
 *  that is bundled with this package in the file LICENSE.txt.
 *  It is also available through the world-wide-web at this URL:
 *  http://opensource.org/licenses/osl-3.0.php
 *
 *  @category  RocketWeb
 *  @copyright Copyright (c) 2020 RocketWeb (http://rocketweb.com)
 *  @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  @author    Rocket Web Inc.
 */
namespace RocketWeb\CmsImportExport\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DumpCmsData extends \Symfony\Component\Console\Command\Command
{
    private const INPUT_KEY_TYPE = 'type';
    private const INPUT_TYPE_VALUES = ['block', 'page', 'menu', 'all'];
    private const INPUT_KEY_IDENTIFIER = 'identifier';
    private const INPUT_KEY_REMOVE_ALL = 'removeAll';
    private const INPUT_KEY_HYVA_CMS = 'hyva-cms';
    private \RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService $dumpCmsDataService;

    public function __construct(
        \RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService $dumpCmsDataService,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->dumpCmsDataService = $dumpCmsDataService;
    }

    protected function configure()
    {
        $this->setName('cms:dump:data');
        $this->setDescription('Dumps cms pages/blocks to var/sync_cms_data for further import');
        $this->setDefinition([
            new InputOption(
                self::INPUT_KEY_TYPE,
                't',
                InputOption::VALUE_REQUIRED,
                'Which type are we dumping - block/page/menu/all (all covers block and page)'
            ),
            new InputOption(
                self::INPUT_KEY_IDENTIFIER,
                'i',
                InputOption::VALUE_OPTIONAL,
                'identifier to process (one or CSV list)'
            ),
            new InputOption(
                self::INPUT_KEY_REMOVE_ALL,
                'r',
                InputOption::VALUE_NONE,
                'Flag to remove all existing data'
            ),
            new InputOption(
                self::INPUT_KEY_HYVA_CMS,
                null,
                InputOption::VALUE_NONE,
                'Also dump Hyva CMS content and its per-entity Tailwind CSS'
            )
        ]);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getOption(self::INPUT_KEY_TYPE);
        $removeAll = (bool)$input->getOption(self::INPUT_KEY_REMOVE_ALL);
        $hyvaCms = (bool)$input->getOption(self::INPUT_KEY_HYVA_CMS);
        if ($type === null) {
            throw new \RuntimeException("Type ([-t|--type) is required");
        }
        if (!in_array($type, self::INPUT_TYPE_VALUES)) {
            throw new \RuntimeException('Unsupported type for export');
        }
        $types = [];
        if ($type == 'all' || $type == 'block') {
            $types[] = 'block';
        }
        if ($type == 'all' || $type == 'page') {
            $types[] = 'page';
        }
        // Menus stay out of 'all' on purpose, so that an existing dump command keeps writing the same files.
        if ($type == 'menu') {
            $types[] = 'menu';
        }

        $identifiers = $input->getOption(self::INPUT_KEY_IDENTIFIER);
        if ($identifiers !== null) {
            $identifiers = explode(',', $identifiers);
        }

        $this->dumpCmsDataService->execute($types, $identifiers, $removeAll, $hyvaCms);

        return 0;
    }
}
