<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Model\Product;

use DateTime;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use Nosto\Object\Signup\Account;
use Nosto\Operation\DeleteProduct;
use Nosto\Operation\UpsertProduct;
use Nosto\Request\Http\HttpRequest;
use Nosto\Tagging\Helper\Account as NostoHelperAccount;
use Nosto\Tagging\Helper\Data as NostoHelperData;
use Nosto\Tagging\Logger\Logger as NostoLogger;
use Nosto\Tagging\Model\Product\Builder as NostoProductBuilder;
use Nosto\Tagging\Model\Product\Repository as NostoProductRepository;
use Nosto\Types\Product\ProductInterface as NostoProductInterface;

/**
 * Service class for updating products to Nosto
 *
 * @package Nosto\Tagging\Model\Product
 */
class Service
{

    public static $batchSize = 50;
    public static $responseTimeOut = 500;
    private $productUpdatesActive;

    private $nostoProductBuilder;
    private $logger;
    private $nostoHelperAccount;
    private $nostoHelperData;
    private $nostoProductRepository;
    private $nostoQueueFactory;
    private $storeManager;
    private $productFactory;
    private $nostoQueueRepository;
    private $storeEmulator;

    public $processed = [];

    /**
     * Constructor to instantiating the product update command. This constructor uses proxy classes for
     * two of the Nosto objects to prevent introspection of constructor parameters when the DI
     * compile command is run.
     * Not using the proxy classes will lead to a "Area code not set" exception being thrown in the
     * compile phase.
     * @param NostoLogger $logger
     * @param Builder $nostoProductBuilder
     * @param NostoHelperAccount\Proxy $nostoHelperAccount
     * @param NostoHelperData\Proxy $nostoHelperData
     * @param NostoProductRepository $nostoProductRepository
     * @param QueueRepository $nostoQueueRepository
     * @param QueueFactory $nostoQueueFactory
     * @param StoreManager $storeManager
     * @param ProductFactory $productFactory
     * @param Emulation $emulation
     */
    public function __construct(
        NostoLogger $logger,
        NostoProductBuilder $nostoProductBuilder,
        NostoHelperAccount\Proxy $nostoHelperAccount,
        NostoHelperData\Proxy $nostoHelperData,
        NostoProductRepository $nostoProductRepository,
        QueueRepository $nostoQueueRepository,
        QueueFactory $nostoQueueFactory,
        StoreManager $storeManager,
        ProductFactory $productFactory,
        Emulation $emulation
    ) {

        $this->logger = $logger;
        $this->nostoProductBuilder = $nostoProductBuilder;
        $this->nostoHelperAccount = $nostoHelperAccount;
        $this->nostoHelperData = $nostoHelperData;
        $this->nostoProductRepository = $nostoProductRepository;
        $this->nostoQueueRepository = $nostoQueueRepository;
        $this->nostoQueueFactory = $nostoQueueFactory;
        $this->storeManager = $storeManager;
        $this->productFactory = $productFactory;
        $this->storeEmulator = $emulation;
    }

    /**
     * Updates products to Nosto via API
     *
     * @param array $ids array of product ids
     * @throws \Exception
     */
    public function updateByIds(array $ids)
    {
        $this->addToQueueByIds($ids);
        $this->flushQueue();
    }

    /**
     * Adds products to queue by id
     *
     * @param ProductInterface[] array of product objects
     * @throws \Exception
     */
    public function update(array $products)
    {
        $this->addToQueue($products);
        $this->flushQueue();
    }

    /**
     * Adds products to queue by id
     *
     * @param array $ids
     * @throws \Exception
     */
    public function addToQueueByIds(array $ids)
    {
        $productSearchResults = $this->nostoProductRepository->getByIds($ids);
        $existingProductIds = [];
        if ($productSearchResults->getTotalCount() > 0) {
            /** @var Product[] $existingProducts */
            $existingProducts = $productSearchResults->getItems();
            $this->addToQueue($existingProducts);
            foreach ($existingProducts as $product) {
                $existingProductIds[] = $product->getId();
            }
        }

        $productsToBeDeleted = array_diff($ids, $existingProductIds);
        foreach ($productsToBeDeleted as $productId) {
            $productStub = $this->productFactory->create(['id' => $productId]);
            $productStub->setId($productId);
            $this->addToQueue([$productStub]);
        }
    }

    /**
     * Adds products to queue
     *
     * @param ProductInterface[] $products
     * @throws \Exception
     */
    public function addToQueue(array $products)
    {
        if ($this->checkProductUpdatesActive()) {
            $productCount = count($products);
            $this->logger->info(
                sprintf(
                    'Adding %d products to Nosto queue',
                    $productCount
                )
            );
            $productIdsForQueue = [];
            foreach ($products as $product) {
                if (!$product instanceof Product) {
                    continue;
                }
                $parentProductIds = $this->nostoProductRepository->resolveParentProductIds($product);
                if (!empty($parentProductIds)) {
                    foreach ($parentProductIds as $parentProductId) {
                        $productIdsForQueue[] = $parentProductId;
                    }
                } else {
                    $productIdsForQueue[] = $product->getId();
                }
            }

            //remove duplicate
            $productIdsForQueue = array_unique($productIdsForQueue);

            foreach ($productIdsForQueue as $productIdForQueue) {
                $queue = $this->nostoQueueFactory->create();
                $queue->setProductId($productIdForQueue);
                $queue->setCreatedAt(new DateTime());
                $this->nostoQueueRepository->save($queue); // @codingStandardsIgnoreLine
            }

            $this->logger->info(
                sprintf(
                    'Added %d products to Nosto queue',
                    $productCount
                )
            );
        } else {
            $this->logger->debug('Product API updates are disabled for all store views');
        }
    }

    /**
     * Updates all products in queue to Nosto
     *
     * @return void
     */
    public function flushQueue()
    {
        HttpRequest::buildUserAgent(
            NostoHelperData::PLATFORM_NAME,
            $this->nostoHelperData->getPlatformVersion(),
            $this->nostoHelperData->getModuleVersion()
        );

        $queueEntries = $this->nostoQueueRepository->getFirstPage(self::$batchSize);
        $remaining = $queueEntries->getTotalCount();
        //keep the $maxBatches, as a safe fuse to prevent unexpected infinite loop
        $maxBatches = $remaining / self::$batchSize;

        while ($remaining > 0 && $maxBatches > 0) {
            $this->logger->info(
                sprintf(
                    'Flushing %d products from Nosto queue',
                    $remaining
                )
            );

            $productIds = [];
            foreach ($queueEntries->getItems() as $queueEntry) {
                /** @var \Nosto\Tagging\Model\Product\Queue $queueEntry */
                $productIds[] = $queueEntry->getProductId();
            }
            try {
                $this->process($productIds);
            } catch (\Exception $e) {
                $this->logger->exception($e);
            } finally {
                //Regardless of success, delete it from the queue to avoid infinite loop
                $this->nostoQueueRepository->deleteByProductIds($productIds);
            }

            //prepare for next loop
            $queueEntries = $this->nostoQueueRepository->getFirstPage(self::$batchSize);
            $remaining = $queueEntries->getTotalCount();

            //It is a safe fuse, to prevent unexpected infinite loop
            $maxBatches--;
        }
    }

    /**
     * Updates products to Nosto by given product ids and store
     *
     * @param array $productIds
     */
    public function process(array $productIds)
    {
        $uniqueProductIds = array_unique($productIds);
        $storesWithNosto = $this->nostoHelperAccount->getStoresWithNosto();
        foreach ($storesWithNosto as $store) {
            if ($this->nostoHelperData->isProductUpdatesEnabled($store)) {
                $nostoAccount = $this->nostoHelperAccount->findAccount($store);
                if (!$nostoAccount instanceof Account) {
                    continue;
                }
                /** @var \Magento\Store\Model\Store\Interceptor $store */
                $this->storeEmulator->startEnvironmentEmulation($store->getId());
                try {
                    $this->processForAccount($uniqueProductIds, $store, $nostoAccount);
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                }
                $this->storeEmulator->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Updates products to Nosto by given product ids and store
     *
     * @param array $uniqueProductIds
     * @param Store $store
     * @param Account $nostoAccount
     * @throws \Exception
     */
    public function processForAccount(array $uniqueProductIds, Store $store, Account $nostoAccount)
    {
        $productSearch = $this->nostoProductRepository->getByIds($uniqueProductIds);

        $this->logger->info(
            sprintf(
                'Updating total of %d unique products for store %s',
                $productSearch->getTotalCount(),
                $store->getName()
            )
        );
        $productsStillExist = $productSearch->getItems();
        $productIdsStillExist = [];

        if (!empty($productsStillExist)) {
            $op = new UpsertProduct($nostoAccount);
            $op->setResponseTimeout(self::$responseTimeOut);

            /* @var Product $product */
            foreach ($productsStillExist as $product) {
                $productIdsStillExist[] = $product->getId();
                $nostoProduct = $this->nostoProductBuilder->build(
                    $product,
                    $store
                );
                if ($nostoProduct instanceof NostoProductInterface) {
                    $op->addProduct($nostoProduct);
                } else {
                    continue;
                }
            }

            try {
                $op->upsert();
                $storeName = 'Could not get Store Name';
                if ($this->storeManager->getStore()) {
                    $storeName = $this->storeManager->getStore()->getName();
                }
                $this->logger->info(
                    sprintf(
                        'Sent %d products to for store %s (%d)',
                        $productSearch->getTotalCount(),
                        $storeName,
                        $store->getId()
                    )
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf(
                        'Failed to send %d products for store %s (%d)' .
                        ' Error was %s',
                        $productSearch->getTotalCount(),
                        $store->getName(),
                        $store->getId(),
                        $e->getMessage()
                    )
                );
                $this->logger->exception($e);
            }
        }

        $leftProducts = array_diff($uniqueProductIds, $productIdsStillExist);
        if (!empty($leftProducts)) {
            $this->processDelete($leftProducts, $store, $nostoAccount);
        }
    }

    /**
     * Sends API calls to Nosto to delete / discontinue products
     *
     * @param array $uniqueProductIds
     * @param Store $store
     * @param Account $nostoAccount
     */
    public function processDelete(array $uniqueProductIds, Store $store, Account $nostoAccount)
    {
        $this->logger->info(
            sprintf(
                'Deleting / discontinuing total of %d unique products for store %s',
                count($uniqueProductIds),
                $store->getName()
            )
        );
        $op = new DeleteProduct($nostoAccount);
        $op->setProductIds($uniqueProductIds);
        try {
            $op->delete();
            $storeName = 'Could not get Store Name';
            if ($this->storeManager->getStore()) {
                $storeName = $this->storeManager->getStore()->getName();
            }
            $this->logger->info(
                sprintf(
                    'Sent %d products to for deletion %s (%d)',
                    count($uniqueProductIds),
                    $storeName,
                    $store->getId()
                )
            );
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Failed to delete %d products for store %s (%d)' .
                    ' Error was %s',
                    count($uniqueProductIds),
                    $store->getName(),
                    $store->getId(),
                    $e->getMessage()
                )
            );
            $this->logger->exception($e);
        }
    }

    /**
     * Checks if at least one of the store views has product updates active
     *
     * @return bool
     */
    public function checkProductUpdatesActive()
    {
        if ($this->productUpdatesActive === null) {
            // Loop through stores and check that at least one store has product
            // updates via API active
            $this->productUpdatesActive = false;
            foreach ($this->nostoHelperAccount->getStoresWithNosto() as $store) {
                if ($this->nostoHelperData->isProductUpdatesEnabled($store)) {
                    $this->productUpdatesActive = true;
                    break;
                }
            }
        }

        return $this->productUpdatesActive;
    }
}
