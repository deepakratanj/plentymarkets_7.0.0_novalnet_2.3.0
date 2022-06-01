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

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Document\Models\Document;
use Novalnet\Constants\NovalnetConstants;

use Novalnet\Methods\NovalnetSepaPaymentMethod;
use Novalnet\Methods\NovalnetCcPaymentMethod;
use Novalnet\Methods\NovalnetApplePayPaymentMethod;
use Novalnet\Methods\NovalnetInvoicePaymentMethod;
use Novalnet\Methods\NovalnetPrepaymentPaymentMethod;
use Novalnet\Methods\NovalnetIdealPaymentMethod;
use Novalnet\Methods\NovalnetSofortPaymentMethod;
use Novalnet\Methods\NovalnetGiropayPaymentMethod;
use Novalnet\Methods\NovalnetCashPaymentMethod;
use Novalnet\Methods\NovalnetPrzelewyPaymentMethod;
use Novalnet\Methods\NovalnetEpsPaymentMethod;
use Novalnet\Methods\NovalnetPaypalPaymentMethod;
use Novalnet\Methods\NovalnetPostfinanceCardPaymentMethod;
use Novalnet\Methods\NovalnetPostfinanceEfinancePaymentMethod;
use Novalnet\Methods\NovalnetBancontactPaymentMethod;
use Novalnet\Methods\NovalnetMultibancoPaymentMethod;
use Novalnet\Methods\NovalnetOnlineBankTransferPaymentMethod;

use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Novalnet\Controllers\PaymentController;
/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param Dispatcher $eventDispatcher
     * @param paymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodContainer $payContainer
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionLogData
     * @param Twig $twig
     * @param ConfigRepository $config
     */
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
                          AddressRepositoryContract $addressRepository,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig,
                          ConfigRepository $config,
                          PaymentRepositoryContract $paymentRepository,
                          DataBase $dataBase,
                          EventProceduresService $eventProceduresService)
    {

        // Register the Novalnet payment methods in the payment method container
        $payContainer->register('plenty_novalnet::NOVALNET_SEPA', NovalnetSepaPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_CC', NovalnetCcPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_APPLEPAY', NovalnetApplePayPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_INVOICE', NovalnetInvoicePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PREPAYMENT', NovalnetPrepaymentPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_IDEAL', NovalnetIdealPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_SOFORT', NovalnetSofortPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_GIROPAY', NovalnetGiropayPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_CASHPAYMENT', NovalnetCashPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PRZELEWY', NovalnetPrzelewyPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_EPS', NovalnetEpsPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PAYPAL', NovalnetPaypalPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_POSTFINANCE_CARD', NovalnetPostfinanceCardPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_POSTFINANCE_EFINANCE', NovalnetPostfinanceEfinancePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_BANCONTACT', NovalnetBancontactPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_MULTIBANCO', NovalnetMultibancoPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_ONLINE_BANK_TRANSFER', NovalnetOnlineBankTransferPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
            
        // Event for Onhold - Capture Process
        $captureProcedureTitle = [
            'de' => 'Novalnet | Bestätigen',
            'en' => 'Novalnet | Confirm',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $captureProcedureTitle,
            '\Novalnet\Procedures\CaptureEventProcedure@run'
        );
        
        // Event for Onhold - Void Process
        $voidProcedureTitle = [
            'de' => 'Novalnet | Stornieren',
            'en' => 'Novalnet | Cancel',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $voidProcedureTitle,
            '\Novalnet\Procedures\VoidEventProcedure@run'
        );
        
        // Event for Onhold - Refund Process
        $refundProcedureTitle = [
            'de' =>  'Novalnet | Rückerstattung',
            'en' =>  'Novalnet | Refund',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $refundProcedureTitle,
            '\Novalnet\Procedures\RefundEventProcedure@run'
        );
        
        // Register filter for the Novalnet pending and on-hold payment status
        $awaitingApprovalFilterTitle = [
            'de' =>  'Novalnet | Warten Auf Zahlungseingang',
            'en' =>  'Novalnet | Awaiting approval',
        ];
        
        $eventProceduresService->registerFilter(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $awaitingApprovalFilterTitle,
            '\Novalnet\Procedures\PaymentStatusFilter@awaitingApproval'
        );
        
        // Register filter for the Novalnet confirmed payment status
        $confirmedFilterTitle = [
            'de' =>  'Novalnet | Gefangen',
            'en' =>  'Novalnet | Captured',
        ];
        
        $eventProceduresService->registerFilter(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $confirmedFilterTitle,
            '\Novalnet\Procedures\PaymentStatusFilter@confirmed'
        );
        
        // Register filter for the Novalnet canceled payment status
        $cancelledFilterTitle = [
            'de' =>  'Novalnet | Storniert',
            'en' =>  'Novalnet | Cancelled',
        ];
        
        $eventProceduresService->registerFilter(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $cancelledFilterTitle,
            '\Novalnet\Procedures\PaymentStatusFilter@canceled'
        );
        
        $manualOrderFilterTitle = [
            'de' =>  'Novalnet | Order created from Backend',
            'en' =>  'Novalnet | Auftrag aus Backend erstellt',
        ];

        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use($config, $paymentHelper, $addressRepository, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage, $twig)
                {
        
                    if($paymentHelper->getPaymentKeyByMop($event->getMop()))
                    {   
                        $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop()); 
                        $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
                        $basket = $basketRepository->load();            
                        $billingAddressId = $basket->customerInvoiceAddressId;
                        $address = $addressRepository->findAddressById($billingAddressId);
                            foreach ($address->options as $option) {
                            if ($option->typeId == 12) {
                                $name = $option->value;
                            }
                            if ($option->typeId == 9) {
                                $birthday = $option->value;
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
                            $endCustomerName = $firstName .' '. $lastName;
                            $endUserName = $address->firstName .' '. $address->lastName;

                        $redirect = $paymentService->isRedirectPayment($paymentKey, false);    
                            
                        if ($redirect && $paymentKey != 'NOVALNET_CC') { # Redirection payments
                            $serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
                           if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name'])) {
                            $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                            $contentType = 'errorCode';   
                           } else {
                                 $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                                        $sessionStorage->getPlugin()->setValue('nnPaymentUrl', $serverRequestData['url']);
                                        $content = '';
                                        $contentType = 'continue';
                           }
                        } elseif ($paymentKey == 'NOVALNET_CC') { # Credit Card
                            $ccFormDetails = $paymentService->getCreditCardAuthenticationCallData($basket, $paymentKey);
                            $ccCustomFields = $paymentService->getCcFormFields();
            
                            $content = $twig->render('Novalnet::PaymentForm.NOVALNET_CC', [
                                'nnPaymentProcessUrl'   => $paymentService->getProcessPaymentUrl(),
                                'paymentMopKey'         =>  $paymentKey,
                                'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)),
                                'ccFormDetails'       => !empty($ccFormDetails) ? $ccFormDetails : '',
                                'ccCustomFields'       => !empty($ccCustomFields) ? $ccCustomFields : ''
                                 ]);
                            $contentType = 'htmlContent';
                        } elseif($paymentKey == 'NOVALNET_SEPA') {
                                $contentType = 'htmlContent';
                                $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);

                                if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
                                {
                                    $contentType = 'errorCode';
                                    $content = $guaranteeStatus;
                                }
                                else
                                {
                                    if( empty($address->companyName) && empty($birthday)) {
                                           $show_birthday = true;
                                     }
                                    $content = $twig->render('Novalnet::PaymentForm.NOVALNET_SEPA', [
                                                                    'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl(),
                                                                    'paymentMopKey'     =>  $paymentKey,
                                                                    'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)), 
                                                                    'endcustomername'=> empty(trim($endUserName)) ? $endCustomerName : $endUserName,
                                                                    'nnGuaranteeStatus' => $show_birthday ? $guaranteeStatus : ''
                                                                    ]);
                                }
                          } else {
                                if(in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CASHPAYMENT', 'NOVALNET_MULTIBANCO']))
                                {
                                    $processDirect = true;
                                    $B2B_customer   = false;
                                    if($paymentKey == 'NOVALNET_INVOICE')
                                    {
                                        $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
                                        if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
                                        {
                                            $processDirect = false;
                                            $contentType = 'errorCode';
                                            $content = $guaranteeStatus;
                                        }
                                        else if($guaranteeStatus == 'guarantee')
                                        {
                                            $processDirect = false;
                                            
                                            $paymentProcessUrl = $paymentService->getProcessPaymentUrl();
                                            if (empty($address->companyName) &&  empty($birthday) ) {
                                            $content = $twig->render('Novalnet::PaymentForm.NOVALNET_INVOICE', [
                                                                'nnPaymentProcessUrl' => $paymentProcessUrl,
                                                'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)),  
                                                'paymentMopKey'     =>  $paymentKey,
                                                'guarantee_force' => trim($config->get('Novalnet.' . strtolower($paymentKey) . '_payment_guarantee_force_active'))
                                            
                                            ]);                                                 $contentType = 'htmlContent';
                                            } else {
                                                $processDirect = true;                                              
                                                $B2B_customer  = true;
                                            }
                                         }
                                    }
                                    if ($processDirect) {
                                    $content = '';
                                    $contentType = 'continue';
                                    $serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
                                    if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name'])) {
                                            $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                                            $contentType = 'errorCode';   
                                     } else {   
                                        if( $B2B_customer) {
                                            $serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
                                            $serverRequestData['data']['key'] = '41';
                                            $serverRequestData['data']['birth_date'] = !empty($birthday) ? $birthday : '';
                        
                       if (empty($address->companyName) && time() < strtotime('+18 years', strtotime($birthday))) {
                          $content = $paymentHelper->getTranslatedText('dobinvalid');
                                              $contentType = 'errorCode';   
                        } elseif (!empty ($address->companyName) ) {
                                unset($serverRequestData['data']['birth_date']);
                            }

                                        }
                                    $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData);
                                    
                                    }
                                    
                                    } 
                                } 
                            }
                                
                                $event->setValue($content);
                                $event->setType($contentType);
                        } 
                });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $transactionLogData,$config,$basketRepository)
            {
                if($paymentHelper->getPaymentKeyByMop($event->getMop())) {
                    $sessionStorage->getPlugin()->setValue('nnOrderNo',$event->getOrderId());
                    $sessionStorage->getPlugin()->setValue('mop',$event->getMop());
                    $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                    $sessionStorage->getPlugin()->setValue('paymentkey', $paymentKey);
                    $doRedirect = $sessionStorage->getPlugin()->getValue('nnDoRedirect');
            

                    if(!$paymentService->isRedirectPayment($paymentKey, $doRedirect)) {
                         $paymentService->paymentCalltoNovalnetServer();
                         $paymentService->validateResponse();
                    } else {
                        $sessionStorage->getPlugin()->setValue('nnDoRedirect', null);
                        $paymentProcessUrl = $paymentService->getRedirectPaymentUrl();
                        $event->setType('redirectUrl');
                        $event->setValue($paymentProcessUrl);
                    }
                }
            }
        );
        
     // Invoice PDF Generation
    
    // Listen for the document generation event
        $eventDispatcher->listen(OrderPdfGenerationEvent::class,
        function (OrderPdfGenerationEvent $event) use ($dataBase, $paymentHelper, $paymentService, $paymentRepository, $transactionLogData) {
            
        /** @var Order $order */ 
        $order = $event->getOrder();
        $payments = $paymentRepository->getPaymentsByOrderId($order->id);
        foreach ($payments as $payment)
        {
            $properties = $payment->properties;
            foreach($properties as $property)
            {
            if ($property->typeId == 21) 
            {
            $invoiceDetails = $property->value;
            }
            if ($property->typeId == 22)
            {
            $cashpayment_comments = $property->value;
            }
            if($property->typeId == 30)
            {
            $tid_status = $property->value;
            }
            }
        }
        $paymentKey = $paymentHelper->getPaymentKeyByMop($payments[0]->mopId);
        $db_details = $paymentService->getDatabaseValues($order->id);
        $get_transaction_details = $transactionLogData->getTransactionData('orderNo', $order->id);
        $payment_details = json_decode($get_transaction_details[0]->additionalInfo, true);
        $db_details['test_mode'] = !empty($db_details['test_mode']) ? $db_details['test_mode'] : $payment_details['test_mode'];
        $db_details['payment_id'] = !empty($db_details['payment_id']) ? $db_details['payment_id'] : $payment_details['payment_id'];
        $totalCallbackAmount = 0;
        foreach ($get_transaction_details as $transaction_details) {
           $totalCallbackAmount += $transaction_details->callbackAmount;
        }
        if (in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_CASHPAYMENT', 'NOVALNET_SOFORT', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PAYPAL', 'NOVALNET_PRZELEWY', 'NOVALNET_APPLEPAY', 'NOVALNET_POSTFINANCE_CARD', 'NOVALNET_POSTFINANCE_EFINANCE', 'NOVALNET_BANCONTACT', 'NOVALNET_MULTIBANCO', 'NOVALNET_ONLINE_BANK_TRANSFER']) && !empty($db_details['plugin_version'])
        ) {
             
        try {
                $comments = '';
                if(!empty($db_details['tid'])) {
                    $comments .= PHP_EOL . $paymentHelper->getTranslatedText('nn_tid') . $db_details['tid'];
                }
                if(!empty($db_details['test_mode'])) {
                    $comments .= PHP_EOL . $paymentHelper->getTranslatedText('test_order');
                }
                 if(in_array($tid_status, ['91', '100']) && ($db_details['payment_id'] == '27' && ($transaction_details->amount > $totalCallbackAmount) || $db_details['payment_id'] == '41') ) {
                     $bank_details = array_merge($db_details, json_decode($invoiceDetails, true));
                     $bank_details['tid_status'] = $tid_status;
                     $comments .= PHP_EOL . $paymentService->getInvoicePrepaymentComments($bank_details);
                
                }
                 if($db_details['payment_id'] == '59' && ($transaction_details->amount > $totalCallbackAmount) && $tid_status == '100' ) {
                $comments .= PHP_EOL . $cashpayment_comments;   
                }
                if($db_details['payment_id'] == '73' && ($transaction_details->amount > $totalCallbackAmount) && $tid_status == '100') {
                        $comments .= PHP_EOL . $paymentService->getMultibancoReferenceInformation($db_details);
                }
            
                $orderPdfGenerationModel = pluginApp(OrderPdfGeneration::class);
                $orderPdfGenerationModel->advice = $paymentHelper->getTranslatedText('novalnet_details'). PHP_EOL . $comments;
                if ($event->getDocType() == Document::INVOICE) {
                    $event->addOrderPdfGeneration($orderPdfGenerationModel); 
                }
        } catch (\Exception $e) {
                    $this->getLogger(__METHOD__)->error('Adding PDF comment failed for order' . $order->id , $e);
        } 
        }
        } 
      );  
    }
}
