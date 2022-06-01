<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet 
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Novalnet\Services\PaymentService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository; 
use Novalnet\Services\TransactionService;

/**
 * Class PaymentController
 *
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var SessionStorageService
     */
    private $sessionStorage;

    /**
     * @var basket
     */
    private $basketRepository;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;
    
    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var Twig
     */
    private $twig;
    
    /**
     * @var ConfigRepository
     */
    private $config;
    
    /**
     * @var transaction
     */
    private $transaction; 

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param SessionStorageService $sessionStorage
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentService $paymentService
     * @param TransactionService $tranactionService
     * @param Twig $twig
     */
    public function __construct(  Request $request,
                                  Response $response,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  AddressRepositoryContract $addressRepository,
                                  FrontendSessionStorageFactoryContract $sessionStorage,
                                  BasketRepositoryContract $basketRepository,             
                                  PaymentService $paymentService,
                                  TransactionService $tranactionService,
                                  Twig $twig
                                )
    {

        $this->request         = $request;
        $this->response        = $response;
        $this->paymentHelper   = $paymentHelper;
        $this->sessionStorage  = $sessionStorage;
        $this->addressRepository = $addressRepository;
        $this->basketRepository  = $basketRepository;
        $this->paymentService  = $paymentService;
        $this->twig            = $twig;
        $this->config          = $config;
        $this->transaction     = $tranactionService;
    }

    /**
     * Novalnet redirects to this page if the payment was executed successfully
     *
     */
    public function paymentResponse() {
        $responseData = $this->request->all();
        $isPaymentSuccess = isset($responseData['status']) && in_array($responseData['status'], ['90','100']);
        $notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
        if ($isPaymentSuccess) {
            $this->paymentService->pushNotification($notificationMessage, 'success', 100);
        } else {
            $this->paymentService->pushNotification($notificationMessage, 'error', 100);    
        }
        
        if($responseData['payment_type'] != 'APPLEPAY') {
            $responseData['test_mode'] = $this->paymentHelper->decodeData($responseData['test_mode'], $responseData['uniqid']);
            $responseData['amount']    = $this->paymentHelper->decodeData($responseData['amount'], $responseData['uniqid']) / 100;
        }
        $sessionPaymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentDataUpdated');
        $paymentRequestData = !empty($sessionPaymentRequestData) ? array_merge($sessionPaymentRequestData, $responseData) : $responseData;
        
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $paymentRequestData);
        $this->paymentService->validateResponse();
       
        return $this->response->redirectTo(strtolower($paymentRequestData['lang']) . '/confirmation');
    }

    /**
     * Process the Form payment
     *
     */
    public function processPayment()
    {
        $requestData = $this->request->all();
        $notificationMessage = $this->paymentHelper->getNovalnetStatusText($requestData);
        $basket = $this->basketRepository->load();  
        $billingAddressId = !empty($basket->customerInvoiceAddressId) ? $basket->customerInvoiceAddressId : $requestData['nn_billing_addressid'];
        $shippingAddressId = !empty($basket->customerShippingAddressId) ? $basket->customerShippingAddressId : $requestData['nn_shipping_addressid'];
        $address = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
        foreach ($address->options as $option) {
            if ($option->typeId == 9) {
            $dob = $option->value;
            }
       }
       
        $doRedirect = false;
        if($requestData['paymentKey'] == 'NOVALNET_CC' && !empty($requestData['nn_cc3d_redirect']) ) {
              $doRedirect = true;
        }
        // Get order amount from the post values
        $orderAmount = !empty($requestData['nn_orderamount']) ? $requestData['nn_orderamount'] : 0;
        
        // Build the request from the shop order Id for the order amount if the reint feature used else load the basket
        if (!empty($orderAmount)) {
            $serverRequestData = $this->paymentService->getRequestParameters($this->basketRepository->load(), $requestData['paymentKey'], $doRedirect, $orderAmount, $billingAddressId, $shippingAddressId);
        } else {
            $serverRequestData = $this->paymentService->getRequestParameters($this->basketRepository->load(), $requestData['paymentKey'], $doRedirect);
        }
        
        if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name'])) {
        $notificationMessage = $this->paymentHelper->getTranslatedText('nn_first_last_name_error');
                $this->paymentService->pushNotification($notificationMessage, 'error', 100);
                return $this->response->redirectTo('checkout');
        }
        
        $guarantee_payments = [ 'NOVALNET_SEPA', 'NOVALNET_INVOICE'];        
        if($requestData['paymentKey'] == 'NOVALNET_CC') {
            $serverRequestData['data']['pan_hash'] = $requestData['nn_pan_hash'];
            $serverRequestData['data']['unique_id'] = $requestData['nn_unique_id'];
        $this->sessionStorage->getPlugin()->setValue('nnDoRedirect', $requestData['nn_cc3d_redirect']);
            if(!empty($requestData['nn_cc3d_redirect']) )
            {
                $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                $this->sessionStorage->getPlugin()->setValue('nnPaymentUrl',$serverRequestData['url']);
                $this->paymentService->pushNotification($notificationMessage, 'success', 100);
                if(!empty($requestData['nn_reinit'])) {
                    return $this->response->redirectTo(strtolower($serverRequestData['data']['lang']) . '/payment/novalnet/redirectPayment');
                } else {
                    return $this->response->redirectTo(strtolower($serverRequestData['data']['lang']) . '/place-order');
                }
                
            }
        }
        // Handles Guarantee and Normal Payment
        else if( in_array( $requestData['paymentKey'], $guarantee_payments ) ) 
        {   
            // Mandatory Params For Novalnet SEPA
            if ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
                    $serverRequestData['data']['bank_account_holder'] = $requestData['nn_sepa_cardholder'];
                    $serverRequestData['data']['iban'] = $requestData['nn_sepa_iban'];                  
            }            
            
            
            if (!empty($basket->customerInvoiceAddressId)) {
                $guranteeStatus = $this->paymentService->getGuaranteeStatus($this->basketRepository->load(), $requestData['paymentKey'], $orderAmount);
            } else {
                $guranteeStatus = $this->paymentService->getGuaranteeStatus($this->basketRepository->load(), $requestData['paymentKey'], $orderAmount, $billingAddressId, $shippingAddressId);
            }
            
            if($guranteeStatus != 'normal' && $guranteeStatus != 'guarantee')
            {
                $this->paymentService->pushNotification($guranteeStatus, 'error', 100);
                return $this->response->redirectTo(strtolower($serverRequestData['data']['lang']) . '/confirmation');
            }
            
            if('guarantee' == $guranteeStatus)
            {    
                $birthday = sprintf('%4d-%02d-%02d',$requestData['nn_guarantee_year'],$requestData['nn_guarantee_month'],$requestData['nn_guarantee_date']);
                $birthday = !empty($dob)? $dob :  $birthday;
                
                if( time() < strtotime('+18 years', strtotime($birthday)) && empty($address->companyName))
                {
                    $notificationMessage = $this->paymentHelper->getTranslatedText('dobinvalid');
                    $this->paymentService->pushNotification($notificationMessage, 'error', 100);
                    return $this->response->redirectTo('checkout');
                }

                    // Guarantee Params Formation 
                    if( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
                        $serverRequestData['data']['payment_type'] = 'GUARANTEED_DIRECT_DEBIT_SEPA';
                        $serverRequestData['data']['key']          = '40';
                        $serverRequestData['data']['birth_date']   =  $birthday;
                    } else {                        
                        $serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
                        $serverRequestData['data']['key']          = '41';
                        $serverRequestData['data']['birth_date']   =  $birthday;
                    }
            }
        }
        if (!empty ($address->companyName) ) {
            unset($serverRequestData['data']['birth_date']);
        }
        
        $serverRequestData['data']['amount'] = !empty($serverRequestData['data']['amount']) ? $serverRequestData['data']['amount'] : $requestData['nn_orderamount'];
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData); 
        if(!empty($requestData['nn_reinit'])) {
            $this->paymentService->paymentCalltoNovalnetServer();
            $this->paymentService->validateResponse();
            return $this->response->redirectTo(strtolower($serverRequestData['data']['lang']) . '/confirmation');
            
        } else {
            return $this->response->redirectTo(strtolower($serverRequestData['data']['lang']) . '/place-order');
        }
    }

    /**
     * Process the redirect payment
     *
     */
    public function redirectPayment()
    {        
        $paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $orderNo = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
        $paymentRequestData['order_no'] = $orderNo;
        $paymentUrl = $this->sessionStorage->getPlugin()->getValue('nnPaymentUrl');
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
        $this->sessionStorage->getPlugin()->setValue('nnOrderNo', null);
        $sendPaymentRequest = $this->paymentService->checkPaymentRequestSend($paymentRequestData['order_no']);
        $tid_status = $this->paymentHelper->getNovalnetTxStatus($paymentRequestData['order_no']);
        if(!empty($paymentRequestData['order_no']) && ( ($sendPaymentRequest == true && empty($tid_status)) || (!empty($tid_status) && !in_array($tid_status, [75, 85, 86, 90, 91, 98, 99, 100, 103])) ) ) {
            $this->paymentService->insertRequestDetailsForReinit($paymentRequestData);
            $this->sessionStorage->getPlugin()->setValue('nnPaymentDataUpdated', $paymentRequestData);  
            return $this->twig->render('Novalnet::NovalnetPaymentRedirectForm', [
                                                               'formData'     => $paymentRequestData,
                                                                'nnPaymentUrl' => $paymentUrl
                                   ]);
        } else {            
            return $this->response->redirectTo(strtolower($paymentRequestData['lang']) . '/confirmation');
          }
    }
    
    /**
     * Process the direct payment reinitialization
     *
     */
    public function changePaymentMethod() 
    {
        $paymentKey = $this->sessionStorage->getPlugin()->getValue('paymentKey');
        $isGuarantee = $this->sessionStorage->getPlugin()->getValue('nnProcessb2bGuarantee');
        $this->sessionStorage->getPlugin()->setValue('nnProcessb2bGuarantee', null);
        
        if (in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CASHPAYMENT', 'NOVALNET_MULTIBANCO'])) {
            if($paymentKey == 'NOVALNET_INVOICE' && $isGuarantee == 'guarantee') {
                $this->sessionStorage->getPlugin()->setValue('nnProceedGuarantee', $isGuarantee);
            }
            $this->paymentService->paymentCalltoNovalnetServer();
            $this->paymentService->validateResponse();
        }
    }
   
}
