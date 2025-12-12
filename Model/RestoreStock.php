<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 *
 */
namespace GetnetArg\Payments\Model;

class RestoreStock extends \Magento\Framework\View\Element\Template
{

    protected $logger;

    protected $moduleManager;

    protected $resourceConnection;

    private \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite $stockIdForCurrentWebsite;

    /**
     *
     *
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
     *
     *
     */
    public function execute($order){

             try{
                //if inventory is enabled
                if (!$this->isInventoryEnabled())
                    return;

                $this->logger->debug('isInventoryEnabled --> restore cart');

                $connection = $this->resourceConnection->getConnection();
                $stockId    = $this->stockIdForCurrentWebsite;


                foreach ($order->getAllItems() as $item) {
                    $product = $item->getProduct();

                    $quantity = $item->getQtyOrdered();

                    $this->logger->debug('Quantity --> ' .$quantity);

                    $metadata = [
                        'event_type'          => "back_item_qty",
                        "object_type"         => "legacy_stock_management_api",
                        "object_id"           => "",
                        "object_increment_id" => $order->getIncrementId()
                    ];

                    $query = "INSERT INTO inventory_reservation (stock_id, sku, quantity, metadata)
                        VALUES (".$stockId->execute().", '".$product->getSku()."', ".$quantity.", '".json_encode($metadata)."');
";

                    //Insert data in db
                    $connection->query($query);

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
            'Magento_InventorySalesApi',
        ];

        // Check if each required module is enabled
        foreach ($requiredModules as $module)
            if (!$this->moduleManager->isEnabled($module))
                return false;

        return true;
    }

}
