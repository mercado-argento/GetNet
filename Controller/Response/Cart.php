<?php
/**
 * Plugin Name:       Magento GetNet
 * Plugin URI:        -
 * Description:       -
 * License:           Copyright © 2023 PagoNxt Merchant Solutions S.L. and Santander España Merchant Services, Entidad de Pago, S.L.U.
 * You may not use this file except in compliance with the License which is available here https://opensource.org/licenses/AFL-3.0 
 * License URI:       https://opensource.org/licenses/AFL-3.0
 *
 */
namespace GetnetArg\Payments\Controller\Response;

use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use \stdClass;

/**
 * Webhook Receiver Controller for Paystand
 */
class Cart extends \Magento\Framework\App\Action\Action
{

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $_request;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    protected $cart;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Sales\Model\OrderRepository
     */
    private $orderRepository;

    /**
     * @var
     */
    protected $quoteFactory;

    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;

    /**
     * @var \GetnetArg\Payments\Model\Cart
     */
    private \GetnetArg\Payments\Model\Cart $cartHelper;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\App\Request\Http $request
     * @param CheckoutSession $checkoutSession
     * @param \Magento\Checkout\Model\Cart $cart
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Sales\Model\OrderRepository $orderRepository
     * @param \Magento\Quote\Model\QuoteFactory $quoteFactory
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param \GetnetArg\Payments\Model\Cart $cartHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Request\Http $request,
        CheckoutSession $checkoutSession,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \GetnetArg\Payments\Model\Cart $cartHelper,
        array $data = []
    ) {
            parent::__construct($context);

            $this->_request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->cart = $cart;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartHelper = $cartHelper;
    }

    /**
     * Receives webhook events from Roadrunner
     * @return void
     */
    public function execute()
    {
        $this->logger->debug('----------------------------------------------');
        $this->logger->debug('-------------------Return Cart-------------------');

        $order = $this->checkoutSession->getLastRealOrder();

        $email = $order->getCustomerEmail();

        $this->logger->debug('-Last Order --> ' .$order->getIncrementId());
        $this->logger->debug('email --> ' .$email);
        $this->cartHelper->getCartItems($email);
        try {
              $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
              $resultRedirect->setPath('checkout/cart');
                 $this->logger->debug('Finished order fail');

        } catch (\Exception $e) {
            $this->logger->debug($e);
        }

        return $resultRedirect;
    }
}
