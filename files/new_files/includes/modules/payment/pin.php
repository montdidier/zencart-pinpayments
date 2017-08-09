<?php
/**
 * pin.php payment module class for Pin Payments method
 *
 * @package paymentMethod
 * @copyright Copyright 2016 ZenExpert - http://zenexpert.com
 * @copyright ZenExpert - http://zenexpert.com
 * version 1.1 - updated for handling amounts greater than 1000, added installer to avoid manual SQL patch
 */

/**
 *  ensure dependencies are loaded
 */
include_once((IS_ADMIN_FLAG === true ? DIR_FS_CATALOG_MODULES : DIR_WS_MODULES) . 'payment/pin/pin_functions.php');

/**
 * pin.php payment module class
 *
 */
class pin extends base {
    /**
     * $code determines the internal 'code' name used to designate "this" payment module
     *
     * @var string
     */
    var $code;
    /**
     * $title is the displayed name for this payment method
     *
     * @var string
     */
    var $title;
    /**
     * $description is a soft name for this payment method
     *
     * @var string
     */
    var $description;
    /**
     * $enabled determines whether this module shows or not... in catalog.
     *
     * @var boolean
     */
    var $enabled;
    /**
     * log file folder
     *
     * @var string
     */
    var $_logDir = '';
    /**
     * vars
     */
    var $gateway_mode;
    var $reportable_submit_data;
    var $transaction_id;
    var $order_status;


    /**
     * @return pin
     */
    function pin() {
        global $order;

        $this->code = 'pin';
        if (IS_ADMIN_FLAG === true) {
            $this->title = MODULE_PAYMENT_PIN_TEXT_ADMIN_TITLE; // Payment module title in Admin
            if (MODULE_PAYMENT_PIN_TESTMODE == 'Test') {
                $this->title .= '<span class="alert"> (in Testing mode)</span>';
            }
        } else {
            $this->title = MODULE_PAYMENT_PIN_TEXT_CATALOG_TITLE; // Payment module title in Catalog
        }
        $this->description = MODULE_PAYMENT_PIN_TEXT_DESCRIPTION;
        $this->enabled = ((MODULE_PAYMENT_PIN_STATUS == 'True') ? true : false);
        $this->sort_order = MODULE_PAYMENT_PIN_SORT_ORDER;

        if ((int)MODULE_PAYMENT_PIN_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_PIN_ORDER_STATUS_ID;
        }

        $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', false); // Page to go to upon submitting page info

        if (is_object($order)) $this->update_status();

        $this->_logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE;

        // verify table structure
        if (IS_ADMIN_FLAG === true) $this->tableCheckup();

        // Determine default/supported currencies
        if (in_array(DEFAULT_CURRENCY, array('USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD'))) {
            $this->gateway_currency = DEFAULT_CURRENCY;
        } else {
            $this->gateway_currency = 'USD';
        }

    }


    /**
     * compute HMAC-MD5
     *
     * @param string $key
     * @param string $data
     * @return string
     */
    function hmac ($key, $data)
    {
        // RFC 2104 HMAC implementation for php.
        // Creates an md5 HMAC.
        // Eliminates the need to install mhash to compute a HMAC
        // by Lance Rushing

        $b = 64; // byte length for md5
        if (strlen($key) > $b) {
            $key = pack("H*",md5($key));
        }
        $key  = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad ;
        $k_opad = $key ^ $opad;

        return md5($k_opad  . pack("H*",md5($k_ipad . $data)));
    }
    // end code from lance (resume code)

    // class methods
    /**
     * Calculate zone matches and flag settings to determine whether this module should display to customers or not
     */
    function update_status() {
        global $order, $db;

        if ($this->enabled && (int)MODULE_PAYMENT_PIN_ZONE > 0 && isset($order->billing['country']['id'])) {
            $check_flag = false;
            $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PIN_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }
    /**
     * JS validation which does error-checking of data-entry if this module is selected for use
     * (Number, Owner Lengths)
     *
     * @return string
     */
    function javascript_validation() {
        if ($this->gateway_mode == 'offsite') return '';
        $js = '  if (payment_value == "' . $this->code . '") {   ' . "\n" .
            'if (document.checkout_payment.pin_cc_token == undefined ||
                 (document.checkout_payment.pin_cc_token != undefined && document.checkout_payment.pin_cc_token.value=="update_new_token"))
                 { '.
            '    var cc_owner = document.checkout_payment.pin_cc_owner.value;' . "\n" .
            '    var cc_number = document.checkout_payment.pin_cc_number.value;' . "\n";
        //if (MODULE_PAYMENT_PIN_USE_CVV == 'True')  {
            $js .= '    var cc_cvv = document.checkout_payment.pin_cc_cvv.value;' . "\n";
        //}
        $js .= '    if (cc_owner == "" || cc_owner.length < ' . CC_OWNER_MIN_LENGTH . ') {' . "\n" .
            '      error_message = error_message + "' . MODULE_PAYMENT_PIN_TEXT_JS_CC_OWNER . '";' . "\n" .
            '      error = 1;' . "\n" .
            '    }' . "\n" .
            '    if (cc_number == "" || cc_number.length < ' . CC_NUMBER_MIN_LENGTH . ') {' . "\n" .
            '      error_message = error_message + "' . MODULE_PAYMENT_PIN_TEXT_JS_CC_NUMBER . '";' . "\n" .
            '      error = 1;' . "\n" .
            '    }' . "\n";
        //if (MODULE_PAYMENT_PIN_USE_CVV == 'True')  {
            $js .= '    if (cc_cvv == "" || cc_cvv.length < "3" || cc_cvv.length > "4") {' . "\n".
                '      error_message = error_message + "' . MODULE_PAYMENT_PIN_TEXT_JS_CC_CVV . '";' . "\n" .
                '      error = 1;' . "\n" .
                '    }' . "\n" ;
        //}
        $js .= '  } ' . "\n";
        $js .= '   else {' . "\n";

        //if (MODULE_PAYMENT_PIN_USE_CVV == 'True')  {
        $js .= '    var pin_cc_token = document.checkout_payment.pin_cc_token.value;' . "\n";
        $js .= '    var token_cc_cvv = document.getElementById("pintoken-cc-cvv-"+pin_cc_token).value;' . "\n";

        $js .= '    if (token_cc_cvv == "" || token_cc_cvv.length < "3" || token_cc_cvv.length > "4") {' . "\n".
            '      error_message = error_message + "' . MODULE_PAYMENT_PIN_TEXT_JS_CC_CVV . '";' . "\n" .
            '      error = 1;' . "\n" .
            '    }' . "\n" ;
        // }
        $js .= '  }' . "\n";
        $js .= '  }' . "\n";

        return $js;
    }
    /**
     * Display Credit Card Information Submission Fields on the Checkout Payment Page
     *
     * @return array
     */
    function selection() {
        global $order;

        for ($i=1; $i<13; $i++) {
            $expires_month[] = array('id' => sprintf('%02d', $i), 'text' => strftime('%B - (%m)',mktime(0,0,0,$i,1,2000)));
        }

        $today = getdate();
        for ($i=$today['year']; $i < $today['year']+10; $i++) {
            $expires_year[] = array('id' => strftime('%y',mktime(0,0,0,1,1,$i)), 'text' => strftime('%Y',mktime(0,0,0,1,1,$i)));
        }

        $onFocus = ' onfocus="methodSelect(\'pmt-' . $this->code . '\')"';

        if ($this->gateway_mode == 'offsite') {
            $selection = array('id' => $this->code,
                'module' => $this->title);
        } else {
            // show payment title
            $selection = array('id' => $this->code,
                'module' => $this->title,
                'fields' => array());

            if ( count(zen_get_customer_pin_token($_SESSION['customer_id'])) >0 ) {
                $selection['fields'][] =
                    array(
                        'title' => '<strong>Use an existing token</strong>',
                        'field' => '',
                        'tag' => $this->code . '-use-token');

                $tokens = zen_get_customer_pin_token($_SESSION['customer_id']);
                for ($ind = 0; $ind < count($tokens); $ind++) {
                    $selection['fields'][] = array('title' => zen_draw_radio_field('pin_cc_token', $tokens[$ind]["token"], ' autocomplete="off"')." ".$tokens[$ind]["cardinfo"]." ".'<a href="javascript:delPinTokens(\'' . $tokens[$ind]["unique_card"] . '\')">' . zen_image(DIR_WS_IMAGES.'/'.'delete_16x16.gif') . '</a>',
                        'field' => "",
                        'tag' => $this->code . '-cc-token-' . $ind);

//                    $selection['fields'][] = array('title' => $tokens["cardinfo"],
//                        'field' => zen_draw_input_field('pin_token_cc_cvv_' . $tokens[$ind]["token"], '', 'size="4" maxlength="4"' . ' id="' . $this->code . 'token-cc-cvv-' . $tokens[$ind]["token"] . '"' . $onFocus . ' autocomplete="off"') . ' ' . '<a href="javascript:popupWindow(\'' . zen_href_link(FILENAME_POPUP_CVV_HELP) . '\')">' . MODULE_PAYMENT_PIN_TEXT_POPUP_CVV_LINK . '</a>',
//                        'tag' => $this->code . '-cc-cvv-' . $ind);
                }

                // add separate line

                $js = '    <script type="text/javascript">' . "\n";
                $js .= '    function delPinTokens(unique_card){' . "\n";
                $js .= '    var r = confirm("Are you sure you want to delete this token?");' . "\n";
                $js .= '    if (r == true) {$.ajax({'. "\n";
                $js .= '    method: "POST",'. "\n";
                $js .= '    url: "ajax.php",'. "\n";
                $js .= '    data: { delPinTokenAct: "del", token: unique_card },'. "\n";
                $js .= '    success: function(resp){'. "\n";
                $js .= '       window.location.reload();'."\n";
                $js .= '    }'. "\n";
                $js .= '     });}}' . "\n";
                $js .= '    </script>' . "\n";

                $selection['fields'][] = array('title' => "======================================",
                    'field' => ' '.$js,
                    'tag' => '');


                $selection['fields'][] = array(
                    'title' => '<strong>Update with a new credit card</strong>',
                    'field' => zen_draw_radio_field('pin_cc_token', "update_new_token", false),
                    'tag' => $this->code . '-use-token');
            } // end if count tokens gt zero
            $selection['fields'][] =
                array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_OWNER,
                    'field' => zen_draw_input_field('pin_cc_owner', $order->billing['firstname'] . ' ' . $order->billing['lastname'], 'id="'.$this->code.'-cc-owner"' . $onFocus . ' autocomplete="off"'),
                    'tag' => $this->code.'-cc-owner');
            $selection['fields'][] =
                array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_NUMBER,
                    'field' => zen_draw_input_field('pin_cc_number', '', 'id="'.$this->code.'-cc-number"' . $onFocus . ' autocomplete="off"'),
                    'tag' => $this->code.'-cc-number');
            $selection['fields'][] = array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_EXPIRES,
                'field' => zen_draw_pull_down_menu('pin_cc_expires_month', $expires_month, strftime('%m'), 'id="'.$this->code.'-cc-expires-month"' . $onFocus) . '&nbsp;' . zen_draw_pull_down_menu('pin_cc_expires_year', $expires_year, '', 'id="'.$this->code.'-cc-expires-year"' . $onFocus),
                'tag' => $this->code.'-cc-expires-month');
            //if (MODULE_PAYMENT_PIN_USE_CVV == 'True') {
            $selection['fields'][] = array('title' => MODULE_PAYMENT_PIN_TEXT_CVV,
                'field' => zen_draw_input_field('pin_cc_cvv', '', 'size="4" maxlength="4"' . ' id="'.$this->code.'cc-cvv"' . $onFocus . ' autocomplete="off"') . ' ' . '<a href="javascript:popupWindow(\'' . zen_href_link(FILENAME_POPUP_CVV_HELP) . '\')">' . MODULE_PAYMENT_PIN_TEXT_POPUP_CVV_LINK . '</a>',
                'tag' => $this->code.'-cc-cvv');
            //}

            $selection['fields'][] = array('title' => MODULE_PAYMENT_PIN_STORE_TOKEN,
                'field' => zen_draw_checkbox_field('store_token', 'true', false ,'size="4" maxlength="4"' . ' id="'.$this->code.'-store-token"'),
                'tag' => $this->code.'-store-token');
            //}
        }
        return $selection;
    }
    /**
     * Evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
     *
     */
    function pre_confirmation_check() {
        global $messageStack;

        if (isset($_POST['pin_cc_token']) && trim($_POST['pin_cc_token']) !="update_new_token")
        {
            $this->cc_card_type = "";
            $this->cc_card_number = "";
            $this->cc_expiry_month = "";
            $this->cc_expiry_year = "";
        }
        else
        {
            include(DIR_WS_CLASSES . 'cc_validation.php');

            $cc_validation = new cc_validation();
            $result = $cc_validation->validate($_POST['pin_cc_number'], $_POST['pin_cc_expires_month'], $_POST['pin_cc_expires_year']);
            $error = '';
            switch ($result) {
                case -1:
                    $error = sprintf(TEXT_CCVAL_ERROR_UNKNOWN_CARD, substr($cc_validation->cc_number, 0, 4));
                    break;
                case -2:
                case -3:
                case -4:
                    $error = TEXT_CCVAL_ERROR_INVALID_DATE;
                    break;
                case false:
                    $error = TEXT_CCVAL_ERROR_INVALID_NUMBER;
                    break;
            }

            if ( ($result == false) || ($result < 1) ) {
                $messageStack->add_session('checkout_payment', $error . '<!-- ['.$this->code.'] -->', 'error');
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
            }

            $this->cc_card_type = $cc_validation->cc_type;
            $this->cc_card_number = $cc_validation->cc_number;
            $this->cc_expiry_month = $cc_validation->cc_expiry_month;
            $this->cc_expiry_year = $cc_validation->cc_expiry_year;
        }
    }
    /**
     * Display Credit Card Information on the Checkout Confirmation Page
     *
     * @return array
     */
    function confirmation() {
        if (isset($_POST['pin_cc_token']) && trim($_POST['pin_cc_token']) !="update_new_token")
        {
            $confirmation = array(); //array('title' => $this->title);
        }
        else if (isset($_POST['pin_cc_number'])) {
            $confirmation = array('title' => $this->title . ': ' . $this->cc_card_type,
                'fields' => array(array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_OWNER,
                    'field' => $_POST['pin_cc_owner']),
                    array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_NUMBER,
                        'field' => substr($this->cc_card_number, 0, 4) . str_repeat('X', (strlen($this->cc_card_number) - 8)) . substr($this->cc_card_number, -4)),
                    array('title' => MODULE_PAYMENT_PIN_TEXT_CREDIT_CARD_EXPIRES,
                        'field' => strftime('%B, %Y', mktime(0,0,0,$_POST['pin_cc_expires_month'], 1, '20' . $_POST['pin_cc_expires_year'])))));
        } else {
            $confirmation = array(); //array('title' => $this->title);
        }
        return $confirmation;
    }
    /**
     * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
     * This sends the data to the payment gateway for processing.
     * (These are hidden fields on the checkout confirmation page)
     *
     * @return string
     */
    function process_button() {
        if (isset($_POST['pin_cc_token']) && trim($_POST['pin_cc_token'])!='' && trim($_POST['pin_cc_token']) !="update_new_token")
        {
            $process_button_string = zen_draw_hidden_field('cc_token', $_POST['pin_cc_token']);
            $process_button_string .= zen_draw_hidden_field('cc_owner', "") .
                zen_draw_hidden_field('cc_expires',"") .
                zen_draw_hidden_field('cc_type', $this->cc_card_type) .
                zen_draw_hidden_field('cc_number', "").
                zen_draw_hidden_field('cc_expiry_year', "").
                zen_draw_hidden_field('cc_expiry_month', "");
            //if (MODULE_PAYMENT_PIN_USE_CVV == 'True') {
                $process_button_string .= zen_draw_hidden_field('cc_cvv', "");
            //}
        }
        else{
            $process_button_string = zen_draw_hidden_field('cc_owner', $_POST['pin_cc_owner']) .
                zen_draw_hidden_field('cc_expires', $this->cc_expiry_month . substr($this->cc_expiry_year, -2)) .
                zen_draw_hidden_field('cc_type', $this->cc_card_type) .
                zen_draw_hidden_field('cc_number', $this->cc_card_number).
                zen_draw_hidden_field('cc_expiry_year', $this->cc_expiry_year).
                zen_draw_hidden_field('cc_expiry_month', $this->cc_expiry_month);
           // if (MODULE_PAYMENT_PIN_USE_CVV == 'True') {
                $process_button_string .= zen_draw_hidden_field('cc_cvv', $_POST['pin_cc_cvv']);
          //  }

            if (isset($_POST['store_token']) && $_POST['store_token']== 'true' )
            {
                $process_button_string .= zen_draw_hidden_field('store_token', 'true');
            }
        }

        $process_button_string .= zen_draw_hidden_field(zen_session_name(), zen_session_id());

        return $process_button_string;
    }
    /**
     * Store the CC info to the order and process any results that come back from the payment gateway
     *
     */
    function before_process() {
        global $response, $db, $order, $messageStack;


            $order->info['cc_type']    = $_POST['cc_type'];
            $order->info['cc_owner']   = $_POST['cc_owner'];
            $order->info['cc_number']  = trim($_POST['cc_number'])!=''? str_pad(substr($_POST['cc_number'], -4), strlen($_POST['cc_number']), "X", STR_PAD_LEFT):"";
            $order->info['cc_expires'] = '';  // $_POST['cc_expires'];
            $order->info['cc_cvv']     = '***';
            $sessID = zen_session_id();

            // DATA PREPARATION SECTION
            unset($submit_data);  // Cleans out any previous data stored in the variable

            // Create a string that contains a listing of products ordered for the description field
            $description = '';
            for ($i=0; $i<sizeof($order->products); $i++) {
                $description .= $order->products[$i]['name'] . ' (qty: ' . $order->products[$i]['qty'] . ') + ';
            }
            // Remove the last "\n" from the string
            $description = substr($description, 0, -2);

            // Create a variable that holds the order time
            $order_time = date("F j, Y, g:i a");

            // Calculate the next expected order id (adapted from code written by Eric Stamper - 01/30/2004 Released under GPL)
            $last_order_id = $db->Execute("select * from " . TABLE_ORDERS . " order by orders_id desc limit 1");
            $new_order_id = $last_order_id->fields['orders_id'];
            $new_order_id = ($new_order_id + 1);
            $order_id = $new_order_id;

            // add randomized suffix to order id to produce uniqueness ... since it's unwise to submit the same order-number twice to authorize.net
            $new_order_id = (string)$new_order_id . '-' . zen_create_random_value(6, 'chars');


            // Populate an array that contains all of the data to be sent to Authorize.net
            $submit_data = array(
                'x_method' => 'CC',
                'x_amount' => number_format($order->info['total'], 2, '.', ''),
                'x_currency_code' => $order->info['currency'],
                'x_card_num' => $_POST['cc_number'],
                'x_exp_date' => $_POST['cc_expires'],
                'x_exp_month' => $_POST['cc_expiry_month'],
                'x_exp_year' => $_POST['cc_expiry_year'],
                'x_card_code' => $_POST['cc_cvv'],
                'x_card_owner' => $_POST['cc_owner'],
                'x_email_customer' => MODULE_PAYMENT_PIN_EMAIL_CUSTOMER == 'True' ? 'TRUE': 'FALSE',
                'x_cust_id' => $_SESSION['customer_id'],
                'x_invoice_num' => (MODULE_PAYMENT_PIN_TESTMODE == 'Test' ? 'TEST-' : '') . $new_order_id,
                'x_first_name' => $order->billing['firstname'],
                'x_last_name' => $order->billing['lastname'],
                'x_company' => $order->billing['company'],
                'x_address' => $order->billing['street_address'],
                'x_city' => $order->billing['city'],
                'x_state' => $order->billing['state'],
                'x_zip' => $order->billing['postcode'],
                'x_country' => $order->billing['country']['title'],
                'x_phone' => $order->customer['telephone'],
                'x_email' => $order->customer['email_address'],
                'x_ship_to_first_name' => $order->delivery['firstname'],
                'x_ship_to_last_name' => $order->delivery['lastname'],
                'x_ship_to_address' => $order->delivery['street_address'],
                'x_ship_to_city' => $order->delivery['city'],
                'x_ship_to_state' => $order->delivery['state'],
                'x_ship_to_zip' => $order->delivery['postcode'],
                'x_ship_to_country' => $order->delivery['country']['title'],
                'x_description' => $description,
                'x_customer_ip' => zen_get_ip_address(),
                'x_po_num' => date('M-d-Y h:i:s'), //$order->info['po_number'],
                'x_tax' => number_format((float)$order->info['tax'],2),
                // Additional Merchant-defined variables go here
                'Date' => $order_time,
                'IP' => zen_get_ip_address(),
                'Session' => $sessID );

            if (isset($_POST['cc_token']) && trim($_POST['cc_token'])!='' )
            {
                $submit_data['x_cc_token'] = isset($_POST['cc_token'])?$_POST['cc_token']:"";
            }

            if (isset($_POST['store_token']) && $_POST['store_token']== 'true' )
            {
                $submit_data['x_store_token'] =  'true';
            }

            // force conversion to supported currencies: USD, GBP, CAD, EUR, AUD, NZD
            if (!in_array($order->info['currency'], array('USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD', 'ZAR', $this->gateway_currency))) {
                global $currencies;
                $submit_data['x_amount'] = number_format($order->info['total'] * $currencies->get_value($this->gateway_currency), 2);
                $submit_data['x_currency_code'] = $this->gateway_currency;
                unset($submit_data['x_tax'], $submit_data['x_freight']);
            }

            unset($response);

            $response = $this->_sendRequest($submit_data);
            if ($response['status'] == 'success'){
                $sql = "insert into " . TABLE_PIN_ORDER_TOKENS . " (order_id, token, created_date) values (:order_id, :token ,now() )";
                $sql = $db->bindVars($sql, ':order_id', $order_id, 'integer');
                $sql = $db->bindVars($sql, ':token', $response['transactionindex'], 'string');
                $db->Execute($sql);
            }
            else {
                $response_msg_to_customer = $response['message'];
                $messageStack->add_session('checkout_payment', $response_msg_to_customer, 'error');
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
            }

            //$this->_debugActions($submit_data, $response, $order_time, $sessID, $new_order_id);
    }
    /**
     * Post-processing activities
     *
     * @return boolean
     */
    function after_process() {
        global $insert_id, $db;
        $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id, orders_status_id, customer_notified, date_added) values (:orderComments, :orderID, :orderStatus, -1, now() )";
        $sql = $db->bindVars($sql, ':orderComments', 'Pin payments.  AUTH: ' . $this->auth_code . '. TransID: ' . $this->transaction_id . '.', 'string');
        $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
        $sql = $db->bindVars($sql, ':orderStatus', $this->order_status, 'integer');
        $db->Execute($sql);
        return false;
    }
    /**
     * Check to see whether module is installed
     *
     * @return boolean
     */
    function check() {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PIN_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }
    /**
     * Install the payment module and its configuration settings
     *
     */
    function install() {
        global $db, $messageStack;
        if (defined('MODULE_PAYMENT_PIN_STATUS')) {
            $messageStack->add_session('Pin Payments module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=pin', 'NONSSL'));
            return 'failed';
        }
        
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Pin Payments Module', 'MODULE_PAYMENT_PIN_STATUS', 'True', 'Do you want to accept pin payments?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('Transaction Key', 'MODULE_PAYMENT_PIN_TXNKEY', 'Test', 'Your Secret Key', '6', '0', now(), 'zen_cfg_password_display')");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction Mode', 'MODULE_PAYMENT_PIN_TESTMODE', 'Test', 'Transaction mode used for processing orders', '6', '0', 'zen_cfg_select_option(array(\'Test\', \'Live\'), ', now())");
        //$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Request CVV Number', 'MODULE_PAYMENT_PIN_USE_CVV', 'False', 'Do you want to ask the customer for the card\'s CVV number', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Customer Notifications', 'MODULE_PAYMENT_PIN_EMAIL_CUSTOMER', 'False', 'Should Pin payments email a receipt to the customer?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PIN_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_PIN_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_PIN_ORDER_STATUS_ID', '1', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug Mode', 'MODULE_PAYMENT_PIN_DEBUGGING', 'Alerts Only', 'Would you like to enable debug mode?  A  detailed log of failed transactions may be emailed to the store owner.', '6', '0', 'zen_cfg_select_option(array(\'Off\', \'Alerts Only\', \'Log File\', \'Log and Email\'), ', now())");
        $db->Execute("CREATE TABLE IF NOT EXISTS `pin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL DEFAULT '0',
  `order_id` int(11) NOT NULL DEFAULT '0',
  `response_code` int(1) DEFAULT '0',
  `response_text` varchar(255) DEFAULT '',
  `authorization_type` varchar(50) DEFAULT '',
  `transaction_id` varchar(64) DEFAULT NULL,
  `sent` longtext NOT NULL,
  `received` longtext NOT NULL,
  `time` varchar(50) DEFAULT '',
  `session_id` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) AUTO_INCREMENT=1");
        $db->Execute("CREATE TABLE IF NOT EXISTS `pin_order_token` (
  `pin_order_token` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_date` date NOT NULL,
  PRIMARY KEY (`pin_order_token`)
) AUTO_INCREMENT=1");
        $db->Execute("CREATE TABLE IF NOT EXISTS `pin_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `card_token` varchar(64) DEFAULT NULL,
  `cardinfo` varchar(32) DEFAULT NULL,
  `unique_card` varchar(64) NOT NULL,
  `date_added` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `customer_id` (`customer_id`)
) AUTO_INCREMENT=1");
    }
    /**
     * Remove the module and all its settings
     *
     */
    function remove() {
        global $db, $messageStack;
        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key LIKE '%PIN%'");
        $messageStack->add_session('Pin Payments module successfully REMOVED. Database tables used by Pin Payments were not removed for security reasons. If you want to delete these tables, please do it manually using phpMyAdmin or similar tools.', 'success');
    }
    /**
     * Internal list of configuration keys used for configuration of the module
     *
     * @return array
     */
    function keys() {
        return array('MODULE_PAYMENT_PIN_STATUS','MODULE_PAYMENT_PIN_TXNKEY', 'MODULE_PAYMENT_PIN_TESTMODE',  'MODULE_PAYMENT_PIN_EMAIL_CUSTOMER', 'MODULE_PAYMENT_PIN_ZONE', 'MODULE_PAYMENT_PIN_ORDER_STATUS_ID', 'MODULE_PAYMENT_PIN_SORT_ORDER', 'MODULE_PAYMENT_PIN_GATEWAY_MODE', 'MODULE_PAYMENT_PIN_STORE_DATA', 'MODULE_PAYMENT_PIN_DEBUGGING');
    }
    /**
     * Calculate validity of response
     */
    function calc_md5_response($trans_id = '', $amount = '') {
        if ($amount == '' || $amount == '0') $amount = '0.00';
        $validating = md5(MODULE_PAYMENT_PIN_MD5HASH . MODULE_PAYMENT_PIN_LOGIN . $trans_id . $amount);
        return strtoupper($validating);
    }
    /**
     * Used to do any debug logging / tracking / storage as required.
     */
    function _debugActions($submit_data, $response, $order_time= '', $sessID = '', $new_order_id) {
        global $db, $messageStack;
        if ($order_time == '') $order_time = date("F j, Y, g:i a");

        if (MODULE_PAYMENT_PIN_TESTMODE == 'Live' || !strstr(MODULE_PAYMENT_PIN_TESTMODE, 'Test')) {
            $this->form_action_url = 'https://api.pin.net.au';
        } else {
            $this->form_action_url = 'https://test-api.pin.net.au';
        }
        $response['url'] = $this->form_action_url;

        $this->reportable_submit_data = $submit_data;
        if (isset($this->reportable_submit_data['x_card_num'])) $this->reportable_submit_data['x_card_num'] = str_repeat('X', strlen($this->reportable_submit_data['x_card_num'] - 4)) . substr($this->reportable_submit_data['x_card_num'], -4);
        if (isset($this->reportable_submit_data['x_exp_date'])) $this->reportable_submit_data['x_exp_date'] = '****';
        if (isset($this->reportable_submit_data['x_card_code'])) $this->reportable_submit_data['x_card_code'] = '****';
        if (isset($this->reportable_submit_data['x_exp_month'])) $this->reportable_submit_data['x_exp_month'] = '****';
        if (isset($this->reportable_submit_data['x_exp_year'])) $this->reportable_submit_data['x_exp_year'] = '****';
        if (isset($this->reportable_submit_data['x_card_owner'])) $this->reportable_submit_data['x_card_owner'] = '****';

        $this->reportable_submit_data['url'] = $this->form_action_url;

        $errorMessage = date('M-d-Y h:i:s') .
            "\n=================================\n\n";
        $errorMessage .=
            'Sent to Pin Payments: ' . print_r($this->reportable_submit_data, true) . "\n\n";
        // DATABASE SECTION
        // Insert the send and receive response data into the database.
        // This can be used for testing or for implementation in other applications
        // This can be turned on and off if the Admin Section

        // Insert the data into the database
        $sql = "insert into " . TABLE_PIN . "  (id, customer_id, order_id, authorization_type, transaction_id, sent, received, time, session_id) values (NULL, :custID, :orderID, :authType, :transID, :sentData, :recvData, :orderTime, :sessID )";
        $sql = $db->bindVars($sql, ':custID', $_SESSION['customer_id'], 'integer');
        $sql = $db->bindVars($sql, ':orderID', preg_replace('/[^0-9]/', '', (int)$new_order_id), 'integer');
        $sql = $db->bindVars($sql, ':authType', 'CREDIT', 'string');
        $sql = $db->bindVars($sql, ':transID', transaction_id, 'string');
        $sql = $db->bindVars($sql, ':sentData', print_r($this->reportable_submit_data, true), 'string');
        $sql = $db->bindVars($sql, ':recvData', print_r($response, true), 'string');
        $sql = $db->bindVars($sql, ':orderTime', $order_time, 'string');
        $sql = $db->bindVars($sql, ':sessID', $sessID, 'string');
        $db->Execute($sql);

    }
    /**
     * Check and fix table structure if appropriate
     */
    function tableCheckup() {
        global $db, $sniffer, $messageStack;
        $pintable = (method_exists($sniffer, 'table_exists')) ? ($sniffer->table_exists(TABLE_PIN) ? '' : $messageStack->add_session('Pin Payments database tables not detected. Please run the SQL patch manually.', 'error')) : -1;
//        $fieldOkay1 = (method_exists($sniffer, 'field_type')) ? $sniffer->field_type(TABLE_PIN, 'transaction_id', 'bigint(20)', true) : -1;
//        if ($fieldOkay1 !== true) {
//            $db->Execute("ALTER TABLE " . TABLE_PIN . " CHANGE transaction_id transaction_id varchar(64) default NULL");
//        }
    }

    /**
     * Send communication request
     */
    private function _sendRequest($submit_data)
    {
        global $request_type, $db;

        include_once(DIR_WS_MODULES . "payment/pin/autoload.php");
        include_once(DIR_WS_MODULES . "payment/pin/omnipay/pin/src/Gateway.php");

        $gateway =  \Omnipay\Common\GatewayFactory::create('Pin');

        if (MODULE_PAYMENT_PIN_TESTMODE == 'Live' || !strstr(MODULE_PAYMENT_PIN_TESTMODE, 'Test')) {
            $testmode = false;
        } else {
            $testmode = true;
        }
        $gateway->initialize(array(
            'secretKey' => MODULE_PAYMENT_PIN_TXNKEY,
            'testMode'  => $testmode, // Or false when you are ready for live transactions
        ));

        try {
            $cardinfo  = "";
            if (isset($submit_data['x_cc_token']) && trim($submit_data['x_cc_token']) != '') {
                $transaction = $gateway->purchase(array(
                    'email'       => $submit_data['x_email'],
                    'description' => $submit_data['x_description'],
                    'amount'      => $submit_data['x_amount'],
                    'currency'    => $submit_data['x_currency_code'],
                    'token'       => $submit_data['x_cc_token'], //'cus_lKWlmZ53Gbw0wOQ3gUaqPA',
                    'ip_address'  => $_SERVER['REMOTE_ADDR']
                ));
            } else {
                $card = new \Omnipay\Common\CreditCard(array(
                    'name'         => $submit_data['x_card_owner'],
                    'number'       => $submit_data['x_card_num'],
                    'expiryMonth'  => strlen($submit_data['x_exp_month'])< 2 ? '0'+$submit_data['x_exp_month']: $submit_data['x_exp_month'],
                    'expiryYear'   => $submit_data['x_exp_year'],
                    'cvv'          => $submit_data['x_card_code'],
                    'email'        => $submit_data['x_email'],
                    'billingAddress1'       => $submit_data['x_address'],
                    'billingCountry'        => $submit_data['x_country'],
                    'billingCity'           => $submit_data['x_city'],
                    'billingPostcode'       => $submit_data['x_zip'],
                    'billingState'          => $submit_data['x_state']
                ));

                // if the use wants to create the card token

                $cus_id = $card_id = $generated_token = '';
                if (isset($submit_data['x_store_token']) && $submit_data['x_store_token'])
                {
                    $generated_token = $this->hmac(MODULE_PAYMENT_PIN_TXNKEY, strtolower($submit_data['x_card_owner'] . $submit_data['x_card_num'] . $submit_data['x_exp_month'] . $submit_data['x_exp_year']));
                    $card_token = zen_check_existing_customer_pin_token($_SESSION['customer_id'], $generated_token);
                    if ($card_token === false)
                    {
                        $response = $gateway->createCard(array(
                            'card'      => $card,
                        ))->send();

                        if ($response->isSuccessful()) {
                            // Find the card ID
                            $card_id = $response->getCardReference();

                            // =========================  associate card token with customer details =========================

                            $response2 = $gateway->createCustomer(array(
                                'email'      => $submit_data['x_email'],
                                'token'      => $card_id
                            ))->send();

                            if ($response2->isSuccessful()) {
                                // Find the customer ID
                                $cus_id = $response2->getCustomerReference();

                                $transaction = $gateway->purchase(array(
                                    'email'       => $submit_data['x_email'],
                                    'description' => $submit_data['x_description'],
                                    'amount'      => $submit_data['x_amount'],
                                    'currency'    => $submit_data['x_currency_code'],
                                    'token'       => $cus_id,
                                    'ip_address'  => $_SERVER['REMOTE_ADDR']
                                ));

                            } else {
                                throw new Exception("Error message == " . $response2->getMessage());
                            }

                        } else {
                            throw new Exception("Error message == " . $response->getMessage());
                        }
                    }
                    else
                    {
                        $transaction = $gateway->purchase(array(
                            'email'       => $submit_data['x_email'],
                            'description' => $submit_data['x_description'],
                            'amount'      => $submit_data['x_amount'],
                            'currency'    => $submit_data['x_currency_code'],
                            'token'       => $card_token,
                            'ip_address'  => $_SERVER['REMOTE_ADDR']
                        ));
                    }
                }
                else {
                    $transaction = $gateway->purchase(array(
                        'description'              => $submit_data['x_description'],
                        'amount'                   => $submit_data['x_amount'],
                        'currency'                 => $submit_data['x_currency_code'],
                        'ip_address'               => $_SERVER['REMOTE_ADDR'],
                        'card'                     => $card,
                    ));
                }
            }


            $response = $transaction->send();

            if ($response->isSuccessful()) {

                $sale_id = $response->getTransactionReference();
                $token = $response->getTransactionReference();


                if (isset($submit_data["x_store_token"]) && trim($cus_id) != "")
                {
                    $cardinfo = $response->getScheme();
                    $cardinfo .= " (";
                    $cardinfo .= "xxxx";
                    $cardinfo .= substr($submit_data['x_card_num'], strlen($submit_data['x_card_num']) - 4, 4);
                    $cardinfo .= ")";

                    if ($cardinfo!=""){
                        $sql = "insert into " . TABLE_PIN_TOKENS . " (token, customer_id, cardinfo, unique_card, card_token, date_added) values (:token, :customer_id, :cardinfo, :unique_card, :card_token, now() )";
                        $sql = $db->bindVars($sql, ':token', $cus_id, 'string');
                        $sql = $db->bindVars($sql, ':customer_id', $_SESSION['customer_id'], 'integer');
                        $sql = $db->bindVars($sql, ':cardinfo', $cardinfo, 'string');
                        $sql = $db->bindVars($sql, ':unique_card', $generated_token, 'string');
                        $sql = $db->bindVars($sql, ':card_token', $card_id, 'string');
                        $db->Execute($sql);
                    }
                }

                $response = array('status' => "success", 'transactionindex'=> $sale_id);
                return $response;
            }
            else{
                throw new Exception("Error message == " . $response->getMessage());
            }
        } catch (Exception $e) {
            return array('status'=> "error", "message"=> $e->getMessage());
        }
    }

    private function _sendRefundRequest($submit_data)
    {
        global $request_type, $db;

        include_once(DIR_FS_CATALOG_MODULES . "payment/pin/autoload.php");
        include_once(DIR_FS_CATALOG_MODULES . "payment/pin/omnipay/pin/src/Gateway.php");

        $gateway = \Omnipay\Common\GatewayFactory::create('Pin');

        if (MODULE_PAYMENT_PIN_TESTMODE == 'Live' || !strstr(MODULE_PAYMENT_PIN_TESTMODE, 'Test')) {
            $testmode = false;
        } else {
            $testmode = true;
        }
        $gateway->initialize(array(
            'secretKey' => MODULE_PAYMENT_PIN_TXNKEY,
            'testMode' => $testmode, // Or false when you are ready for live transactions
        ));

        return $gateway->refund($submit_data)->send();

    }


    public function deregisterToken($customer_id='', $token='')
    {
        global $db;

//        include_once(DIR_WS_MODULES . "payment/pin/autoload.php");
//        include_once(DIR_WS_MODULES . "payment/pin/omnipay/stripe/src/Gateway.php");
//
//        $stripe =  Omnipay\Stripe\Gateway('Gateway');
//
//        if (MODULE_PAYMENT_PIN_TESTMODE == 'Live' || !strstr(MODULE_PAYMENT_PIN_TESTMODE, 'Test')) {
//            $testmode = false;
//        } else {
//            $testmode = true;
//        }
//        $stripe->initialize(array(
//            'apiKey' => MODULE_PAYMENT_PIN_TXNKEY,
//            'testMode'  => $testmode, // Or false when you are ready for live transactions
//        ));

        try {
            if (zen_check_existing_customer_pin_token($customer_id, $token) !==false)
            {

                //$stripe->deleteCustomer();
                zen_del_customer_pin_token($customer_id, $token);

            }
        } catch (Exception $e) {
            return array('status'=> "error", "message"=> $e->getMessage());
        }

    }

     public function _doRefund($oID, $type='full', $amount = '', $akey='', $currency = 'USD') {
        global $db, $messageStack, $currencies;

        $refundNote = "";
        /**
         * Submit refund request to PayPal
         */
        try {
            $data = array(
                'transactionReference'      => $akey,
                'amount' => (string)number_format((float)$amount, 2, '.', '')
            );

            $response = $this->_sendRefundRequest($data);

            $tran_id = $response->getTransactionReference();

            if ($response->isSuccessful()) {
                // Success, so save the results
                $sql_data_array = array('orders_id' => $oID,
                    'date_added' => 'now()',
                    'comments' => 'REFUND INITIATED. Trans ID:' . $tran_id .  "\n" . ' Gross Refund Amt: ' . urldecode($amount > 0 ? $amount. ' ' . $currency : 'full'),
                    'customer_notified' => 0
                );
                zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
                $messageStack->add_session(sprintf(MODULE_PAYMENT_PIN_TEXT_REFUND_INITIATED, urldecode($amount > 0 ? $amount. ' ' . $currency : 'full'), urldecode($tran_id)), 'success');
                return true;
            }
            else{
                $sql_data_array = array('orders_id' => $oID,
                    'date_added' => 'now()',
                    'comments' => 'REFUND INITIATED. Trans ID:' . $tran_id .  "\n" . ' Gross Refund Amt: ' . urldecode($amount > 0 ? $amount. ' ' . $currency : 'full'). "  FAILED: ".$response->getMessage(),
                    'customer_notified' => 0
                );
                zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
                $messageStack->add_session(sprintf(MODULE_PAYMENT_PIN_TEXT_REFUND_INITIATED, urldecode($amount > 0 ? $amount. ' ' . $currency : 'full'), urldecode($tran_id)), 'error');
                return false;
            }
        }
        catch(Exception $e)
        {
            return false;
        }
    }
}
?>