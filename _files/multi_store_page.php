<?php

declare(strict_types=1);

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Store\Model\Store;

$objectManager = Bootstrap::getObjectManager();

/**
 * @var $page Page
 * @var $pageRepository PageRepositoryInterface
 */
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(0);
$pageFactory = $objectManager->create(\Magento\Cms\Model\PageFactory::class);
$pageRepository = $objectManager->create(PageRepositoryInterface::class);
$store = $objectManager->create(Store::class);
$store->load('second_store_view', 'code');
$page = $pageFactory->create();
$page->load('imported_cms_page_multistore', 'identifier');
if ($page->getId()) {
    $pageRepository->delete($page);
    $page = $pageFactory->create();
}

$page->setTitle('Cms Page 100')
    ->setIdentifier('imported_cms_page_multistore')
    ->setStores([1, (int)$store->getId()])
    ->setIsActive(1)
    ->setContent('<h1>Cms Page 100 Title</h1>')
    ->setContentHeading('<h2>Cms Page 100 Title</h2>')
    ->setMetaTitle('Cms Meta title for page100')
    ->setMetaKeywords('Cms Meta Keywords for page100')
    ->setMetaDescription('Cms Meta Description for page100')
    ->setPageLayout('1column')
    ->setStoreId(0);
$pageRepository->save($page);

