<?php
/**
 * Mollie Payment Gateway
 * @version 1.1.0
 */

namespace Cloudstek\WHMCS\Mollie;

use Mollie\API\Mollie;
use Mollie\API\Exception\RequestException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Capture payment action
 */
class Capture extends ActionBase
{
    /** @var int $invoiceId Invoice ID */
    private $invoiceId;

    /** @var array $clientDetails */
    private $clientDetails;

    /**
     * Capture action constructor
     * @param array $params Capture action parameters.
     */
    public function __construct(array $params)
    {
        parent::__construct($params);

        // Store invoice ID
        $this->invoiceId = $params['invoiceid'];

        // Client details
        $this->clientDetails = $params['clientdetails'];
    }

    /**
     * Generate status message
     *
     * @param string     $status  Status message type.
     * @param string     $message Status message content.
     * @param array|null $data    Additional data to include.
     * @return array
     */
    private function statusMessage($status, $message, array $data = null)
    {
        // Build message.
        $msg = array(
            'status' => $status,
            'rawdata' => array(
                'message' => $message
            )
        );

        // Merge with additional data.
        if (!empty($data)) {
            $msg['rawdata'] = array_merge($msg['rawdata'], $data);
        }

        return $msg;
    }

    /**
     * Process recurring payment using existing mandate
     * 
     * @return array Status message
     */
    public function run()
    {
        // Initialize.
        if (!$this->initialize()) {
            return $this->statusMessage(
                'error',
                "Failed to process recurring payment for invoice {$this->invoiceId} - API key is missing!"
            );
        }

        // Check if recurring is enabled
        if (!isset($this->gatewayParams['enable_recurring']) || $this->gatewayParams['enable_recurring'] != 'on') {
            return $this->statusMessage(
                'error',
                "Recurring payments are not enabled for this gateway."
            );
        }

        // Get API key.
        $apiKey = $this->getApiKey();

        try {
            // Mollie API.
            $mollie = new Mollie($apiKey);
            
            // Initialize recurring module
            $recurring = new Recurring($this->gatewayParams);
            $recurring->run();
            
            // Check if we're using subscriptions
            if (isset($this->gatewayParams['recurring_type']) && $this->gatewayParams['recurring_type'] == 'subscription') {
                // Handle via subscription
                return $this->processSubscription($mollie, $recurring);
            } else {
                // Handle via manual recurring
                return $this->processManualRecurring($mollie, $recurring);
            }
        } catch (\Exception $ex) {
            return $this->statusMessage(
                'error',
                "Failed to process recurring payment for invoice {$this->invoiceId}.",
                array(
                    'exception' => $ex->getMessage()
                )
            );
        }
    }
    
    /**
     * Process payment using manual recurring (payment with mandate)
     * 
     * @param Mollie $mollie Mollie API instance
     * @param Recurring $recurring Recurring module instance
     * @return array Status message
     */
    private function processManualRecurring(Mollie $mollie, Recurring $recurring)
    {
        // Get customer ID
        $customerId = $this->getCustomerId($this->clientDetails['userid']);
        
        if (!$customerId) {
            return $this->statusMessage(
                'error',
                "Customer ID not found for client {$this->clientDetails['userid']}."
            );
        }
        
        // Get valid mandate
        $mandate = $recurring->getValidMandate($this->clientDetails['userid']);
        
        if (!$mandate) {
            // No valid mandate found, we need to create a first payment
            return $this->statusMessage(
                'error',
                "No valid mandate found for client {$this->clientDetails['userid']}. " .
                "Client must make a first payment to authorize recurring payments."
            );
        }
        
        // Create payment parameters
        $params = [
            'invoiceid' => $this->invoiceId,
            'amount' => $this->actionParams['amount'],
            'description' => $this->actionParams['description']
        ];
        
        // Create recurring payment
        $payment = $recurring->createRecurringPayment($mollie, $customerId, $mandate->mandateid, $params);
        
        if (!$payment) {
            return $this->statusMessage(
                'error',
                "Failed to create recurring payment for invoice {$this->invoiceId}."
            );
        }
        
        // Check if payment was immediately paid (sometimes happens with certain methods)
        if ($payment->status === 'paid') {
            // Add payment
            addInvoicePayment(
                $this->invoiceId,
                $payment->id,
                $payment->amount,
                0.00,
                $this->gatewayParams['paymentmethod'],
                false
            );
            
            return $this->statusMessage(
                'success',
                "Successfully processed recurring payment for invoice {$this->invoiceId}.",
                array(
                    'transaction_id' => $payment->id
                )
            );
        }
        
        // Payment is created but needs to be processed by Mollie
        return $this->statusMessage(
            'pending',
            "Recurring payment initiated for invoice {$this->invoiceId}. Awaiting processing by Mollie.",
            array(
                'transaction_id' => $payment->id
            )
        );
    }
    
    /**
     * Process payment using subscription
     * 
     * @param Mollie $mollie Mollie API instance
     * @param Recurring $recurring Recurring module instance
     * @return array Status message
     */
    private function processSubscription(Mollie $mollie, Recurring $recurring)
    {
        // Get customer ID
        $customerId = $this->getCustomerId($this->clientDetails['userid']);
        
        if (!$customerId) {
            return $this->statusMessage(
                'error',
                "Customer ID not found for client {$this->clientDetails['userid']}."
            );
        }
        
        // Check if the service has an active subscription
        $subscription = Capsule::table('mod_mollie_subscriptions')
            ->where('clientid', $this->clientDetails['userid'])
            ->where('status', 'active')
            ->first();
        
        if ($subscription) {
            // Subscription already exists
            return $this->statusMessage(
                'pending',
                "Client already has an active subscription. Invoice {$this->invoiceId} will be paid automatically.",
                array(
                    'subscription_id' => $subscription->subscriptionid
                )
            );
        }
        
        // Get valid mandate
        $mandate = $recurring->getValidMandate($this->clientDetails['userid']);
        
        if (!$mandate) {
            // No valid mandate found, we need to create a first payment
            return $this->statusMessage(
                'error',
                "No valid mandate found for client {$this->clientDetails['userid']}. " .
                "Client must make a first payment to authorize recurring payments."
            );
        }
        
        // Create subscription parameters
        $params = [
            'clientid' => $this->clientDetails['userid'],
            'serviceid' => isset($this->actionParams['serviceid']) ? $this->actionParams['serviceid'] : 0,
            'amount' => $this->actionParams['amount'],
            'interval' => '1 month', // Default to monthly
            'description' => $this->actionParams['description']
        ];
        
        // Create subscription
        $subscription = $recurring->createSubscription($mollie, $customerId, $params);
        
        if (!$subscription) {
            return $this->statusMessage(
                'error',
                "Failed to create subscription for client {$this->clientDetails['userid']}."
            );
        }
        
        return $this->statusMessage(
            'success',
            "Successfully created subscription for client {$this->clientDetails['userid']}.",
            array(
                'subscription_id' => $subscription->id
            )
        );
    }
}