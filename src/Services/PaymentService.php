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

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Payment\History\Contracts\PaymentHistoryRepositoryContract;
use Plenty\Modules\Payment\History\Models\PaymentHistory as PaymentHistoryModel;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;
    
    /**
     * @var PaymentHistoryRepositoryContract
     */
    private $paymentHistoryRepo;
    
   /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var ConfigRepository
     */
    private $config;
   
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var TransactionLogData
     */
    private $transactionLogData;
    
    private $redirectPayment = ['NOVALNET_SOFORT', 'NOVALNET_PAYPAL', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY', 'NOVALNET_APPLEPAY', 'NOVALNET_POSTFINANCE_CARD', 'NOVALNET_POSTFINANCE_EFINANCE', 'NOVALNET_BANCONTACT', 'NOVALNET_ONLINE_BANK_TRANSFER'];

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param TransactionService $transactionLogData
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper,
                                PaymentHistoryRepositoryContract $paymentHistoryRepo,
                                PaymentRepositoryContract $paymentRepository,
                                TransactionService $transactionLogData)
    {
        $this->config                   = $config;
        $this->sessionStorage           = $sessionStorage;
        $this->addressRepository        = $addressRepository;
        $this->countryRepository        = $countryRepository;
        $this->webstoreHelper           = $webstoreHelper;
        $this->paymentHistoryRepo       = $paymentHistoryRepo;
        $this->paymentRepository        = $paymentRepository;
        $this->paymentHelper            = $paymentHelper;
        $this->transactionLogData       = $transactionLogData;
    }
    
    /**
     * Push notification
     *
     */
    public function pushNotification($message, $type, $code = 0) {
        
    $notifications = json_decode($this->sessionStorage->getPlugin()->getValue('notifications'), true);  
        
    $notification = [
            'message'       => $message,
            'code'          => $code,
            'stackTrace'    => []
           ];
        
    $lastNotification = $notifications[$type];

        if( !is_null($lastNotification) )
    {
            $notification['stackTrace'] = $lastNotification['stackTrace'];
            $lastNotification['stackTrace'] = [];
            array_push( $notification['stackTrace'], $lastNotification );
        }
        
        $notifications[$type] = $notification;
        $this->sessionStorage->getPlugin()->setValue('notifications', json_encode($notifications));
    }
    /**
     * Validate  the response data.
     *
     */
    public function validateResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $lang = strtolower((string)$nnPaymentData['lang']);
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
       
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        
        if($nnPaymentData['payment_id'] == '59' && !empty($nnPaymentData['cp_checkout_token']) && $nnPaymentData['tid_status'] == '100')
        {
        $this->sessionStorage->getPlugin()->setValue('novalnet_checkout_token', $nnPaymentData['cp_checkout_token']);
        $this->sessionStorage->getPlugin()->setValue('novalnet_checkout_url', $this->getBarzhalenTestMode($nnPaymentData['test_mode']));        
        }
        
        $additional_info = $this->additionalInfo($nnPaymentData);

        $transactionData = [
            'amount'           => ($nnPaymentData['payment_method'] == 'novalnet_applepay' && $nnPaymentData['status'] != 100) ? $nnPaymentData['amount'] : $nnPaymentData['amount'] * 100,
            'callback_amount'  => ($nnPaymentData['payment_method'] == 'novalnet_applepay' && $nnPaymentData['status'] != 100) ? $nnPaymentData['amount'] : $nnPaymentData['amount'] * 100,
            'tid'              => !empty($nnPaymentData['tid']) ? $nnPaymentData['tid'] : '0',
            'ref_tid'          => !empty($nnPaymentData['tid']) ? $nnPaymentData['tid'] : '0',
            'payment_name'     => $nnPaymentData['payment_method'],
            'order_no'         => $nnPaymentData['order_no'],
            'additional_info'      => !empty($additional_info) ? json_encode($additional_info) : '0',
        ];
       
        if(in_array($nnPaymentData['payment_id'], ['27', '59', '73']) || (in_array($nnPaymentData['tid_status'], ['85','86','90'])) || $nnPaymentData['status'] != '100')
            $transactionData['callback_amount'] = 0;    
        
       $this->transactionLogData->saveTransaction($transactionData);
       
       $this->executePayment($nnPaymentData);

     }
     
    /**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData 
     * @param bool $callbackfailure
     * 
     * @return array
     */
    public function executePayment($requestData, $callbackfailure = false)
    {
        try {
            if(!$callbackfailure &&  in_array($requestData['status'], ['100', '90'])) {
                if(in_array($requestData['tid_status'], ['75', '85', '86', '90', '91', '98', '99']) || in_array($requestData['payment_id'], ['27', '59', '73']) && $requestData['tid_status'] == '100') {
                    $requestData['paid_amount'] = 0;
                } else {
                    $requestData['paid_amount'] = ($requestData['tid_status'] == '100') ? $requestData['amount'] : '0';
                }
            } else {
                $requestData['type'] = 'cancel';
                $requestData['paid_amount'] = '0';
            }
        
            $this->paymentHelper->createPlentyPayment($requestData);
           
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($requestData)
            ];
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build Invoice and Prepayment transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getInvoicePrepaymentComments($requestData)
    {     
        $comments = '';
        if($requestData['tid_status'] == '100' && !empty($requestData['due_date']) ) {
           $comments .= PHP_EOL . PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transfer_amount_duedate_text'), $requestData['amount'], $requestData['currency'] , date('Y/m/d', (int)strtotime($requestData['due_date'])) );
        } else {
           $comments .= PHP_EOL . PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transfer_amount_text'), $requestData['amount'], $requestData['currency'] );    
        }
        
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bank') . $requestData['invoice_bankname']. ' ' . $requestData['invoice_bankplace'];

        $comments .= PHP_EOL . PHP_EOL .$this->paymentHelper->getTranslatedText('any_one_reference_text');
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference1'). ' ' . 'TID '. $requestData['tid'] . PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference2') . ' ' . ('BNR-' . $requestData['product_id'] . '-' . $requestData['order_no']) . PHP_EOL;
        $comments .= PHP_EOL;
        return $comments;
    }
    
    /**
     * Build Multibanco transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getMultibancoReferenceInformation($requestData)
    {
        $multibancoComments  = PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('multibanco_reference_text'), $requestData['amount'], $requestData['currency'] );
        $multibancoComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('multibanco_reference_one') . $requestData['multibanco_partner_payment_ref'];
        $multibancoComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('multibanco_reference_two') . $requestData['multibanco_service_supplier_id'];
        
        return $multibancoComments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param Basket $basket
     * @param PaymentKey $paymentKey
     * @param bool $doRedirect
     * @param int $orderAmount
     * @param int $billingInvoiceAddrId
     * @param int $shippingInvoiceAddrId
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket, $paymentKey = '', $doRedirect = false, $orderAmount = 0, $billingInvoiceAddrId = 0, $shippingInvoiceAddrId = 0)
    {
        
     /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $billingAddressId = !empty($basket->customerInvoiceAddressId) ? $basket->customerInvoiceAddressId : $billingInvoiceAddrId;
        $shippingAddressId = !empty($basket->customerShippingAddressId) ? $basket->customerShippingAddressId : $shippingInvoiceAddrId;
        $address = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
        $shippingAddress = $address;
        if(!empty($shippingAddressId)){
            $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $shippingAddressId);
        }
        
        $customerName = $this->getCustomerName($address);
    
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = 'Novalnet.' . $paymentKeyLower . '_test_mode';
        
        $paymentRequestData = [
            'vendor'             => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
            'auth_code'          => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
            'product'            => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
            'tariff'             => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
            'test_mode'          => (int)($this->config->get($testModeKey) == 'true'),
            'first_name'         => !empty($address->firstName) ? $address->firstName : $customerName['firstName'],
            'last_name'          => !empty($address->lastName) ? $address->lastName : $customerName['lastName'],
            'email'              => $address->email,
            'gender'             => 'u',
            'city'               => $address->town,
            'street'             => $address->street,
            'country_code'       => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'zip'                => $address->postalCode,
            'customer_no'        => ($customerId) ? $customerId : 'guest',
            'lang'               => strtoupper($this->sessionStorage->getLocaleSettings()->language),
            'amount'             => !empty($orderAmount) ? $orderAmount : $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'           => $basket->currency,
            'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
            'system_ip'          => $this->paymentHelper->getServerAddress(),
            'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
            'system_name'        => 'Plentymarkets',
            'system_version'     => NovalnetConstants::PLUGIN_VERSION,
            'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback/',
            'key'                => $this->getkeyByPaymentKey($paymentKey),
            'payment_type'       => $this->getTypeByPaymentKey($paymentKey)
        ];

        if(!empty($address->houseNumber))
        {
            $paymentRequestData['house_no'] = $address->houseNumber;
        }
        else
        {
            $paymentRequestData['search_in_street'] = '1';
        }

        if(!empty($address->companyName)) {
            $paymentRequestData['company'] = $address->companyName;
        } elseif(!empty($shippingAddress->companyName)) {
            $paymentRequestData['company'] = $shippingAddress->companyName;
        }

        if(!empty($address->phone)) {
            $paymentRequestData['tel'] = $address->phone;
        }
        
        if($paymentRequestData['key'] == '102') {
            $this->getSeamlessFormParams($paymentRequestData);
        }
        
        $url = $this->getPaymentData($paymentKey, $paymentRequestData, $doRedirect);
        
        return [
            'data' => $paymentRequestData,
            'url'  => $url
        ];
    }
    
    
     /**
     * Get customer name if the salutation as Person
     *
     * @param object $address
     *
     * @return array
     */
    public function getCustomerName($address) 
    {
        foreach ($address->options as $option) {
            if ($option->typeId == 12) {
                    $name = $option->value;
            }
        }
        $customerName = explode(' ', $name);
        $firstname = $customerName[0];
            if( count( $customerName ) > 1 ) {
                unset($customerName[0]);
                $lastname = implode(' ', $customerName);
            } else {
                $lastname = $firstname;
            }
        $firstName = empty ($firstname) ? $lastname : $firstname;
        $lastName = empty ($lastname) ? $firstname : $lastname;
        return ['firstName' => $firstName, 'lastName' => $lastName];
    }

    /**
     * Get payment related param
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     * @param bool $doRedirect
     */
    public function getPaymentData($paymentKey, &$paymentRequestData, $doRedirect )
    {
        $url = $this->getpaymentUrl($paymentKey);
        if(in_array($paymentKey, ['NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_PAYPAL', 'NOVALNET_INVOICE', 'NOVALNET_APPLEPAY'])) {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_on_hold');
                $onHoldAuthorize = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_payment_action');
        if((is_numeric($onHoldLimit) && $paymentRequestData['amount'] >= $onHoldLimit && $onHoldAuthorize == 'true') || ($onHoldAuthorize == 'true' && empty($onHoldLimit))) {
            $paymentRequestData['on_hold'] = '1';
        }
        if($paymentKey == 'NOVALNET_CC') {
                    if($this->config->get('Novalnet.novalnet_cc_enforce') == 'true') {
                        $paymentRequestData['enforce_3d'] = '1';
                    }
            if($doRedirect == true) {
             $url = NovalnetConstants::CC3D_PAYMENT_URL; 
            }
        } else if($paymentKey == 'NOVALNET_SEPA') {
                    $dueDate = $this->paymentHelper->getNovalnetConfig('novalnet_sepa_due_date');
                    if(is_numeric($dueDate) && $dueDate >= 2 && $dueDate <= 14) {
                        $paymentRequestData['sepa_due_date'] = $this->paymentHelper->dateFormatter($dueDate);
                    }
                    
        } else if($paymentKey == 'NOVALNET_INVOICE') {
                    $paymentRequestData['invoice_type'] = 'INVOICE';
                    $invoiceDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_due_date');
                    if(is_numeric($invoiceDueDate)) {
                        $paymentRequestData['due_date'] = $this->paymentHelper->dateFormatter($invoiceDueDate);
                    }
        }
        } else if($paymentKey == 'NOVALNET_PREPAYMENT') {
            $paymentRequestData['invoice_type'] = 'PREPAYMENT';
        $prepaymentDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_prepayment_due_date');
            if(is_numeric($prepaymentDueDate) && $prepaymentDueDate >= 7 && $prepaymentDueDate <= 28) {
        $paymentRequestData['due_date'] = $this->paymentHelper->dateFormatter($prepaymentDueDate);
           }
        } else if($paymentKey == 'NOVALNET_CASHPAYMENT') {
        $cashpaymentDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_cashpayment_due_date');
        if(is_numeric($cashpaymentDueDate)) {
            $paymentRequestData['cp_due_date'] = $this->paymentHelper->dateFormatter($cashpaymentDueDate);
        }
        }

        if($this->isRedirectPayment($paymentKey, $doRedirect))
        {
        $paymentRequestData['uniqid'] = $this->paymentHelper->getUniqueId();
        $this->encodePaymentData($paymentRequestData);
        $paymentRequestData['implementation'] = 'ENC';
        $paymentRequestData['return_url'] = $paymentRequestData['error_return_url'] = $this->getReturnPageUrl();
        $paymentRequestData['return_method'] = $paymentRequestData['error_return_method'] = 'POST';
        if ($paymentKey != 'NOVALNET_CC') {
            $paymentRequestData['user_variable_0'] = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        }
         }
        
        return $url;
    }

    /**
     * Collecting the Credit Card for the initial authentication call to PSP
     *
     * @param object $basket
     * @param string $paymentKey
     * @param int $orderAmount
     * 
     * @return string
     */
    public function getCreditCardAuthenticationCallData(Basket $basket, $paymentKey, $orderAmount = 0, $billingInvoiceAddrId = 0, $shippingInvoiceAddrId = 0) {
        $billingAddressId = !empty($basket->customerInvoiceAddressId) ? $basket->customerInvoiceAddressId : $billingInvoiceAddrId;
        $shippingAddressId = !empty($basket->customerShippingAddressId) ? $basket->customerShippingAddressId : $shippingInvoiceAddrId;
        $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
        $shippingAddress = $billingAddress;
        if(!empty($shippingAddressId)){
            $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $shippingAddressId);
        }
        $customerName = $this->getCustomerName($billingAddress);
        
        /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);
        
        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $ccFormRequestParameters = [
            'client_key'    => trim($this->config->get('Novalnet.novalnet_client_key')),
        'enforce_3d'    => (int)($this->config->get('Novalnet.' . strtolower((string) $paymentKey) . '_enforce') == 'true'),
            'test_mode'     => (int)($this->config->get('Novalnet.' . strtolower((string) $paymentKey) . '_test_mode') == 'true'),
            'first_name'    => !empty($billingAddress->firstName) ? $billingAddress->firstName : $customerName['firstName'],
            'last_name'     => !empty($billingAddress->lastName) ? $billingAddress->lastName : $customerName['lastName'],
            'email'         => $billingAddress->email,
            'street'        => $billingAddress->street,
            'house_no'      => $billingAddress->houseNumber,
            'city'          => $billingAddress->town,
            'zip'           => $billingAddress->postalCode,
            'country_code'  => $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'),
            'amount'        => !empty($orderAmount) ? $orderAmount : $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'      => $basket->currency,
            'lang'          => strtoupper($this->sessionStorage->getLocaleSettings()->language)
        ];  
        $billingShippingDetails = $this->getBillingShippingDetails($billingAddress, $shippingAddress);
        if ($billingShippingDetails['billing'] == $billingShippingDetails['shipping']) {
            $ccFormRequestParameters['same_as_billing'] = 1;
        }
        return json_encode($ccFormRequestParameters);
    }
    
    /**
     * Form customer billing and shipping details
     *
     * @param object $billingAddress
     * @param object $shippingAddress
     *
     * @return array
     */
    public function getBillingShippingDetails($billingAddress, $shippingAddress) 
    {
        $billingShippingDetails = [];
        $billingShippingDetails['billing']     = [
                'street'       => $billingAddress->street,
                'house_no'     => $billingAddress->houseNumber,
                'city'         => $billingAddress->town,
                'zip'          => $billingAddress->postalCode,
                'country_code' => strtoupper($this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'))
            ];
         $billingShippingDetails['shipping']    = [
                'street'   => $shippingAddress->street,
                'house_no'     => $shippingAddress->houseNumber,
                'city'     => $shippingAddress->town,
                'zip' => $shippingAddress->postalCode,
                'country_code' => strtoupper($this->countryRepository->findIsoCode($shippingAddress->countryId, 'iso_code_2'))
            ];
        return $billingShippingDetails;
    }
    
    /**
     * Retrieves Credit Card form style set in payment configuration and texts present in language files
     *
     * @return string
     */
    public function getCcFormFields() 
    {
        $ccformFields = [];

        $styleConfiguration = array('novalnet_cc_standard_style_label', 'novalnet_cc_standard_style_field', 'novalnet_cc_standard_style_css');

        foreach ($styleConfiguration as $value) {
            $ccformFields[$value] = trim($this->config->get('Novalnet.' . $value));
        }

        $textFields = array( 'template_novalnet_cc_holder_Label', 'template_novalnet_cc_holder_input', 'template_novalnet_cc_number_label', 'template_novalnet_cc_number_input', 'template_novalnet_cc_expirydate_label', 'template_novalnet_cc_expirydate_input', 'template_novalnet_cc_cvc_label', 'template_novalnet_cc_cvc_input', 'template_novalnet_cc_error' );

        foreach ($textFields as $value) {
            $ccformFields[$value] = $this->paymentHelper->getCustomizedTranslatedText($value);
        }
        return json_encode($ccformFields);
    }
    
    /**
     * Check if the payment is redirection or not
     *
     * @param string $paymentKey
     * @param bool $doRedirect
     *
     */
    public function isRedirectPayment($paymentKey, $doRedirect) {
        return (bool) (in_array($paymentKey, $this->redirectPayment) || $doRedirect == true);
    }

    /**
     * Encode the server request parameters
     *
     * @param array
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
        }

        // Generate hash value
        $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
    }

    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    private function getReturnPageUrl()
    {   
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/' . $this->sessionStorage->getLocaleSettings()->language . '/payment/novalnet/paymentResponse/';
    }

    /**
    * Get the direct payment process controller URL to be handled
    *
    * @return string
    */
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/' . $this->sessionStorage->getLocaleSettings()->language . '/payment/novalnet/processPayment/';
    }

    /**
    * Get the redirect payment process controller URL to be handled
    *
    * @return string
    */
    public function getRedirectPaymentUrl()
    { 
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/' . $this->sessionStorage->getLocaleSettings()->language . '/payment/novalnet/redirectPayment/';
    }
    
    /**
    * Get the payment process URL by using plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getpaymentUrl($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_PREPAYMENT'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_CC'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_SEPA'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_CASHPAYMENT'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_MULTIBANCO'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_PAYPAL'=>NovalnetConstants::PAYPAL_PAYMENT_URL,
            'NOVALNET_IDEAL'=>NovalnetConstants::SOFORT_PAYMENT_URL,
            'NOVALNET_EPS'=>NovalnetConstants::GIROPAY_PAYMENT_URL,
            'NOVALNET_GIROPAY'=>NovalnetConstants::GIROPAY_PAYMENT_URL,
            'NOVALNET_PRZELEWY'=>NovalnetConstants::PRZELEWY_PAYMENT_URL,
            'NOVALNET_SOFORT'=>NovalnetConstants::SOFORT_PAYMENT_URL,
            'NOVALNET_APPLEPAY'=>NovalnetConstants::PAYGATE_URL,
            'NOVALNET_POSTFINANCE_CARD'=>NovalnetConstants::POSTFINANCE_PAYMENT_URL,
            'NOVALNET_POSTFINANCE_EFINANCE'=>NovalnetConstants::POSTFINANCE_PAYMENT_URL,
            'NOVALNET_BANCONTACT'=>NovalnetConstants::BANCONTACT_PAYMENT_URL,
            'NOVALNET_ONLINE_BANK_TRANSFER'=>NovalnetConstants::ONLINE_BANK_TRANSFER_PAYMENT_URL
        ];

        return $payment[$paymentKey];
    }

   /**
    * Get Barzahlen slip URL by using Testmode
    *
    * @param string $response
    * @return string
    */
    public function getBarzhalenTestMode($response)
    {
        $testmode = [
        '0'=>NovalnetConstants::BARZAHLEN_LIVEURL,
        '1'=>NovalnetConstants::BARZAHLEN_TESTURL

        ];

        return $testmode[$response];
    }

   /**
    * Get payment key by plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getkeyByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'27',
            'NOVALNET_PREPAYMENT'=>'27',
            'NOVALNET_CC'=>'6',
            'NOVALNET_SEPA'=>'37',
            'NOVALNET_CASHPAYMENT'=>'59',
            'NOVALNET_MULTIBANCO'=>'73',
            'NOVALNET_PAYPAL'=>'34',
            'NOVALNET_IDEAL'=>'49',
            'NOVALNET_EPS'=>'50',
            'NOVALNET_GIROPAY'=>'69',
            'NOVALNET_PRZELEWY'=>'78',
            'NOVALNET_SOFORT'=>'33',
            'NOVALNET_APPLEPAY'=>'102',
            'NOVALNET_POSTFINANCE_CARD'=>'87',
            'NOVALNET_POSTFINANCE_EFINANCE'=>'88',
            'NOVALNET_BANCONTACT'=>'44',
            'NOVALNET_ONLINE_BANK_TRANSFER'=>'113'
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get payment type by plenty payment Key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getTypeByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'INVOICE_START',
            'NOVALNET_PREPAYMENT'=>'INVOICE_START',
            'NOVALNET_CC'=>'CREDITCARD',
            'NOVALNET_SEPA'=>'DIRECT_DEBIT_SEPA',
            'NOVALNET_CASHPAYMENT'=>'CASHPAYMENT',
            'NOVALNET_MULTIBANCO'=>'MULTIBANCO',
            'NOVALNET_PAYPAL'=>'PAYPAL',
            'NOVALNET_IDEAL'=>'IDEAL',
            'NOVALNET_EPS'=>'EPS',
            'NOVALNET_GIROPAY'=>'GIROPAY',
            'NOVALNET_PRZELEWY'=>'PRZELEWY24',
            'NOVALNET_SOFORT'=>'ONLINE_TRANSFER',
            'NOVALNET_APPLEPAY'=>'APPLEPAY',
            'NOVALNET_POSTFINANCE_CARD'=>'POSTFINANCE_CARD',
            'NOVALNET_POSTFINANCE_EFINANCE'=>'POSTFINANCE',
            'NOVALNET_BANCONTACT'=>'BANCONTACT',
            'NOVALNET_ONLINE_BANK_TRANSFER'=>'ONLINE_BANK_TRANSFER'
        ];

        return $payment[$paymentKey];
    }
    
    /**
    * Get the Payment Guarantee status
    *
    * @param object $basket
    * @param string $paymentKey
    * @param int $orderAmount
    * @param int $billingInvoiceAddrId
    * @param int $shippingInvoiceAddrId
    * @return string
    */
    public function getGuaranteeStatus(Basket $basket, $paymentKey, $orderAmount = 0, $billingInvoiceAddrId = 0, $shippingInvoiceAddrId = 0)
    {
        try {
            if (! is_null($basket) && $basket instanceof Basket) { 
            // Get payment name in lowercase
            $paymentKeyLow = strtolower((string) $paymentKey);
            $guaranteePayment = $this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_active');

            if ($guaranteePayment == 'true') {
                // Get guarantee minimum amount value
                $minimumAmount = $this->paymentHelper->getNovalnetConfig($paymentKeyLow . '_guarantee_min_amount');
                $minimumAmount = ((preg_match('/^[0-9]*$/', $minimumAmount) && $minimumAmount >= '999')  ? $minimumAmount : '999');
                $amount        = !empty($orderAmount) ? $orderAmount : (sprintf('%0.2f', $basket->basketAmount) * 100);

                $billingAddressId = !empty($basket->customerInvoiceAddressId) ? $basket->customerInvoiceAddressId : $billingInvoiceAddrId;
                $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
                $customerBillingIsoCode = strtoupper($this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'));

                $shippingAddressId = !empty($basket->customerShippingAddressId) ? $basket->customerShippingAddressId : $shippingInvoiceAddrId;
                
                // Get the company value from the billing address
                $billingAddressCompany = !empty($billingAddress->companyName) ? $billingAddress->companyName : $billingAddress->name1;

                $addressValidation = false;
                if(!empty($shippingAddressId))
                {
                    $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $shippingAddressId);
                    $customerShippingIsoCode = strtoupper($this->countryRepository->findIsoCode($shippingAddress->countryId, 'iso_code_2'));

                    // Billing address
                    $billingAddress = ['street_address' => (($billingAddress->street) ? $billingAddress->street : $billingAddress->address1),
                                       'city'           => $billingAddress->town,
                                       'postcode'       => $billingAddress->postalCode,
                                       'country'        => $customerBillingIsoCode,
                                      ];
                    // Shipping address
                    $shippingAddress = ['street_address' => (($shippingAddress->street) ? $shippingAddress->street : $shippingAddress->address1),
                                        'city'           => $shippingAddress->town,
                                        'postcode'       => $shippingAddress->postalCode,
                                        'country'        => $customerShippingIsoCode,
                                       ];

                 }
                 else
                 {
                     $addressValidation = true;
                 }
                 
                 // Check if it is B2B customer from the European country
                 $b2bEuropeanCustomer = false;
                 if(!empty($billingAddressCompany)) {
                    $europeanUnionCountryCodes =  [
                                                    'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
                                                    'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
                                                    'PT', 'RO', 'SE', 'SI', 'SK', 'UK', 'CH'
                                                  ];
                    if(in_array($customerBillingIsoCode, $europeanUnionCountryCodes)) {
                        $b2bEuropeanCustomer = true;
                    } else {
                        $b2bEuropeanCustomer = false;     
                    }
                 }
            
                // Check guarantee payment
                if ((((int) $amount >= (int) $minimumAmount && (in_array(
                    $customerBillingIsoCode,
                    [
                     'DE',
                     'AT',
                     'CH',
                    ]
                ) || $b2bEuropeanCustomer) && $basket->currency == 'EUR' && ($addressValidation || ($billingAddress === $shippingAddress)))
                )) {
                    $processingType = 'guarantee';
                } elseif ($this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_force_active') == 'true') {   
                    $processingType = 'normal';
                } else {
                    if ( (! in_array( $customerBillingIsoCode, array( 'AT', 'DE', 'CH' ), true ) ) || $b2bEuropeanCustomer ) {
                        $processingType = $this->paymentHelper->getTranslatedText('guarantee_country_error');                   
                    } elseif ( $basket->currency !== 'EUR' ) {
                        $processingType = $this->paymentHelper->getTranslatedText('guarantee_currency_error');                  
                    } elseif ( ! empty( array_diff( $billingAddress, $shippingAddress ) ) ) {
                        $processingType = $this->paymentHelper->getTranslatedText('guarantee_address_error');                   
                    } elseif ( (int) $amount < (int) $minimumAmount ) {
                        $processingType = $this->paymentHelper->getTranslatedText('guarantee_minimum_amount_error'). ' ' . $minimumAmount/100 . ' ' . 'EUR)';                   
                    }
                }
                return $processingType;
            }//end if
                return 'normal';
            }
        } catch(\Exception $e) {
            return false;
        }
    }
    
    /**
     * Execute capture and void process
     *
     * @param object $order
     * @param object $paymentDetails
     * @param int $tid
     * @param int $key
     * @param bool $capture
     * @return none
     */
    public function doCaptureVoid($order, $paymentDetails, $tid, $key, $invoiceDetails, $capture=false) 
    {
        
        try {
        $paymentRequestData = [
            'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
            'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
            'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
            'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
            'key'            => $key, 
            'edit_status'    => '1', 
            'tid'            => $tid, 
            'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
            'lang'           => 'de'  
             ];
        
        if($capture) {
        $paymentRequestData['status'] = '100';
        } else {
        $paymentRequestData['status'] = '103';
        }
        
         $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
         $responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');
            
         // Get the updated payment details
         foreach ($paymentDetails as $paymentDetail)
         {
            $paymentCurrency = $paymentDetail->currency;
            $paymentMopId = $paymentDetail->mopId;
         }
            
         // Get the proper order amount even the system currency and payment currency are differ
        if(count($order->amounts) > 1) {
           foreach($order->amounts as $amount) {
               if($paymentCurrency == $amount->currency) {
                   $invoiceAmount = (float) $amount->invoiceTotal;
               }
           }
         } else {
             $invoiceAmount = (float) $order->amounts[0]->invoiceTotal;
         }
            
         if ($responseData['status'] == '100') {
            $paymentData['currency']    = $paymentCurrency;
            $paymentData['paid_amount'] = (float) $invoiceAmount;
            $paymentData['tid']         = $tid;
            $paymentData['order_no']    = $order->id;
            $paymentData['type']        = $responseData['tid_status'] != '100' ? 'cancel' : 'credit';
            $paymentData['mop']         = $paymentMopId;
            $paymentData['tid_status']  = $responseData['tid_status'];
            
            // Update the transaction status in the database table
             $orderDetails = $this->transactionLogData->updateTransactionData('tid', $tid, $responseData['tid_status']);
            
            $transactionComments = '';
            if($responseData['tid_status'] == '100') {
                   if (in_array($key, ['27', '41'])) {
                     $bankDetails = json_decode($invoiceDetails);
                     $paymentData['invoice_bankname'] = $bankDetails->invoice_bankname;
                     $paymentData['invoice_bankplace'] = $bankDetails->invoice_bankplace;
                     $paymentData['invoice_iban'] = $bankDetails->invoice_iban;
                     $paymentData['invoice_bic'] = $bankDetails->invoice_bic;
                     $paymentData['due_date'] = !empty($responseData['due_date']) ? $responseData['due_date'] : $bankDetails->due_date;
                     $paymentData['payment_id'] = $key;
                 } 
               $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transaction_confirmation', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
           } else {
            $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transaction_cancel', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
        }
             if (($responseData['tid_status'] == '100' && $key == '27') || $responseData['tid_status'] != '100') {
             $paymentData['paid_amount'] = 0;
             }
             $paymentData['booking_text'] = $transactionComments;  
             
             if($responseData['tid_status'] == '103') {
                $this->paymentHelper->updatePayments($transactionComments, $responseData['tid_status'], $order->id);
            } else {
                $this->paymentHelper->updatePayments($tid, $responseData['tid_status'], $order->id);
                $this->paymentHelper->createPlentyPayment($paymentData);
        }
         } else {
               $error = $this->paymentHelper->getNovalnetStatusText($responseData);
               $this->getLogger(__METHOD__)->error('Novalnet::doCaptureVoid', $error);
               throw new \Exception('Novalnet doCaptureVoid not executed');
         }  
    } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::doCaptureVoid', $e);
      }
    }
    
    /**
     * Show payment for allowed countries
     *
     * @param string $allowed_country
     *
     * @return bool
     */
    public function allowedCountries(Basket $basket, $allowed_country) {
        $allowed_country = str_replace(' ', '', strtoupper($allowed_country));
        $allowed_country_array = explode(',', $allowed_country);    
        try {
            if (! is_null($basket) && $basket instanceof Basket && !empty($basket->customerInvoiceAddressId)) {         
                $billingAddressId = $basket->customerInvoiceAddressId;              
                $address = $this->addressRepository->findAddressById($billingAddressId);
                $country = $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2');
                if(!empty($address) && !empty($country) && in_array($country,$allowed_country_array)) {                             
                        return true;
                }
        
            }
        } catch(\Exception $e) {
            return false;
        }
        return false;
    }
    
    /**
     * Show payment for Minimum Order Amount
     *
     * @param object $basket
     * @param int $minimum_amount
     *
     * @return bool
     */
    public function getMinBasketAmount(Basket $basket, $minimum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($minimum_amount) && $minimum_amount<=$amount)    {
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Show payment for Maximum Order Amount
     *
     * @param object $basket
     * @param int $maximum_amount
     *
     * @return bool
     */
    public function getMaxBasketAmount(Basket $basket, $maximum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($maximum_amount) && $maximum_amount>=$amount)    {
            
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Get database values
     *
     * @param int $orderId
     *
     * @return array
     */
    public function getDatabaseValues($orderId) {
        $database = pluginApp(DataBase::class);
        $transaction_details = $database->query(TransactionLog::class)->where('orderNo', '=', $orderId)->get();
    if (!empty($transaction_details)) {
        foreach($transaction_details as $transaction_detail) {
             $end_transaction_detail = $transaction_detail;
    }
        //Typecasting object to array
        $transaction_details = (array) $end_transaction_detail;
        $transaction_details['order_no'] = $transaction_details['orderNo'];
        $transaction_details['amount'] = $transaction_details['amount'] / 100;
        if (!empty($transaction_details['additionalInfo'])) {
           //Decoding the json as array
            $transaction_details['additionalInfo'] = json_decode( $transaction_details['additionalInfo'], true );
            //Merging the array
            $transaction_details = array_merge($transaction_details, $transaction_details['additionalInfo']);
            //Unsetting the redundant key
            unset($transaction_details['additionalInfo']); 
        } else {
            unset($transaction_details['additionalInfo']);   
        }
        return $transaction_details;
        }
    }
    
    /**
     * Send the payment call
     *
     */
    public function paymentCalltoNovalnetServer () {
          
        $serverRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $serverRequestData['data']['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
        $guaranteePayment = $this->sessionStorage->getPlugin()->getValue('nnProceedGuarantee');
        $this->sessionStorage->getPlugin()->setValue('nnProceedGuarantee', null);
        if($guaranteePayment == 'guarantee') {
            $serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
            $serverRequestData['data']['key']          = '41';
        }
        $response = $this->paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
        $responseData = $this->paymentHelper->convertStringToArray($response['response'], '&');
        $notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
        $responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
        $isPaymentSuccess = isset($responseData['status']) && $responseData['status'] == '100';
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));
        
        if($isPaymentSuccess)
        {           
            if(isset($serverRequestData['data']['pan_hash']))
            {
                unset($serverRequestData['data']['pan_hash']);
            }

            $this->pushNotification($notificationMessage, 'success', 100);
            
        } else {
            $this->pushNotification($notificationMessage, 'error', 100);
        }
          
    }

    /**
     * Build the additional params
     *
     * @param array $nnPaymentData
     *
     * @return array
     */
    public function additionalInfo ($nnPaymentData) {
     $lang = strtolower((string)$nnPaymentData['lang']);
     $statusMessage = $this->paymentHelper->getNovalnetStatusText($nnPaymentData);
     $additional_info = [
        'currency' => $nnPaymentData['currency'],
        'product_id' => !empty($nnPaymentData['product_id']) ? $nnPaymentData['product_id'] : $nnPaymentData['product'] ,
        'payment_id' => !empty($nnPaymentData['payment_id']) ? $nnPaymentData['payment_id'] : $nnPaymentData['key'],
        'plugin_version' => $nnPaymentData['system_version'],
        'test_mode' => !empty($nnPaymentData['test_mode']) ? $this->paymentHelper->getTranslatedText('test_order',$lang) : '0',
        'invoice_type'      => !empty($nnPaymentData['invoice_type']) ? $nnPaymentData['invoice_type'] : '0' ,
        'invoice_account_holder' => !empty($nnPaymentData['invoice_account_holder']) ? $nnPaymentData['invoice_account_holder'] : '0',
        'tid_status' => !empty($nnPaymentData['tid_status']) ? $nnPaymentData['tid_status'] : $nnPaymentData['status'],
        'tx_status_msg' => !empty($statusMessage) ? $statusMessage : '',
        'multibanco_partner_payment_ref' => !empty($nnPaymentData['partner_payment_reference']) ? $nnPaymentData['partner_payment_reference'] : 0,
        'multibanco_service_supplier_id' => !empty($nnPaymentData['service_supplier_id']) ? $nnPaymentData['service_supplier_id'] : 0
        ];
     
    
     return $additional_info;
     
    }
    
    /**
     * insert payment data into Novalnet transaction table
     *
     * @param array $requestData
     *
     * @return none
     */
    public function insertRequestDetailsForReinit($requestData) {

        $paymentRequestData = [
            'amount'           => $requestData['amount'] * 100,
            'callback_amount'  => in_array($requestData['key'], ['27', '59']) ? 0 : $requestData['amount'] * 100,
            'tid'              => '0',
            'ref_tid'          => '0',
            'payment_name'     => '0',
            'order_no'         => $requestData['order_no'],
            'additional_info'  => json_encode(['is_novalnet_callback_executed' => false])
        ];
        
        $this->transactionLogData->saveTransaction($paymentRequestData);
    }
    
    /**
     * Check payment request already send to Novalnet server
     *
     * @param int $orderId
     *
     * @return bool
     */
    public function checkPaymentRequestSend($orderId) {
        $sendPaymentCall = true;
        if(!empty($orderId)) {
            $orderDetails = $this->transactionLogData->getTransactionData('orderNo', $orderId);
            foreach($orderDetails as $orderDetail) {
                    $additionalInfo = json_decode($orderDetail->additionalInfo, true);
                    if(isset($additionalInfo['is_novalnet_callback_executed'])) {
                        $sendPaymentCall = false;
                    }
             }
        }
        return $sendPaymentCall;
    }
    
    /**
     * Get Seamless payment form customization params
     *
     * @param array $paymentRequestData
     
     * @return none
     */
    public function getSeamlessFormParams(&$paymentRequestData)
    {
        $paymentRequestData['hfooter'] = 0;
        $paymentRequestData['lhide'] = 1;
        $paymentRequestData['shide'] = 1;
        $paymentRequestData['thide'] = 1;
        $paymentRequestData['address_form'] = 0;
        $paymentRequestData['chosen_only'] = 1;
    }
    
}
