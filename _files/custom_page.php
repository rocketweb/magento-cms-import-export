<?php

declare(strict_types=1);

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/**
 * @var $page Page
 * @var $pageRepository PageRepositoryInterface
 */
$page = $objectManager->create(Page::class);
$pageRepository = $objectManager->create(PageRepositoryInterface::class);

$page->setTitle('Cms Page 100')
    ->setIdentifier('imported_cms_page')
    ->setStores([1])
    ->setIsActive(1)
    ->setContent('<h1>Cms Page 100 Title</h1>')
    ->setContentHeading('<h2>Cms Page 100 Title</h2>')
    ->setMetaTitle('Cms Meta title for page100')
    ->setMetaKeywords('Cms Meta Keywords for page100')
    ->setMetaDescription('Cms Meta Description for page100')
    ->setPageLayout('1column');
$pageRepository->save($page);
