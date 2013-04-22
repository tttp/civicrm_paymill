
<?php

require_once 'CRM/Core/Payment.php';

class CRM_Core_Payment_Paymill extends CRM_Core_Payment {

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;

    /**
     * mode of operation: live or test
     *
     * @var object
     * @static
     */
    static protected $_mode = null;

    /**
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct($mode, &$paymentProcessor) {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName = ts('Paymill');
    }

    /**
     * singleton function used to manage this object
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return object
     * @static
     *
     */
    static function &singleton($mode, &$paymentProcessor) {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null) {
            self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
        }
        return self::$_singleton[$processorName];
    }

    /**
     * This function checks to see if we have the right config values
     *
     * @return string the error message if any
     * @public
     */
    function checkConfig() {
        $config = CRM_Core_Config::singleton();
        $error = array();

        if (empty($this->_paymentProcessor['user_name'])) {
            $error[] = ts('The "Secret Key" is not set in the Paymill Payment Processor settings.');
        }

        if (empty($this->_paymentProcessor['password'])) {
            $error[] = ts('The "Publishable Key" is not set in the Paymill Payment Processor settings.');
        }

        if (!empty($error)) {
            return implode('<p>', $error);
        } else {
            return NULL;
        }
    }

    function doDirectPayment(&$params) {
        // Let a $0 transaction pass.
        if (empty($params['amount']) || $params['amount'] == 0) {
            return $params;
        }


        // Paymill amount required in cents.
        $amount = $params['amount'] * 100;

        // It would require 3 digits after the decimal for one to make it this far.
        // CiviCRM prevents this, but let's be redundant.
        $amount = number_format($amount, 0, '', '');

        // Include Paymill library & Set API credentials.
        require_once("Paymill-PHP/lib/Services/Paymill/Transactions.php");
        require_once("Paymill-PHP/lib/Services/Paymill/Clients.php");
        require_once("Paymill-PHP/lib/Services/Paymill/Payments.php");
        require_once("CRM/Core/Error.php");

        echo "<br><br>";


        if (isset($params['paymill_token'])) {
            $card_details = $params['paymill_token'];
            if ($params['paymill_token'] == null) {
                CRM_Core_Error::fatal(ts('Paymill token is NULL!'));
            }
        } else {
            CRM_Core_Error::fatal(ts('Paymill.js token was not passed!'));
        }

        // Preverim če klient po emailu obstaja
        // Check for existing customer, create new otherwise.
        if (isset($params['email'])) {
            $email = $params['email'];
        } elseif (isset($params['email-5'])) {
            $email = $params['email-5'];
        } elseif (isset($params['email-Primary'])) {
            $email = $params['email-Primary'];
        }


        // Preverim če klient že obstaja
        $clientsObject = new Services_Paymill_Clients($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);

        $clients = $clientsObject->get(array('email' => $email));
        $params['clients'] = $clients;

        if (isset($clients[0]['id'])) {
            $params['client_id'] = $clients[0]['id'];

            // set existing credit card details
            $lastPaymentCount = end(array_keys($clients[0]['payment']));
            $params['lastPaymentCount'] = $lastPaymentCount; // todo DELETE

            $tmpClient = $clients[0];

            $params['payment_last4'] = $tmpClient['payment'][$lastPaymentCount]['last4'];
            $params['payment_expire_month'] = $tmpClient['payment'][$lastPaymentCount]['expire_month'];
            $params['payment_expire_year'] = $tmpClient['payment'][$lastPaymentCount]['expire_year'];

            if ($params['payment_last4'] == $params['cvv2'] && $params['payment_expire_month'] == $params['credit_card_exp_date']['M'] && $params['payment_expire_year'] == $params['credit_card_exp_date']['Y']) {
                $params['payment_id'] = $tmpClient['payment'][$lastPaymentCount]['id'];
            } else {
                $params['payment_id'] = "";
            }
        } else {
            // Kreiram klienta
            $client = $clientsObject->create(array(
                'email' => $email,
                'description' => $params['first_name'] . ' ' . $params['last_name']
            ));

            $params['client_id'] = $client['id'];
            $params['payment_id'] = "";
        }

        // If client doesnt have payment
        if (!isset($params['payment_id']) || $params['payment_id'] == "") {

            // Payment
            $payment_params = array(
                'client' => $params['client_id'],
                'token' => $params['paymill_token']
            );

            $paymentsObject = new Services_Paymill_Payments($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);
            $payment = $paymentsObject->create($payment_params);
            $params['paymentObject'] = $payment;
            $params['payment_id'] = $payment['id'];


            if (isset($payment['id'])) {
                //CRM_Core_Error::fatal(ts('Uspel payment! ' . CRM_Core_Error::debug_var('payment', $creditcard)));
            } else {
                CRM_Core_Error::fatal(ts('Napaka payment! ' . CRM_Core_Error::debug_var('payment', $creditcard) . CRM_Core_Error::debug_var('params', $params)));
            }
        }

        // Handle recurring payments in doRecurPayment().
        if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
            $result = $this->doRecurPayment($params, $amount, $stripe_customer);
        }

        // Transakcija ..
        $transactionsObject = new Services_Paymill_Transactions($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);


        $transaction_params = array(
            'amount' => $params['amount'] * 100, // e.g. "4200" for 42.00 EUR
            'currency' => $params['currencyID'], // ISO 4217
            'client' => $params['client_id'],
            'payment' => $params['creditcard_id'],
            'description' => $params['description']
        );
        $transaction = $transactionsObject->create($transaction_params);


        if (isset($transaction['response_code'])) {
            if ($transaction['response_code'] == 20000) {
                // transakcija ok    
            } else {
                CRM_Core_Error::fatal(ts('Napaka transakcije! ' . $transaction['response_code']));
            }
        } else {
            CRM_Core_Error::fatal(ts('Transakcija ni uspela' . $transaction['response_code']));
        }




        // Success!  Return some values for CiviCRM.
        $params['trxn_id'] = $transaction['id'];
        // Return fees & net amount for Civi reporting.  Thanks Kevin!
        //$params['fee_amount'] = 24;
        //$params['net_amount'] = 23;

        return $params;
    }

    function doRecurPayment(&$params) {

        require_once("Paymill-PHP/lib/Services/Paymill/Offers.php");
        require_once("Paymill-PHP/lib/Services/Paymill/Subscriptions.php");

        switch ($this->_mode) {
            case 'test':
                $transaction_mode = 0;
                break;
            case 'live':
                $transaction_mode = 1;
        }

        $interval = $params['frequency_interval'];
        $frequency = $params['frequency_unit'];
        $installments = $params['installments'];
        $amount = $params['amount'] * 100;

        $offer_name = $interval . " " . strtoupper($frequency) . " " . $params['amount'] . $params['currencyID'];

        // Check if Offer existst
        $offersObject = new Services_Paymill_Offers($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);
        $offer = $offersObject->get(array('offset' => $interval, 'interval' => $frequency, 'amount' => $amount));

        $tmp = CRM_Core_Error::debug('$offer' . $offer);

        if (!isset($offer[0]['id'])) {

            $offerParams = array(
                'amount' => $amount, // E.g. "4200" for 42.00 EUR
                'currency' => $params['currencyID'], // ISO 4217
                'interval' => $interval . " " . strtoupper($frequency),
                'name' => $offer_name
            );
            $offer = $offersObject->create($offerParams);
            $tmp = CRM_Core_Error::debug('$offer' . $offer);
            $params['offer_id'] = $offer['id'];
        } else {
            $params['offer_id'] = $offer[0]['id'];
        }


        // Create subscription
        $subscriptionParams = array(
            'client' => $params['client_id'],
            'offer' => $params['offer_id'],
            'payment' => $params['payment_id']
        );

        // Check if Offer existst
        $subscriptionsObject = new Services_Paymill_Offers($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);
        $subscription = $subscriptionsObject->create($subscriptionParams);





        if (!isset($subscription['id'])) {

            $tmp .= CRM_Core_Error::debug('$offer' . $offer);

            $tmp .= CRM_Core_Error::debug('$subscriptionParams', $subscriptionParams);

            $tmp .= CRM_Core_Error::debug('$subscriptionsObject', $subscriptionsObject);
            $tmp .= CRM_Core_Error::debug('$subscription', $subscription);
            $tmp .= CRM_Core_Error::debug('$subscription', $subscription[0]);
            $tmp .= CRM_Core_Error::debug('$subscription', $subscription['response_code']);

            $tmp .= CRM_Core_Error::debug('$params', $params);

            CRM_Core_Error::fatal(ts('Subscription failed' . $tmp));

            return $params;
        } else {
            $params['subscription_id'] = $subscription[0]['id'];
            return $params;
        }
    }

    /**
     * Sets appropriate parameters for checking out to UCM Payment Collection
     *
     * @param array $params  name value pair of contribution datat
     *
     * @return void
     * @access public
     *
     */
    function doTransferCheckout(&$params, $component) {
        CRM_Core_Error::fatal(ts('This function is not implemented'));
    }

    /**
     * Run Paymill calls through this to catch exceptions gracefully.
     * @param  string $op
     *   Determine which operation to perform.
     * @param  array $params
     *   Parameters to run Paymill calls on.
     * @return varies
     *   Response from gateway.
     */
    function paymillCatchErrors($op = 'create_customer', &$params, $qfKey = '') {
        // @TODO:  Handle all calls through this using $op switching for sanity.
        // Check for errors before trying to submit.
        try {
            switch ($op) {
                case 'create_customer':
                    $return = Stripe_Customer::create($params);
                    break;

                case 'charge':
                    $return = Paymill_Charge::create($params);
                    break;

                case 'save':
                    $return = $params->save();
                    break;

                case 'create_plan':
                    $return = Stripe_Plan::create($params);
                    break;

                default:
                    $return = Stripe_Customer::create($params);
                    break;
            }
        } catch (Stripe_CardError $e) {
            $error_message = '';
            // Since it's a decline, Stripe_CardError will be caught
            $body = $e->getJsonBody();
            $err = $body['error'];

            //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
            ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
            $error_message .= 'Type: ' . $err['type'] . "<br />";
            $error_message .= 'Code: ' . $err['code'] . "<br />";
            $error_message .= 'Message: ' . $err['message'] . "<br />";

            // Check Event vs Contribution for redirect.  There must be a better way.
            if (empty($params['selectMembership']) && empty($params['contributionPageID'])) {
                $error_url = CRM_Utils_System::url('civicrm/event/register', "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
            } else {
                $error_url = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
            }

            CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> $error_message", $error_url);
        } catch (Stripe_InvalidRequestError $e) {
            // Invalid parameters were supplied to Paymill's API
        } catch (Stripe_AuthenticationError $e) {
            // Authentication with Paymill's API failed
            // (maybe you changed API keys recently)
        } catch (Stripe_ApiConnectionError $e) {
            // Network communication with Paymill failed
        } catch (Stripe_Error $e) {
            // Display a very generic error to the user, and maybe send
            // yourself an email
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Paymill
            $error_message = '';
            // Since it's a decline, Stripe_CardError will be caught
            $body = $e->getJsonBody();
            $err = $body['error'];

            //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
            ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
            $error_message .= 'Type: ' . $err['type'] . "<br />";
            $error_message .= 'Code: ' . $err['code'] . "<br />";
            $error_message .= 'Message: ' . $err['message'] . "<br />";

            if (empty($params['selectMembership']) && empty($params['contributionPageID'])) {
                $error_url = CRM_Utils_System::url('civicrm/event/register', "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
            } else {
                $error_url = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
            }

            CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> $error_message", $error_url);
        }

        return $return;
    }

}