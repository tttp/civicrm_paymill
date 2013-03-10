
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


        $transactionsObject = new Services_Paymill_Transactions($this->_paymentProcessor['user_name'], $this->_paymentProcessor['url_site']);


        echo "<br><br>";


     
        
        if (isset($params['paymill_token'])) {
            $card_details = $params['paymill_token'];
            if ($params['paymill_token'] == null) {
                CRM_Core_Error::fatal(ts('Paymill token is NULL!'));
            }
        } else {
            CRM_Core_Error::fatal(ts('Stripe.js token was not passed!'));
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
                    $return = Stripe_Charge::create($params);
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