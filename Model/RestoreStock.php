<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 *
 */
namespace GetnetArg\Payments\Model;

class RestoreStock extends \Magento\Framework\View\Element\Template
{

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    private \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite $stockIdForCurrentWebsite;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite $stockIdForCurrentWebsite
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite $stockIdForCurrentWebsite,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->moduleManager      = $moduleManager;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->stockIdForCurrentWebsite = $stockIdForCurrentWebsite;
    }

    /**
     * @param $order
     * @return void
     */
    public function execute($order)
    {

        try {
           //if inventory is enabled
            if (!$this->isInventoryEnabled()) {
                return;
            }

            $this->logger->debug('isInventoryEnabled --> restore cart');

            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_reservation');
            $stockId    = (int)$this->stockIdForCurrentWebsite->execute();


            foreach ($order->getAllItems() as $item) {
                    $product = $item->getProduct();

                    $quantity = $item->getQtyOrdered();

                    $this->logger->debug('Quantity --> ' .$quantity);

                    $metadata = [
                        'event_type'          => 'back_item_qty',
                        'object_type'         => 'legacy_stock_management_api',
                        'object_id'           => '',
                        'object_increment_id' => $order->getIncrementId()
                    ];

                    $connection->insert($tableName, [
                        'stock_id' => $stockId,
                        'sku' => $product->getSku(),
                        'quantity' => (float)$quantity,
                        'metadata' => json_encode($metadata)
                    ]);

                    $this->logger->debug('inserto-----');
            }


        } catch (\Exception $e) {
               $this->logger->debug(' exception --> ' . $e);
        }
    }

   /**
    * Check if Magento inventory feature is enabled.
    *
    */
    public function isInventoryEnabled()
    {
        $requiredModules = [
            'Magento_Inventory',
            'Magento_InventoryApi',
            'Magento_InventoryCatalog',
            'Magento_InventorySalesApi',
        ];

        // Check if each required module is enabled
        foreach ($requiredModules as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }
}
