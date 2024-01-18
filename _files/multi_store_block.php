<?php

declare(strict_types=1);

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Store\Model\Store;

$objectManager = Bootstrap::getObjectManager();

/**
 * @var $block Block
 * @var $blockRepository BlockRepositoryInterface
 */
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(0);
$blockFactory = $objectManager->create(\Magento\Cms\Model\BlockFactory::class);
$blockRepository = $objectManager->create(BlockRepositoryInterface::class);
$store = $objectManager->create(Store::class);
$store->load('second_store_view', 'code');
$block = $blockFactory->create();
$block->load('import_gopher_cms_block_multistore', 'identifier');
if ($block->getId()) {
    $blockRepository->delete($block);
    $block = $blockFactory->create();
}

$block->setTitle(
    'CMS Block Title'
)->setIdentifier(
    'import_gopher_cms_block_multistore'
)->setContent(
    '<h1>Fixture Block Title</h1>
<a href="{{store url=""}}">store url</a>
<p>Config value: "{{config path="web/unsecure/base_url"}}".</p>
<p>Custom variable: "{{customvar code="variable_code"}}".</p>
'
)->setIsActive(
    1
)->setStores([1, (int)$store->getId()])
->setStoreId(0);

$blockRepository->save($block);

