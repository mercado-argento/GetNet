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
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;

class OrderUpdate
{

    private const ACTIVE = 'payment/argenmagento/cron_enabled';
    private const CLIENT_ID = 'payment/argenmagento/client_id';
    private const SECRET_ID = 'payment/argenmagento/secret_id';
    private const TEST_ENV = 'payment/argenmagento/test_environment';

    /**
     * @var ClientWS
     */
    protected $client;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var StoreRepositoryInterface
     */
    protected $storeRepository;

    /**
     * @var CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var InvoiceService
     */
    private InvoiceService $invoiceService;

    /**
     * @var TransactionFactory
     */
    private TransactionFactory $transactionFactory;

    /**
     * Constructor
     *
     * @param ClientWS $client
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreRepositoryInterface $storeRepository
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param LoggerInterface $logger
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     */
    public function __construct(
        ClientWS $client,
        ScopeConfigInterface $scopeConfig,
        StoreRepositoryInterface $storeRepository,
        CollectionFactory $orderCollectionFactory,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        LoggerInterface $logger,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory
    ) {
        $this->client = $client;
        $this->scopeConfig = $scopeConfig;
        $this->storeRepository = $storeRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
    }

    /**
     * Write to system.log
     *
     * @return void
     */
    public function execute()
    {
        $storeList = $this->storeRepository->getList();
        $paymentReviewState = Order::STATE_PAYMENT_REVIEW;
        $statesToCancel = [Order::STATE_PENDING_PAYMENT, 'getnet_rejected', 'pending'];
        foreach ($storeList as $store) {
            $storeId = (int)$store->getStoreId();
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            if (!$this->scopeConfig->getValue(self::ACTIVE, $storeScope, $storeId)) {
                continue;
            }

            $ordersToUpdate = $this->getOrders([$paymentReviewState], $storeId);
            $hours = (int) $this->scopeConfig->getValue('payment/argenmagento/delete_pending_after');
            $ordersToCancel = $this->getOrders($statesToCancel, $storeId, $hours);
            $ordersToAuthorize = $this->getOrders(['approbado_koin'], $storeId);
            if ($ordersToUpdate->getSize() === 0 && $ordersToCancel->getSize() === 0) {
                continue;
            }

            $clientId = $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope, $storeId);
            $secretId = $this->scopeConfig->getValue(self::SECRET_ID, $storeScope, $storeId);
            $testEnv = $this->scopeConfig->getValue(self::TEST_ENV, $storeScope, $storeId);
            $token = $this->client->getToken($clientId, $secretId, $testEnv);

            foreach ($ordersToUpdate as $order) {
                $this->logger->info('PaymentDataValidator::Start Processing Order #' . $order->getIncrementId());
                $orderPayment = $order->getPayment();
                $paymentId = $orderPayment->getAdditionalInformation('paymentID');
                if (!$paymentId) {
                    $this->logger->info('PaymentDataValidator::Missing Payment Id in Order #' . $order->getIncrementId());
                    continue;
                }

                try {
                    $paymentResponseData = $this->client->getPaymentInfo($token, $testEnv, $paymentId);

                    if (empty($paymentResponseData)) {
                        $this->logger->info('PaymentDataValidator::Invalid payment response data for Order #' . $order->getIncrementId());
                        continue;
                    }

                    $paymentData = json_decode($paymentResponseData, true);

                    if ($paymentData['status'] == 'Authorized') {
                        $this->logger->info('Payment approved for order #' . $order->getIncrementId());
                        $cuota = $paymentData['payment_method']['installments']['quantity'] ?? '1';
                        $orderPayment->setAdditionalInformation('cuota', $cuota);
                        $orderPayment->setAdditionalInformation('cupon', $paymentData['authorization_code']);
                        $orderPayment->setAdditionalInformation('transaction_id', $paymentData['transaction_identifier']);
                        $orderPayment->setAdditionalInformation('card_type', $paymentData['payment_method']['brand']);
                        $orderPayment->setAdditionalInformation('dni', $paymentData['customer']['document_number']);
                        $orderPayment->setAdditionalInformation('card', $paymentData['payment_method']['last_four_digits']);
                        $orderPayment->setAdditionalInformation('getnet_payment_data', $paymentResponseData);
                        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                        $this->orderRepository->save($order);
                        $this->logger->info('Order #' . $order->getIncrementId() . ' updated successfully.');

                    } elseif ($paymentData['status'] == 'Rejected' || $paymentData['status'] == 'Cancelled') {
                        $this->cancelOrder($order);
                    }
                } catch (\Exception $e) {
                    $this->logger->info($e->getMessage());
                }
            }

            foreach ($ordersToCancel as $order) {
                try {
                    $this->logger->info("Cancelando orden: " . $order->getIncrementId());
                    $this->cancelOrder($order);
                } catch (\Exception $e) {
                    $this->logger->info($e->getMessage());
                }
            }

            foreach ($ordersToAuthorize as $order) {
                $this->logger->info('PaymentDataValidator::Start Processing Order #' . $order->getIncrementId());
                $payment = $order->getPayment();
                $amount = $payment->getAdditionalInformation('authorized_amount');
                if (!$amount) {
                    $amount = (int)round($order->getGrandTotal() * 100);
                }

                $idempotencyKey = uniqid('capture-' . $order->getIncrementId() . '-');
                $body = json_encode([
                    'idempotency_key' => $idempotencyKey,
                    'payment_id' => $paymentId,
                    'amount' => (int) $amount
                ]);

                $token = $this->client->getToken($clientId, $secretId, $testEnv);
                if (!$token || $token === 'invalido') {
                    $this->logger->error('No se pudo obtener token para captura. Orden -> ' . $order->getIncrementId());
                    continue;
                }

                $response = $this->client->capturePayment($token, $body, $testEnv);
                $responseJson = json_decode($response, true);

                if (isset($responseJson['status']) && $responseJson['status'] === 'APPROVED') {
                    $this->logger->info('Captura exitosa para la orden -> ' . $order->getIncrementId());
                    $this->closeAuthorization($order, $payment, $responseJson);
                } else {
                    $this->logger->warning('Captura sin aprobar para la orden -> ' . $order->getIncrementId());
                }
            }
        }
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function cancelOrder($order)
    {
        $this->logger->info('Payment rejected for order #' . $order->getIncrementId());
        $order->cancel();
        $order->addStatusHistoryComment(__('Order canceled due to rejected payment.'), false);
        $this->orderRepository->save($order);
        $this->logger->info('Order #' . $order->getIncrementId() . ' cancelled.');
    }

    /**
     * Get order to process
     * @param array $states
     * @param int $storeId
     * @param int|null $hours
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    protected function getOrders($states, $storeId, $hours = null)
    {
        $collection = $this->orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->join(['payment' => 'sales_order_payment'], 'main_table.entity_id=payment.parent_id', ['payment_method' => 'payment.method'])
            ->addFieldToFilter('state', ["in" => $states])
            ->addFieldToFilter('payment.method', 'argenmagento')
            ->addFieldToFilter('store_id', $storeId);

        if ($hours !== null) {
            $fromDate = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
            $collection->addFieldToFilter('created_at', ['lteq' => $fromDate]);
        }

        return $collection;
    }

    /**
     * @param
     * @param
     * @param
     * @return void
     */
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
            try {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setTransactionId($transactionId)
                    ->addComment('Invoice created after capture.')
                    ->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                $invoice->register()->pay();
                $invoice->save();

                $transactionSave = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();

                $order->addStatusHistoryComment(__('Invoice #%1.', $invoice->getId()))
                    ->setIsCustomerNotified(true);
            } catch (LocalizedException $exception) {
                $this->logger->error(
                    sprintf(
                        'Capture invoice creation failed for order #%s: %s',
                        $order->getIncrementId(),
                        $exception->getMessage()
                    )
                );
            }
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addStatusHistoryComment(__('Payment captured successfully.'));
        $order->save();
        $payment->save();

        $this->orderSender->send($order);
    }
}
