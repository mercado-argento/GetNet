<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 *
 */
namespace GetnetArg\Payments\Model;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Message\ManagerInterface;
use \Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class Cart extends \Magento\Framework\View\Element\Template
{

    /**
     * @var \Magento\Framework\Registry
     */
    private $registry;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    protected $cart;

    /**
     * @var \Magento\Sales\Model\OrderRepository
     */
    private $orderRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var
     */
    protected $quoteFactory;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected \Magento\Sales\Model\Order $order;

    /**
     * @var OrderCollectionFactory
     */
    protected \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Psr\Log\LoggerInterface $logger
     * @param ManagerInterface $messageManager
     * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param CheckoutSession $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Sales\Model\OrderRepository $orderRepository
     * @param \Magento\Sales\Model\Order $order
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param \Magento\Quote\Model\QuoteFactory $quoteFactory
     * @param \Magento\Checkout\Model\Cart $cart
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Psr\Log\LoggerInterface $logger,
        ManagerInterface $messageManager,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        CheckoutSession $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Checkout\Model\Cart $cart,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->registry = $registry;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->order = $order;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->cart = $cart;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
    }

    /**
     * @param $email
     * @return void
     * @throws \Magento\Framework\Exception\InputException
     */
    public function getCartItems($email)
    {
        try {
              $this->logger->debug('----Restore cart items----');

                $orderDatamodel = $this->orderCollectionFactory->create();
                $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();

                $order = $this->orderRepository->get($orderDatamodel->getId());

                $quoteId = $order->getId();
                $this->logger->debug($quoteId);
                $this->logger->debug($order->getGrandTotal());

                $quote = $this->quoteFactory->create()->load($order->getQuoteId());
                $this->logger->debug('QuoteID -- ' .$quote->getId());

                //cancelamos la orden
                $this->cancelaOrden($order);

                $this->logger->debug('Restore -- ');

                //Restore cart
                $quote->setReservedOrderId(null);
                $quote->setIsActive(true);
                $quote->removePayment();
                $quote->save();

                $this->logger->debug('-');

                $this->checkoutSession->replaceQuote($quote);
                $this->cart->setQuote($quote);
                $this->checkoutSession->restoreQuote();

                $this->logger->debug('-------- ');
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
               $this->logger->info('Error restore quote --> ' . $e);
        }
    }

    /**
     * Cancela orden
     */
    private function cancelaOrden($order)
    {
        $order->cancel();
        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
        $order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_CANCELED, 'Orden cancelada', false);
        $order->save();
    }
}
