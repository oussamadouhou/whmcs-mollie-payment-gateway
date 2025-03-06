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
 * Recurring payment action
 */
class Recurring extends ActionBase
{
    /**
     * Recurring action constructor
     * @param array $params Recurring action parameters.
     */
    public function __construct(array $params)
    {
        parent::__construct($params);
    }

    /**
     * Initialize database tables for recurring payments
     * 
     * @return void
     */
    protected function initializeRecurringTables()
    {
        if (!Capsule::schema()->hasTable('mod_mollie_mandates')) {
            Capsule::schema()->create('mod_mollie_mandates', function ($table) {
                $table->increments('id');
                $table->integer('clientid')->unsigned();
                $table->string('mandateid')->unique();
                $table->string('method');
                $table->string('status');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_mollie_subscriptions')) {
            Capsule::schema()->create('mod_mollie_subscriptions', function ($table) {
                $table->increments('id');
                $table->integer('clientid')->unsigned();
                $table->integer('serviceid')->unsigned();
                $table->string('subscriptionid')->unique();
                $table->string('status');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('next_payment_date')->nullable();
            });
        }
    }

    /**
     * Get or create a mandate for the customer
     * 
     * @param Mollie $mollie Mollie API instance.
     * @param string $customerId Mollie customer ID.
     * @param string $method Payment method.
     * @return object Mandate object.
     */
    public function getOrCreateMandate(Mollie $mollie, $customerId, $method = null)
    {
        try {
            // Get existing mandates
            $mandates = $mollie->customer($customerId)->mandates()->all();
            
            // Check for valid mandate
            foreach ($mandates as $mandate) {
                if ($mandate->status === 'valid' && ($method === null || $mandate->method === $method)) {
                    return $mandate;
                }
            }
            
            // No valid mandate found, we need to create a first payment to get a mandate
            return null;
        } catch (RequestException $e) {
            $this->logTransaction("Error getting mandate: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Create a first payment to get a mandate
     * 
     * @param Mollie $mollie Mollie API instance.
     * @param string $customerId Mollie customer ID.
     * @param array $params Payment parameters.
     * @return object|null Payment object or null on failure.
     */
    public function createFirstPaymentForMandate(Mollie $mollie, $customerId, array $params)
    {
        try {
            // Create a payment with sequenceType set to first
            $payment = $mollie->customer($customerId)->payment()->create(
                $params['amount'],
                $params['description'],
                $params['returnurl'],
                array(
                    'whmcs_invoice' => $params['invoiceid'],
                    'whmcs_service' => isset($params['serviceid']) ? $params['serviceid'] : null,
                ),
                array(
                    'webhookUrl' => $this->getWebhookUrl(),
                    'sequenceType' => 'first',
                    'metadata' => array(
                        'recurring' => true,
                        'first_payment' => true
                    )
                )
            );

            // Store pending transaction
            $this->updateTransactionStatus($params['invoiceid'], 'pending', $payment->id);

            // Log transaction
            $this->logTransaction(
                "First payment for recurring mandate attempted for invoice {$params['invoiceid']}. " .
                "Awaiting payment confirmation from callback for transaction {$payment->id}.",
                'Success'
            );

            return $payment;
        } catch (RequestException $e) {
            $this->logTransaction("Error creating first payment for mandate: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Create a recurring payment using an existing mandate
     * 
     * @param Mollie $mollie Mollie API instance.
     * @param string $customerId Mollie customer ID.
     * @param string $mandateId Mollie mandate ID.
     * @param array $params Payment parameters.
     * @return object|null Payment object or null on failure.
     */
    public function createRecurringPayment(Mollie $mollie, $customerId, $mandateId, array $params)
    {
        try {
            // Create a payment with sequenceType set to recurring
            $payment = $mollie->customer($customerId)->payment()->create(
                $params['amount'],
                $params['description'],
                isset($params['returnurl']) ? $params['returnurl'] : null,
                array(
                    'whmcs_invoice' => $params['invoiceid'],
                    'whmcs_service' => isset($params['serviceid']) ? $params['serviceid'] : null,
                ),
                array(
                    'webhookUrl' => $this->getWebhookUrl(),
                    'sequenceType' => 'recurring',
                    'mandateId' => $mandateId
                )
            );

            // Store pending transaction
            $this->updateTransactionStatus($params['invoiceid'], 'pending', $payment->id);

            // Log transaction
            $this->logTransaction(
                "Recurring payment attempted for invoice {$params['invoiceid']}. " .
                "Transaction ID: {$payment->id}.",
                'Success'
            );

            return $payment;
        } catch (RequestException $e) {
            $this->logTransaction("Error creating recurring payment: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Create a subscription for automated recurring payments
     * 
     * @param Mollie $mollie Mollie API instance.
     * @param string $customerId Mollie customer ID.
     * @param array $params Subscription parameters.
     * @return object|null Subscription object or null on failure.
     */
    public function createSubscription(Mollie $mollie, $customerId, array $params)
    {
        try {
            // Create a subscription
            $subscription = $mollie->customer($customerId)->subscription()->create(
                $params['amount'],
                $params['interval'], // e.g., "1 month", "14 days"
                $params['description'],
                array(
                    'webhookUrl' => $this->getWebhookUrl(),
                    'metadata' => array(
                        'whmcs_client' => $params['clientid'],
                        'whmcs_service' => isset($params['serviceid']) ? $params['serviceid'] : null
                    ),
                    'startDate' => isset($params['startdate']) ? $params['startdate'] : null
                )
            );

            // Store subscription in database
            Capsule::table('mod_mollie_subscriptions')->insert([
                'clientid' => $params['clientid'],
                'serviceid' => isset($params['serviceid']) ? $params['serviceid'] : 0,
                'subscriptionid' => $subscription->id,
                'status' => $subscription->status,
                'next_payment_date' => $subscription->nextPaymentDate
            ]);

            // Log subscription creation
            $this->logTransaction(
                "Subscription created: {$subscription->id} for client {$params['clientid']}.",
                'Success'
            );

            return $subscription;
        } catch (RequestException $e) {
            $this->logTransaction("Error creating subscription: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Cancel a subscription
     * 
     * @param Mollie $mollie Mollie API instance.
     * @param string $customerId Mollie customer ID.
     * @param string $subscriptionId Mollie subscription ID.
     * @return bool Success status.
     */
    public function cancelSubscription(Mollie $mollie, $customerId, $subscriptionId)
    {
        try {
            // Cancel the subscription at Mollie
            $mollie->customer($customerId)->subscription($subscriptionId)->cancel();
            
            // Update subscription status in database
            Capsule::table('mod_mollie_subscriptions')
                ->where('subscriptionid', $subscriptionId)
                ->update([
                    'status' => 'canceled',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            // Log subscription cancellation
            $this->logTransaction("Subscription {$subscriptionId} canceled successfully.", 'Success');
            
            return true;
        } catch (RequestException $e) {
            $this->logTransaction("Error canceling subscription {$subscriptionId}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Store mandate in the database
     * 
     * @param int $clientId WHMCS client ID.
     * @param string $mandateId Mollie mandate ID.
     * @param string $method Payment method.
     * @param string $status Mandate status.
     * @return bool Success status.
     */
    public function storeMandate($clientId, $mandateId, $method, $status)
    {
        try {
            // Check if mandate already exists
            $exists = Capsule::table('mod_mollie_mandates')
                ->where('mandateid', $mandateId)
                ->count();
            
            if ($exists) {
                // Update existing mandate
                Capsule::table('mod_mollie_mandates')
                    ->where('mandateid', $mandateId)
                    ->update([
                        'method' => $method,
                        'status' => $status,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                // Insert new mandate
                Capsule::table('mod_mollie_mandates')->insert([
                    'clientid' => $clientId,
                    'mandateid' => $mandateId,
                    'method' => $method,
                    'status' => $status
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logTransaction("Error storing mandate {$mandateId}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get valid mandate for a client
     * 
     * @param int $clientId WHMCS client ID.
     * @param string|null $method Specific payment method (optional).
     * @return object|null Mandate row or null if not found.
     */
    public function getValidMandate($clientId, $method = null)
    {
        $query = Capsule::table('mod_mollie_mandates')
            ->where('clientid', $clientId)
            ->where('status', 'valid');
        
        if ($method !== null) {
            $query->where('method', $method);
        }
        
        return $query->first();
    }

    /**
     * Run initialization of recurring payment functionality
     * @return bool Success status.
     */
    public function run()
    {
        // Initialize base functionality
        if (!$this->initialize()) {
            return false;
        }
        
        // Initialize recurring tables
        $this->initializeRecurringTables();
        
        return true;
    }
}