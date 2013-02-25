
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
        $this->_processorName = ts('Paymill Payment Collection');
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
            $error[] = ts('The "Secret Key" is not set in the Stripe Payment Processor settings.');
        }

        if (empty($this->_paymentProcessor['password'])) {
            $error[] = ts('The "Publishable Key" is not set in the Stripe Payment Processor settings.');
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

}