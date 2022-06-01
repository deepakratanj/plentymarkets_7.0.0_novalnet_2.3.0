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

namespace Novalnet\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Order\Models\OrderType;

/**
 * Class RefundEventProcedure
 */
class RefundEventProcedure
{
    use Loggable;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     *
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var transaction
     */
    private $transaction;

    /**
     * Constructor.
     *
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     */

    public function __construct( PaymentHelper $paymentHelper, TransactionService $tranactionService,
                                 PaymentService $paymentService)
    {
        $this->paymentHelper   = $paymentHelper;
        $this->paymentService  = $paymentService;
        $this->transaction     = $tranactionService;
    }

    /**
     * @param EventProceduresTriggered $eventTriggered
     *
     */
    public function run(
        EventProceduresTriggered $eventTriggered
    ) {
        /* @var $order Order */

       $order = $eventTriggered->getOrder();
       $parent_order_id = $order->id;

        // Checking order type
       if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) {
            foreach ($order->orderReferences as $orderReference) {
                $parent_order_id = $orderReference->originOrderId;
                $child_order_id = $orderReference->orderId;
            }
       }

       $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);
       $paymentDetails = $payments->getPaymentsByOrderId($parent_order_id);

       $orderDetails = $this->transaction->getTransactionData('orderNo', $parent_order_id); // Load all the details for an order

       foreach($orderDetails as $orderDetail) {
            $additionalInfo = json_decode($orderDetail->additionalInfo, true);
            if (isset($additionalInfo['tid_status']) && $additionalInfo['tid_status'] == 100) {
                  $tidStatus = $additionalInfo['tid_status'];
                  $refundTid = $orderDetail->tid;
                  $orderAmount = $orderDetail->amount;
                  $paymentKey = $orderDetail->paymentName;
                  break;
              }
       }

       $key = $this->paymentService->getkeyByPaymentKey(strtoupper($paymentKey));
        
       // Get the updated payment details
       foreach ($paymentDetails as $paymentDetail)
       {
            $paymentCurrency = $paymentDetail->currency;
       }
        
       // Get the proper order amount even the system currency and payment currency are differ
       if(count($order->amounts) > 1) {
          foreach($order->amounts as $amount) {
               if($paymentCurrency == $amount->currency) {
                   $refundAmount = (float) $amount->invoiceTotal; // Get the refunding amount
               }
          }
        } else {
             $refundAmount = (float) $order->amounts[0]->invoiceTotal; // Get the refunding amount
       }

       if ($tidStatus == 100)
       {
            try {
                $paymentRequestData = [
                    'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
                    'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
                    'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
                    'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
                    'key'            => $key,
                    'refund_request' => 1,
                    'tid'            => $refundTid,
                    'refund_param'  => (float) $refundAmount * 100,
                    'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
                    'lang'           => 'de'
                     ];

                $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
                $responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');

                if ($responseData['status'] == '100') {

                    $transactionComments = '';

                    if (!empty($responseData['tid'])) {
                        $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message_new_tid', $paymentRequestData['lang']), $refundTid, sprintf('%0.2f', ($paymentRequestData['refund_param'] / 100)) , $paymentCurrency, $responseData['tid']);
                    } else {
                        $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message', $paymentRequestData['lang']), $refundTid, sprintf('%0.2f', ($paymentRequestData['refund_param'] / 100)), $paymentCurrency, uniqid());
                    }

                    $paymentData['tid'] = !empty($responseData['tid']) ? $responseData['tid'] : $refundTid;
                    $paymentData['tid_status'] = $responseData['tid_status'];
                    $paymentData['refunded_amount'] = (float) $refundAmount;
                    $paymentData['child_order_id'] = $child_order_id;
                    $paymentData['parent_order_id'] = $parent_order_id;
                    $paymentData['parent_tid'] = $refundTid;
                    $paymentData['payment_name'] = strtolower($paymentKey);

                    if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) { // Create refund entry in credit note order
                        $this->paymentHelper->createRefundPayment($paymentDetails, $paymentData, $transactionComments);
                    } else { // Update the already exist payment entry
                        $paymentData['tid'] = !empty($responseData['tid']) ? $responseData['tid'] : $refundTid;
                        $this->paymentHelper->updatePayments($refundTid, $responseData['tid_status'], $parent_order_id, true);
                    }
                } else {
                    $error = $this->paymentHelper->getNovalnetStatusText($responseData);
                    $this->getLogger(__METHOD__)->error('Novalnet::doRefundError', $error);
                }
            } catch (\Exception $e) {
                        $this->getLogger(__METHOD__)->error('Novalnet::doRefund', $e);
                    }
        } else {
           $this->getLogger(__METHOD__)->error('Novalnet::doRefund', 'Transaction status is not valid');
        }
    }
}
