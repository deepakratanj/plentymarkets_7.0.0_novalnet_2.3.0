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
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;

/**
 * Class PaymentStatusFilter
 */
class PaymentStatusFilter
{
    use Loggable;
 
    /**
     * Filter process for the pending and on-hold payment status 
     *
     * EventProceduresTriggered $eventTriggered
     * 
     */
    public function awaitingApproval(EventProceduresTriggered $eventTriggered) {
        return $this->getNovalnetOrderPaymentStatus($eventTriggered, 1);
    }
    
     /**
     * Filter process for the confirmed payment status 
     *
     * EventProceduresTriggered $eventTriggered
     * 
     */
    public function confirmed(EventProceduresTriggered $eventTriggered) {
       return $this->getNovalnetOrderPaymentStatus($eventTriggered, 3);
    }
   
   /**
     * Filter process for the confirmed payment status 
     *
     * EventProceduresTriggered $eventTriggered
     * 
     */
   public function canceled(EventProceduresTriggered $eventTriggered) {
      return $this->getNovalnetOrderPaymentStatus($eventTriggered, 5);
   }
 
   
  
   /**
     * Get the payment status based on the order Id
     *
     * return bool 
    */
   public function getNovalnetOrderPaymentStatus($eventTriggered, $paymentStatusId) {
       /* @var $order Order */
       $order = $eventTriggered->getOrder();
       $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
       $paymentDetails = $payments->getPaymentsByOrderId($order->id);
       foreach ($paymentDetails as $paymentDetail) {
          $paymentStatus = $paymentDetail->status;
       }
       
       if($paymentStatusId == $paymentStatus) {
          return true;
       } else {
          return false;
       }
   }
}
