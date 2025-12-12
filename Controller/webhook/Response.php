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
namespace GetnetArg\Payments\Controller\Webhook;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Quote\Model\QuoteFactory as QuoteFactory;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;

class Response extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $request;

    const USER_NOTIF = 'payment/argenmagento/user_notif';

    const PASW_NOTIF = 'payment/argenmagento/pasw_notif';

    private $_quote;

    private $modelCart;

    private $orderRepository;

    private $quoteManagement;

    private $eventManager;

    private $maskedQuoteIdToQuoteId;

    protected $_urlInterface;

    protected $urlBuilder;

    protected $transactionBuilder;


    protected $_quoteFactory;

    protected $checkoutSession;

    protected $customerSession;

    protected $quoteIdMaskFactory;

    protected $_jsonResultFactory;

    protected $quoteRepository;

    protected $logger;

    protected $jsonResultFactory;

    protected $orderHelper;

    /**
     * @var OrderCollectionFactory
     */
    private OrderCollectionFactory $orderCollectionFactory;

    /**
     * @var ScopeConfig
     */
    private ScopeConfig $scopeConfig;


        /**
         * @param \Magento\Framework\App\Request\Http $request
         * @param \Magento\Framework\App\Action\Context $context
         * @param \Psr\Log\LoggerInterface $logger
         * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
         * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
         * @param \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder
         * @param \Magento\Sales\Model\OrderRepository $orderRepository
         * @param OrderCollectionFactory $orderCollectionFactory
         * @param \Magento\Framework\Event\ManagerInterface $eventManager
         * @param \Magento\Checkout\Model\Session $checkoutSession
         * @param \Magento\Customer\Model\Session $customerSession
         * @param \Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
         * @param \Magento\Sales\Model\Order $order
         * @param QuoteFactory $quoteFactory
         * @param QuoteIdMaskFactory $quoteIdMaskFactory
         * @param ScopeConfig $scopeConfig
         * @param JsonFactory $jsonResultFactory
         * @param \Magento\Checkout\Model\Cart $modelCart
         * @param \GetnetArg\Payments\Model\OrderHelper $orderHelper
         */
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        OrderCollectionFactory $orderCollectionFactory,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        \Magento\Sales\Model\Order $order,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig,
        JsonFactory $jsonResultFactory,
        \Magento\Checkout\Model\Cart $modelCart,
        \GetnetArg\Payments\Model\OrderHelper $orderHelper
    ) {
        parent::__construct($context);
        $this->request = $request;
        $this->logger = $logger;
        $this->urlBuilder = $context->getUrl();
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->_quoteFactory = $quoteFactory;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->transactionBuilder = $transactionBuilder;
        $this->eventManager = $eventManager;
        $this->modelCart = $modelCart;
        $this->scopeConfig = $scopeConfig;
        $this->order = $order;
        $this->jsonResultFactory = $jsonResultFactory;
        $this->orderHelper = $orderHelper;
    }

    /**
     * Create Csrf Validation Exception.
     *
     * @param RequestInterface $request
     *
     * @return null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate For Csrf.
     *
     * @param RequestInterface $request
     *
     * @return bool true
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     *
     * *
     */
    public function execute()
    {
        $responseGetnet = $this->request->getContent();
        $this->logger->info('---------RESULTADO BODY RESPONSE. (responseGetnet) -----------');
        $this->logger->debug($responseGetnet);
        $webMessage = 'Webhook received successfully.';
        $statusHTTP = 'success';
        $response = $this->jsonResultFactory->create();
        $response->setHttpResponseCode(200);
        try {
            $jsondata = json_decode($responseGetnet, true);
            $email = $jsondata["customer"]["email"] ?? '';
            if (!$email) {
                throw new \InvalidArgumentException('Customer email missing in webhook payload');
            }

            $orderDatamodel = $this->orderCollectionFactory->create()
                ->addFieldToFilter('customer_email', ['eq' => $email])
                ->setOrder('entity_id', 'DESC')
                ->getFirstItem();

            if (!$orderDatamodel->getId()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('No order found for webhook email %1', $email)
                );
            }

            $order = $this->orderRepository->get($orderDatamodel->getId());
            $this->logger->info('OrderID --> ' . $order->getId());
            $originHeader = $this->getRequest()->getHeader('Authorization');
            if ($this->_validateRequest($originHeader, $order)) {
                $status = $jsondata["payment"]["result"]["status"];
                if ($status == 'Authorized') {
                    $webMessage = $this->_processAuthorizedPayment($jsondata, $order);
                } else { //Error in response
                    $webMessage = $this->_processDeclinedPayment($jsondata, $order);
                }
            } else {
                $statusHTTP = 'forbidden';
                $response->setHttpResponseCode(403);
            }
        } catch (\Exception $e) {
            $this->logger->info($e);
            $statusHTTP = 'error';
            $response->setHttpResponseCode(500);
            $webMessage = __('Error processing webhook');
        }
        $response->setData(['status' => $statusHTTP, 'message' => $webMessage]);
        return $response;
    }

    /**
     * @param $originHeader
     * @param $order
     * @return bool
     */
    private function _validateRequest($originHeader, $order)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $user = $this->scopeConfig->getValue(self::USER_NOTIF, $storeScope);
        $pasw = $this->scopeConfig->getValue(self::PASW_NOTIF, $storeScope);
        $basicOrigen = 'Basic ' . base64_encode($user . ':' . $pasw);
        if ($basicOrigen == $originHeader) {
            $this->logger->info("-- validation webhook success --");
            return true;
        } else {
            $this->logger->info('Validation Webhook - bad credentials');
            $webMessage = 'Validation Webhook Error';
            $order->addStatusHistoryComment($webMessage, false);
            $order->save();
            return false;
        }
    }

    /**
     * @param $jsondata
     * @param $order
     * @return string
     */
    private function _processAuthorizedPayment($jsondata, $order)
    {
        $method = $jsondata["payment"]["method"];
        $amount = $jsondata["payment"]["amount"];
        $status = $jsondata["payment"]["result"]["status"];
        $paymentID = $jsondata["payment"]["result"]["payment_id"];
        $authCode = $jsondata["payment"]["result"]["authorization_code"];
        try {
            $shippingAmount = $jsondata["shipping"]["shipping_amount"];
            $this->logger->debug('Shipping cost --> ' . $shippingAmount);
        } catch (\Exception $e) {
            $shippingAmount = 0;
        }
        try {
            $interes = $jsondata["payment"]["installment"]["interest_rate"];
            $this->logger->debug('interes --> ' . $interes);
        } catch (\Exception $e) {
            $interes = 0;
        }
        $grandTotal = ($amount + $shippingAmount + $interes) / 100;
        $this->logger->info('Total --> ' . $order->getGrandTotal() . '');
        $status = $order->getStatus();
        $payment = $order->getPayment();
        $isTwoStepPayment = $this->orderHelper->isTwoStepPayment();
        $finalState = ($isTwoStepPayment) ? $this->orderHelper->getAuthorizedStatus() : $this->orderHelper->getCapturedStatus();
        if ($status != $finalState) {
            try {
                $this->checkoutSession->setForceOrderMailSentOnSuccess(true);
                $this->checkoutSession
                    ->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());
                $order->setState($status);
                $order->setStatus($status);
                $order->setGrandTotal($grandTotal);
                $action = ($isTwoStepPayment) ? 'authorized' : 'process';
                try {
                        $comment = __(
                            'Payment ' . $action . ' with %1, paymentID: %2 - Authorization Code: %3 >>>>> Interes: %4',
                            $method,
                            $paymentID,
                            $authCode,
                            $interes / 100
                        );
                        $order->addStatusHistoryComment($comment, false);
                    $order->save();
                } catch (\Exception $e) {
                    $this->logger->error('Error Capture');
                }
                $payment = $order->getPayment();
                $payment->setLastTransId($paymentID);
                if ($isTwoStepPayment) {
                    $payment->setIsTransactionPending(true);
                    $payment->setIsTransactionClosed(false);
                    $payment->setShouldCloseParentTransaction(false);
                } else {
                    $payment->setIsTransactionPending(false);
                    $payment->setIsTransactionClosed(true);
                    $payment->setShouldCloseParentTransaction(true);
                }
                $transaction = $payment->addTransaction(
                    \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH
                );
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionApproved(true);
                $address = $order->getBillingAddress();
                if ($address) {
                    $address->setIsDefaultBilling(false)
                        ->setIsDefaultShipping(true)
                        ->setSaveInAddressBook(true);
                    $address->save();
                }
                //for Refund and Capture
                $domain = $this->urlBuilder->getRouteUrl('argenmagento');
                $payment->setAdditionalInformation('domain', $domain);
                $payment->setAdditionalInformation('status', $status);
                $payment->setAdditionalInformation('authCode', $authCode);
                $payment->setAdditionalInformation('interes', $interes);
                $payment->setAdditionalInformation('paymentID', $paymentID);
                $payment->setAdditionalInformation('method', 'argenmagento');
                $payment->setAdditionalInformation('authorized_amount', $amount);
                if ($isTwoStepPayment) {
                    $payment->setAdditionalInformation('authorized_amount', $amount);
                    $payment->setAdditionalInformation('pagoAprobado', null);
                } else {
                    $payment->setAdditionalInformation('pagoAprobado', 'si');
                }
                                $transaction = $this->transactionBuilder->setPayment($payment)
                    ->setOrder($order)
                    ->setTransactionId($paymentID)
                    ->setAdditionalInformation($payment->getTransactionAdditionalInfo())
                    ->build(Transaction::TYPE_AUTH);
                $payment->setParentTransactionId(null);
                $payment->save();
                $order->save();
                if ($isTwoStepPayment) {
                    $this->logger->debug('Authorization stored, awaiting capture');
                } else {
                    $this->logger->debug('guardo transacción');
                }
                if ($order) {
                    $this->logger->debug('-order-');
                    $this->checkoutSession->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus($order->getStatus());
                }
                if ($isTwoStepPayment) {
                                    $message = __('Your payment was authorized and is pending capture');
                    $this->messageManager->addSuccessMessage($message);
                } else {
                    $this->messageManager->addSuccessMessage(__('Your payment was processed correctly'));
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('Error while saving the transaction'));
            }
            //eliminando cart
            $cart = $this->modelCart;
            $cart->truncate();
            $cart->save();
            $webMessage = 'Webhook received successfully.';
            $this->logger->debug('Finished order complete callback.');
        } else {
            $webMessage = 'Webhook -> Duplicate notification';
            $this->logger->info('Webhook -> Duplicate notification');
        }
        return $webMessage;
    }

    /**
     * @param $jsondata
     * @param $order
     * @return string
     */
    private function _processDeclinedPayment($jsondata, $order)
    {
        $status = $jsondata["payment"]["result"]["status"];
        $webMessage = $status . ' status --  > Payment declined <';
        try {
            $message = $jsondata["payment"]["result"]["return_message"];
            $order->setState('getnet_rejected');
            $order->setStatus('getnet_rejected');
            $order->addStatusHistoryComment(__('Payment declined') . '--> ' . $message, false);
            $order->save();
        } catch (\Exception $e) {
            $this->logger->error($e);
        }
        $this->logger->info('Payment Denied');
        return $webMessage;
    }
}
