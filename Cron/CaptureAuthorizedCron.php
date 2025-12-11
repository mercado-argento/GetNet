<?php
/**
 * Plugin Name:       Magento GetNet
 * License:           Copyright © 2023 PagoNxt Merchant Solutions S.L. and Santander España Merchant Services, Entidad de Pago, S.L.U.
 * You may not use this file except in compliance with the License which is available here https://opensource.org/licenses/AFL-3.0
 * License URI:       https://opensource.org/licenses/AFL-3.0
 *
 */
namespace GetnetArg\Payments\Cron;

use GetnetArg\Payments\Model\ClientWS;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction;
use Psr\Log\LoggerInterface;

class CaptureAuthorizedCron
{
    const CLIENT_ID = 'payment/argenmagento/client_id';
    const SECRET_ID = 'payment/argenmagento/secret_id';
    const TEST_PAYMENT = 'payment/argenmagento/test_environment';

    protected $orderFactory;
    protected $logger;
    public $_objectManager;
    protected $scopeConfig;
    protected $clientWS;
    protected $orderSender;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $_objectManager,
        LoggerInterface $logger,
        OrderFactory $orderFactory,
        ScopeConfigInterface $scopeConfig,
        ClientWS $clientWS,
        OrderSender $orderSender
    ) {
        $this->_objectManager  = $_objectManager;
        $this->orderFactory    = $orderFactory;
        $this->logger          = $logger;
        $this->scopeConfig     = $scopeConfig;
        $this->clientWS        = $clientWS;
        $this->orderSender     = $orderSender;
    }

    /**
     * Execute capture over authorized payments
     */
    public function execute()
    {
        $this->logger->info('---------------------------------------');
        $this->logger->info('Corriendo Cron Getnet - Capture autorizaciones.');

        $orders = $this->orderFactory->create()->getCollection()
            ->addFieldToSelect('*')
            ->addFieldToFilter('status', 'payment_review');

        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $clientId = $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope);
        $secretId = $this->scopeConfig->getValue(self::SECRET_ID, $storeScope);
        $testEnv  = $this->scopeConfig->getValue(self::TEST_PAYMENT, $storeScope);

        foreach ($orders as $order) {
            $payment = $order->getPayment();
            if (!$payment || $payment->getMethod() !== 'argenmagento') {
                continue;
            }

            $paymentId = $payment->getAdditionalInformation('paymentID');
            if (!$paymentId) {
                $this->logger->warning('Orden sin paymentID para captura -> ' . $order->getIncrementId());
                continue;
            }

            $amount = $payment->getAdditionalInformation('authorized_amount');
            if (!$amount) {
                $amount = (int)round($order->getGrandTotal() * 100);
            }

            $idempotencyKey = uniqid('capture-' . $order->getIncrementId() . '-');
            $body = json_encode([
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $paymentId,
                'amount' => (int)$amount
            ]);

            $token = $this->clientWS->getToken($clientId, $secretId, $testEnv);
            if (!$token || $token === 'invalido') {
                $this->logger->error('No se pudo obtener token para captura. Orden -> ' . $order->getIncrementId());
                continue;
            }

            $response = $this->clientWS->capturePayment($token, $body, $testEnv);
            $responseJson = json_decode($response, true);

            if (isset($responseJson['status']) && $responseJson['status'] === 'APPROVED') {
                $this->logger->info('Captura exitosa para la orden -> ' . $order->getIncrementId());
                $this->closeAuthorization($order, $payment, $responseJson);
            } else {
                $this->logger->warning('Captura sin aprobar para la orden -> ' . $order->getIncrementId());
            }
        }

        $this->logger->info('---------------------------------------');
    }

    protected function closeAuthorization($order, $payment, array $responseJson)
    {
        $transactionId = $responseJson['payment_id'] ?? $payment->getAdditionalInformation('paymentID');

        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionApproved(true);
        $payment->setLastTransId($transactionId);
        $payment->setAdditionalInformation('pagoAprobado', 'si');

        $payment->addTransaction(Transaction::TYPE_CAPTURE);

        if ($order->canInvoice()) {
            $invoiceService = $this->_objectManager->create('Magento\\Sales\\Model\\Service\\InvoiceService');
            $invoice = $invoiceService->prepareInvoice($order);
            $invoice->setTransactionId($transactionId)
                ->addComment("Invoice created after capture.")
                ->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register()->pay();
            $invoice->save();

            $transaction = $this->_objectManager->create('Magento\\Framework\\DB\\Transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transaction->save();

            $order->addStatusHistoryComment(__('Invoice #%1.', $invoice->getId()))
                ->setIsCustomerNotified(true);
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addStatusHistoryComment(__('Payment captured successfully.'));
        $order->save();
        $payment->save();

        $this->orderSender->send($order);
    }
}
