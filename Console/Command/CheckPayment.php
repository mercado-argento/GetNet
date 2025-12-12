<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace GetnetArg\Payments\Console\Command;

use GetnetArg\Payments\Model\ClientWSFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CheckPayment extends Command
{

    private const PAYMENT_ID = "id";
    private const CLIENT_ID = 'payment/argenmagento/client_id';
    private const SECRET_ID = 'payment/argenmagento/secret_id';
    private const TEST_ENV = 'payment/argenmagento/test_environment';

    /**
     * @var ClientWSFactory
     */
    private ClientWSFactory $clientWSFactory;
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param ClientWSFactory $clientWSFactory
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ClientWSFactory $clientWSFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->clientWSFactory = $clientWSFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $paymentId = $input->getOption(self::PAYMENT_ID);
        $paymentResponseData = $this->getPaymentInformation($paymentId);
        $output->writeln('<comment>Dump de respuesta:</comment>');
        $output->writeln(print_r($paymentResponseData, true));
        return Command::SUCCESS;
    }

    /**
     * Return payment information
     * @param string $paymentId
     * @return array
     */
    private function getPaymentInformation($paymentId)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $clientId = $this->scopeConfig->getValue(self::CLIENT_ID, $storeScope);
        $secretId = $this->scopeConfig->getValue(self::SECRET_ID, $storeScope);
        $testEnv = $this->scopeConfig->getValue(self::TEST_ENV, $storeScope);
        $clientWs = $this->clientWSFactory->create();
        $token = $clientWs->getToken($clientId, $secretId, $testEnv);
        $response = $clientWs->getPaymentInfo($token, $testEnv, $paymentId);

        return json_decode($response, true) ?: [];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName("getnet:checkpayment");
        $this->setDescription("Información de Pago realizado con Getnet");

        $this->addOption(
            self::PAYMENT_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'Payment Id'
        );

        parent::configure();
    }
}
