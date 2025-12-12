<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 *
 */
namespace GetnetArg\Payments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Ramsey\Uuid\Uuid;

class OrderHelper extends \Magento\Framework\View\Element\Template
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
     * @var \Magento\Sales\Model\OrderRepository
     */
    private $orderRepository;

    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    private \Magento\Checkout\Model\Cart $modelCart;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private \Magento\Sales\Model\Order $order;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender;

    /**
     * @var CollectionFactory
     */
    private \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Sales\Model\OrderRepository $orderRepository
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Checkout\Model\Cart $modelCart
     * @param CollectionFactory $orderCollectionFactory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Checkout\Model\Cart $modelCart,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->registry = $registry;
        $this->logger = $logger;
        $this->modelCart = $modelCart;
        $this->order = $order;
        $this->orderSender = $orderSender;
        $this->orderRepository = $orderRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param $email
     * @return string
     */
    public function getBodyOrderRequest($email)
    {
        try {
            $orderDatamodel = $this->orderCollectionFactory->create();
            $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();
            $order = $this->orderRepository->get($orderDatamodel->getId());

            $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                ->addStatusHistoryComment(__('Se inicia intención de pago.'))
                ->setIsCustomerNotified(false);
            $order->save();

            $currency = $order->getOrderCurrencyCode();
            $amount = $order->getGrandTotal();
            $this->logger->debug('Amount  -->' . $amount . ' ' . $currency);

            $firstname = $order->getCustomerFirstname();
            $lastname = $order->getCustomerLastname();

            $shippingAddress = $order->getShippingAddress();
            $shippingAmount = $order->getShippingAmount();

            $billingAddress = $order->getBillingAddress();

            $dni = '9999999999'; //default
            try {
                $orderDni = $order->getDni();
                if (!empty($orderDni)) {
                    $dni = $orderDni;
                } else {
                    $shippingDni = $shippingAddress->getData('dni');
                    if (!empty($shippingDni)) {
                        $dni = $shippingDni;
                    }
                }
                if (strlen($dni) < 8 || strlen($dni) > 15) {
                    $dni = '9999999999'; //default
                }
            } catch (\Exception $e) {
                $this->logger->debug($e);
            }

            $items = $order->getAllVisibleItems();
            $itemData = [];
            foreach ($items as $item) {
                $itemData[] = [
                    'product_type' => 'digital_content',
                    'title' => $item->getName(),
                    'description' => $item->getName(),
                    'value' => (int)($item->getPrice() * 100),
                    'quantity' => (int)$item->getQtyOrdered(),
                ];
            }

            //request without decimals
            $amount = (int)($amount * 100);
            $shippingAmount = (int)($shippingAmount * 100);
            $amountBeforeShip = $amount - $shippingAmount;

            $body = [
                'mode' => 'instant',
                'payment' => [
                    'amount' => $amountBeforeShip,
                    'currency' => $currency,
                ],
                'product' => $itemData,
                'customer' => [
                    'customer_id' => Uuid::uuid4()->toString(),
                    'first_name' => $firstname,
                    'last_name' => $lastname,
                    'name' => $firstname . ' ' . $lastname,
                    'email' => $email,
                    'document_type' => 'dni',
                    'document_number' => $dni,
                    'checked_email' => true,
                    'billing_address' => [
                        'street' => substr(preg_replace('/[^a-zA-Z ]+/', ' ', (string)$billingAddress->getStreetLine(1)), 0, 59),
                        'number' => preg_replace('/[^0-9]+/', ' ', (string)$billingAddress->getStreetLine(1)) ?: '0',
                        'country' => substr($billingAddress->getCountryId(), 0, 19),
                        'postal_code' => $billingAddress->getPostcode(),
                    ],
                ],
                'shipping' => [
                    'first_name' => $shippingAddress->getFirstname(),
                    'last_name' => $shippingAddress->getLastname(),
                    'name' => $shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname(),
                    'shipping_amount' => $shippingAmount,
                    'address' => [
                        'street' => substr(preg_replace('/[^a-zA-Z ]+/', ' ', (string)$shippingAddress->getStreetLine(1)), 0, 59),
                        'number' => preg_replace('/[^0-9]+/', ' ', (string)$shippingAddress->getStreetLine(1)) ?: '0',
                        'country' => substr($shippingAddress->getCountryId(), 0, 19),
                        'postal_code' => $shippingAddress->getPostcode(),
                    ],
                ],
                'pickup_store' => true,
                'shipping_method' => 'PAC',
                'authorization' => 'Bearer XXXXXXXXXX',
            ];

            $isTwoStepPayment = $this->isTwoStepPayment();
            if ($isTwoStepPayment) {
                $body['payment']['payment_type'] = 'authorize';
            }

            // Add optional fields
            if ($city_bil = $billingAddress->getCity()) {
                $body['customer']['billing_address']['city'] = substr($city_bil, 0, 39);
            }
            if ($state_bil = $billingAddress->getRegion()) {
                $body['customer']['billing_address']['state'] = substr($state_bil, 0, 19);
            }
            if ($telefono_bil = $billingAddress->getTelephone()) {
                $body['customer']['phone_number'] = substr(str_replace("+", "", $telefono_bil), 0, 14);
            }

            if ($city_ship = $shippingAddress->getCity()) {
                $body['shipping']['address']['city'] = substr($city_ship, 0, 39);
            }
            if ($state_ship = $shippingAddress->getRegion()) {
                $body['shipping']['address']['state'] = substr($state_ship, 0, 19);
            }
            if ($telefono_ship = $shippingAddress->getTelephone()) {
                $body['shipping']['phone_number'] = substr(str_replace("+", "", $telefono_ship), 0, 14);
            }


            $this->logger->debug('----------------Create Body Request-----------------');
            $jsonBody = json_encode($body);
            $this->logger->debug('Json Enviado --> ' . $jsonBody);

        } catch (\Exception $ee) {
            $this->logger->debug($ee);
            return '-';
        }

        return $jsonBody;
    }

    /**
     * Check if two-step payment is enabled
     *
     * @param $storeId
     * @return bool
     */
    public function isTwoStepPayment($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            'payment/argenmagento/two_step_payment',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get authorized order status
     *
     * @param $storeId
     * @return string
     */
    public function getAuthorizedStatus($storeId = null)
    {
        return (string)$this->scopeConfig->getValue(
            'payment/argenmagento/authorized_order_status',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get captured order status
     *
     * @param $storeId
     * @return string
     */
    public function getCapturedStatus($storeId = null)
    {
        return (string)$this->scopeConfig->getValue(
            'payment/argenmagento/captured_order_status',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param $dividend
     * @param $divisor
     * @return float
     */
    public function divide($dividend, $divisor)
    {
        if (empty((float)$divisor)) {
            return (float)0;
        }

        return (float)($dividend / $divisor);
    }

    /**
     * @param $taxAmount
     * @param $grossAmount
     * @param $decimals
     * @return string
     */
    public function calculateTax($taxAmount, $grossAmount, $decimals = 2)
    {
        return number_format(
            $this->divide($taxAmount, $grossAmount) * 100,
            $decimals
        );
    }
}
