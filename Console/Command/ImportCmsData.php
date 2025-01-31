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

class ImportCmsData extends \Symfony\Component\Console\Command\Command
{
    private const INPUT_KEY_TYPE = 'type';
    private const INPUT_TYPE_VALUES = ['block', 'page', 'all'];
    private const INPUT_KEY_IDENTIFIER = 'identifier';
    private const INPUT_KEY_IMPORT_ALL = 'importAll';
    private const INPUT_KEY_STORE = 'store';
    private \RocketWeb\CmsImportExport\Model\Service\ImportCmsDataService $importCmsDataService;

    public function __construct(
        \RocketWeb\CmsImportExport\Model\Service\ImportCmsDataService $importCmsDataService,
        string $name = null
    ) {
        parent::__construct($name);
        $this->importCmsDataService = $importCmsDataService;
    }

    protected function configure()
    {
        $this->setName('cms:import:data');
        $this->setDescription('Import cms pages/blocks from var/sync_cms_data');
        $this->setDefinition([
            new InputOption(
                self::INPUT_KEY_TYPE,
                't',
                InputOption::VALUE_REQUIRED,
                'Which type are we importing - block/page/all'
            ),
            new InputOption(
                self::INPUT_KEY_IDENTIFIER,
                'i',
                InputOption::VALUE_OPTIONAL,
                'identifier to process (one or CSV list)'
            ),
            new InputOption(
                self::INPUT_KEY_IMPORT_ALL,
                'a',
                InputOption::VALUE_NONE,
                'Flag to import all files'
            ),
            new InputOption(
                self::INPUT_KEY_STORE,
                's',
                InputOption::VALUE_OPTIONAL,
                'Specific Store Code'
            )
        ]);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getOption(self::INPUT_KEY_TYPE);
        $importAll = (bool)$input->getOption(self::INPUT_KEY_IMPORT_ALL);
        if ($type === null) {
            throw new \RuntimeException("Type ([-t|--type) is required");
        }
        if (!in_array($type, self::INPUT_TYPE_VALUES)) {
            throw new \RuntimeException('Unsupported type for import');
        }
        $types = [];
        if ($type == 'all' || $type == 'block') {
            $types[] = 'block';
        }
        if ($type == 'all' || $type == 'page') {
            $types[] = 'page';
        }

        $identifiers = $input->getOption(self::INPUT_KEY_IDENTIFIER);
        if ($identifiers !== null) {
            $identifiers = explode(',', $identifiers);
        }

        $storeCode = empty($input->getOption(self::INPUT_KEY_STORE)) ?
            null :
            $input->getOption(self::INPUT_KEY_STORE);

        $this->importCmsDataService->execute($types, $identifiers, $importAll, $storeCode);

        return 0;
    }
}
