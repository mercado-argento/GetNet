<?php
namespace GetnetArg\Payments\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use GetnetArg\Payments\Model\ClientWS;
use GetnetArg\Payments\Model\Cart as CartModel;
use GetnetArg\Payments\Model\OrderHelper;


class Index extends Template
{
    const CLIENT_ID = 'payment/argenmagento/client_id';
    
    const SECRET_ID = 'payment/argenmagento/secret_id';
    
    const TEST_ENV = 'payment/argenmagento/test_environment';
    
    private $checkoutSession;

    protected Http $request;

    protected $_checkoutSession;

    protected $_customerSession;

    protected $urlBuilder;

    private ScopeConfigInterface $scopeConfig;

    private ClientWS $clientWs;

    private OrderHelper $orderHelper;

    private CartModel $cartModel;

    private OrderCollectionFactory $orderCollectionFactory;

    private OrderRepositoryInterface $orderRepository;

    /**
     * @param Template\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\UrlInterface $urlBuilder,
        Http $request,
        ScopeConfigInterface $scopeConfig,
        ClientWS $clientWs,
        OrderHelper $orderHelper,
        CartModel $cartModel,
        OrderCollectionFactory $orderCollectionFactory,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    )
    {
        parent::__construct($context, $data);
        $this->_checkoutSession = $checkoutSession;
        $this->_customerSession = $customerSession;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
        $this->request = $request;
        $this->scopeConfig = $scopeConfig;
        $this->clientWs = $clientWs;
        $this->orderHelper = $orderHelper;
        $this->cartModel = $cartModel;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
    }


    /**
     * @return string
     */
    public function getUrlSDK(){

        $test = $this->scopeConfig->getValue(self::TEST_ENV, ScopeInterface::SCOPE_STORE);
        
//        $this->logger->debug('TEST ENV --> ' . $test);
        
             if($test == '1'){
                    $url = 'https://www.pre.globalgetnet.com/digital-checkout/loader.js';
               
             } else { //produccion
                    $url = 'https://www.globalgetnet.com/digital-checkout/loader.js';
             }
        
        return $url;
    }
    

    
    /**
     * @return string
     */
    public function getScript()
    {
        $this->logger->debug('------------------Init Script-------------------');

        $clienId = $this->scopeConfig->getValue(self::CLIENT_ID, ScopeInterface::SCOPE_STORE);

        $secret = $this->scopeConfig->getValue(self::SECRET_ID, ScopeInterface::SCOPE_STORE);

        $testEnv = $this->scopeConfig->getValue(self::TEST_ENV, ScopeInterface::SCOPE_STORE);

        /////////GET TOKEN /////////
        $token = $this->clientWs->getToken($clienId, $secret, $testEnv);
        
        $Client_id = $this->request->getParam('prx');
        $email = base64_decode($Client_id);
            $this->logger->debug('ID Cliente --> ' .$email);


        $status = $this->getOrderStatus($email);

        if($status == 'processing'){
                $baseURL = $this->urlBuilder->getBaseUrl();
                $urlSuccess = $baseURL . 'checkout/onepage/success';
            
                $script = 'window.location.replace("'.$urlSuccess.'");';
               
            return $script;
        }


        if($token == 'invalido'){
            //enable cartItems
            $this->cartModel->getCartItems($email);
            $script = 'alert("No se pudo generar la intención de pago, por favor intente de nuevo. Si el problema persiste, contacte con un ejecutivo Getnet.");
                            window.history.go(-2);';
            return $script;
        }


        /////////GET BODY /////////
        $bodyRequest = $this->orderHelper->getBodyOrderRequest($email);

        
        
        
        /////////GET PAYMENT INTENT ID /////////
        $payIntentId = $this->clientWs->getPaymentIntentID($token, $bodyRequest, $testEnv);
        
         $this->logger->debug('$payIntentId --> ' .$payIntentId);
        
        if($payIntentId == 'error') {
            //enable cartItems
            $this->cartModel->getCartItems($email);
            $script = 'alert("Error al generar la intención de pago");
                        window.history.go(-2);';

        } else if($payIntentId == 'currency_error') {
            //enable cartItems
            $this->cartModel->getCartItems($email);
            $script = 'alert("Tipo de moneda no soportada");
                            window.history.go(-2);';
            
        }  else {
            $script = 'const config = {
                          "paymentIntentId": "'.$payIntentId.'",
                          "checkoutType": "iframe",
                               "accessToken": "Bearer '.$token.'"
                            };';
        }
                
//        $this->logger->debug($script);
        
        return $script;
    }


    /*
     * *
     */
    public function getURLreturn()
    {
        $baseURL = $this->urlBuilder->getBaseUrl();
            $this->logger->debug($baseURL);
            
        $UrlCart = $baseURL . 'argenmagento/response/cart';    

        return $UrlCart;
    }    
    
    



    /*
     * *
     */
    public function getOrderStatus($email)
    {
        $orderDatamodel = $this->orderCollectionFactory->create();
        $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();

        $order = $this->orderRepository->get($orderDatamodel->getId());

        $status = $order->getStatus();

        return $status;
    }   
}