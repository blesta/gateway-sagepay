<?php
/**
 * Sage Pay Credit Card processing gateway. Supports onsite payment processing for
 * Credit Cards.
 *
 * The Sage Pay API can be found at:
 *  http://integrations.sagepay.co.uk/content/getting-started-submit-payment-your-server
 *
 * @package blesta
 * @subpackage blesta.components.gateways.sagepay
 * @copyright Copyright (c) 2017, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Sagepay extends MerchantGateway implements MerchantCc
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * @var array An array of test and live API URLs
     */
    private $api_urls = [
        'test' => [
            'process' => 'https://pi-test.sagepay.com/api/v1/transactions',
            'refund' => 'https://pi-test.sagepay.com/api/v1/transactions',
            'identifier' => 'https://pi-test.sagepay.com/api/v1/card-identifiers',
            'session' => 'https://pi-test.sagepay.com/api/v1/merchant-session-keys'
        ],
        'live' => [
            'process' => 'https://pi-live.sagepay.com/api/v1/transactions',
            'refund' => 'https://pi-live.sagepay.com/api/v1/transactions',
            'identifier' => 'https://pi-live.sagepay.com/api/v1/card-identifiers',
            'session' => 'https://pi-live.sagepay.com/api/v1/merchant-session-keys'
        ]
    ];

    /**
     * Construct a new merchant gateway.
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('sagepay', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Attempt to install this gateway.
     */
    public function install()
    {
        // Ensure that the system has support for the JSON extension
        if (!function_exists('json_decode')) {
            $errors = [
                'json' => [
                    'required' => Language::_('Stripe_gateway.!error.json_required', true)
                ]
            ];
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Sets the currency code to be used for all subsequent payments.
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway.
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'merchant' . DS . 'sagepay' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway.
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        // Verify meta data is valid
        $rules = [
            'vendor_name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Sagepay.!error.vendor_name.empty', true)
                ]
            ],
            'integration_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Sagepay.!error.integration_key.empty', true)
                ]
            ],
            'integration_password' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Sagepay.!error.integration_password.empty', true)
                ]
            ],
            'developer_mode' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Sagepay.!error.developer_mode.valid', true)
                ]
            ]
        ];

        // Set checkbox if not set
        if (!isset($meta['developer_mode'])) {
            $meta['developer_mode'] = 'false';
        }

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);
        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database.
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['integration_key', 'integration_password'];
    }

    /**
     * Sets the meta data for this particular gateway.
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Used to determine whether this gateway can be configured for autodebiting accounts.
     *
     * @return bool True if the customer must be present
     *  (e.g. in the case of credit card customer must enter security code), false otherwise
     */
    public function requiresCustomerPresent()
    {
        return false;
    }

    /**
     * Charge a credit card.
     *
     * @param array $card_info An array of credit card info including:
     *  - first_name The first name on the card
     *  - last_name The last name on the card
     *  - card_number The card number
     *  - card_exp The card expiration date in yyyymm format
     *  - card_security_code The 3 or 4 digit security code of the card (if available)
     *  - type The credit card type
     *  - address1 The address 1 line of the card holder
     *  - address2 The address 2 line of the card holder
     *  - city The city of the card holder
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the state
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-character country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the card holder
     * @param float $amount The amount to charge this card
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, error, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function processCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        // Attempt to process this sale transaction
        $action = 'process';

        return $this->processTransaction(
            $this->getRequestUrl($action),
            $this->getFields($action, null, $amount, $card_info)
        );
    }

    /**
     * Authorize a credit card.
     *
     * @param array $card_info An array of credit card info including:
     *  - first_name The first name on the card
     *  - last_name The last name on the card
     *  - card_number The card number
     *  - card_exp The card expiration date in yyyymm format
     *  - card_security_code The 3 or 4 digit security code of the card (if available)
     *  - type The credit card type
     *  - address1 The address 1 line of the card holder
     *  - address2 The address 2 line of the card holder
     *  - city The city of the card holder
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-cahracter country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the card holder
     * @param float $amount The amount to charge this card
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, error, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function authorizeCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Capture the funds of a previously authorized credit card.
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @param float $amount The amount to capture on this card
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, error, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function captureCc($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a credit card charge.
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, error, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function voidCc($reference_id, $transaction_id)
    {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Refund a credit card charge.
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @param float $amount The amount to refund this card
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, error, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refundCc($reference_id, $transaction_id, $amount)
    {
        // Refund this payment transaction
        $action = 'refund';

        $result = $this->processTransaction(
            $this->getRequestUrl($action),
            $this->getFields($action, $transaction_id, $amount)
        );

        // An approved refunded transaction should have a status of refunded
        if ($result['status'] == 'approved') {
            $result['status'] = 'refunded';
        }

        return $result;
    }

    /**
     * Constructs the JSON and fields to be sent to Sage Pay.
     *
     * @param string $transaction_type The type of transaction to perform ("process", "refund")
     * @param int $transaction_id The ID of a previous transaction if available
     * @param float $amount The amount to charge this card
     * @param array $card_info An array of credit card info including:
     *  - first_name The first name on the card
     *  - last_name The last name on the card
     *  - card_number The card number
     *  - card_exp The card expiration date in yyyymm format
     *  - card_security_code The 3 or 4 digit security code of the card (if available)
     *  - type The credit card type
     *  - address1 The address 1 line of the card holder
     *  - address2 The address 2 line of the card holder
     *  - city The city of the card holder
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the state
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-character country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the card holder
     * @return array A list of fields and their JSON including:
     *  - fields A list of fields to be sent
     *  - json The list of fields in JSON format
     */
    private function getFields($transaction_type, $transaction_id = null, $amount = null, array $card_info = null)
    {
        $transaction_types = [
            'process' => 'Payment',
            'refund' => 'Refund'
        ];

        // Generate a merchant session key
        $merchant_session_key = $this->getMerchantSessionKey($this->getRequestUrl('session'));

        // Generate the card identifier
        $card_identifier = $this->getCardIdentifier(
            $this->getRequestUrl('identifier'),
            $card_info,
            $merchant_session_key
        );

        // Generate a unique ID
        $unique_id = uniqid();

        // Create a list of all possible parameters
        $params = [
            'transactionType' => (isset($transaction_types[$transaction_type]) ? $transaction_types[$transaction_type] : null),
            'referenceTransactionId' => ($transaction_id ?? null),
            'paymentMethod' => [
                'card' => [
                    'merchantSessionKey' => ($merchant_session_key ?? null),
                    'cardIdentifier' => ($card_identifier ?? null)
                ]
            ],
            'vendorTxCode' => (isset($transaction_id) ? $transaction_id : $unique_id),
            'amount' => (is_numeric($amount) ? 100 * $amount : $amount), // Amount must be in cents
            'currency' => ($this->currency ?? null),
            'description' => (isset($transaction_id) ? $transaction_id : $unique_id),
            'apply3DSecure' => 'Disable',
            'customerFirstName' => (isset($card_info['first_name']) ? $card_info['first_name'] : null),
            'customerLastName' => (isset($card_info['last_name']) ? $card_info['last_name'] : null),
            'billingAddress' => [
                'address1' => (isset($card_info['address1']) ? $card_info['address1'] : null),
                'city' => (isset($card_info['city']) ? $card_info['city'] : null),
                'postalCode' => (isset($card_info['zip']) ? $card_info['zip'] : null),
                'country' => (isset($card_info['country']['alpha2']) ? $card_info['country']['alpha2'] : null)
            ],
            'entryMethod' => 'Ecommerce'
        ];

        // The state field is required for billing in the United States
        if ($params['billingAddress']['country'] == 'US') {
            $params['billingAddress']['state'] = (isset($card_info['state']['code']) ? $card_info['state']['code'] : null);
        }

        $required_fields = [];

        // Set which fields are required to be sent
        switch ($transaction_type) {
            case 'process':
                $required_fields = [
                    'transactionType','paymentMethod','vendorTxCode',
                    'amount','currency','description', 'apply3DSecure',
                    'customerFirstName','customerLastName','billingAddress',
                    'entryMethod'
                ];
                break;
            case 'refund':
                $required_fields = [
                    'transactionType','referenceTransactionId','vendorTxCode',
                    'amount','description','description'
                ];
                break;
        }

        // Remove the fields that are not required
        foreach ($params as $key => $value) {
            if (!in_array($key, $required_fields)) {
                unset($params[$key]);
            }
        }

        // Build the JSON
        return [
            'fields' => $params,
            'json' => $this->buildJson($params)
        ];
    }

    /**
     * Builds the JSON request to be sent to Sage Pay.
     *
     * @param array $fields A list of fields to pass to Sage Pay
     * @return string The constructed JSON
     */
    private function buildJson(array $fields)
    {
        $response = '';

        // Ensure that the system has support for the JSON extension
        if (function_exists('json_decode')) {
            $response = json_encode($fields);
        }

        return $response;
    }

    /**
     * Processes a transaction.
     *
     * @param string The URL to post to
     * @param array A list of fields and json including:
     *  - fields A list of fields used to construct the JSON
     *  - json The JSON constructed from fields
     * @param mixed $url
     * @return array A list of response key=>value pairs including:
     *  - status (approved, declined, or error)
     *  - reference_id
     *  - transaction_id
     *  - message
     */
    private function processTransaction($url, array $fields)
    {
        // Load the NET component, if not already loaded
        if (!isset($this->Net)) {
            Loader::loadComponents($this, ['Net']);
        }

        $this->Http = $this->Net->create('Http');

        // Submit the request
        $this->Http->setHeader('Content-Type: application/json');
        $this->Http->setHeader(
            'Authorization: Basic '
            . base64_encode($this->meta['integration_key'] . ':' . $this->meta['integration_password'])
        );
        $response = $this->Http->post($url, (isset($fields['json']) ? $fields['json'] : null));

        // Parse the response
        $response = $this->parseResponse($response);

        // Log the transaction (with the parsed response and unbuilt request fields)
        $this->logRequest((isset($fields['fields']) ? $fields['fields'] : null), $response, $url);

        // Set the response status
        $response_status = $this->getTransactionStatus($response);
        $status = $response_status['status'];

        // Set general error if status is error
        if ($status == 'error') {
            $this->Input->setErrors($this->getCommonError('general'));
        }

        return [
            'status' => $status,
            'reference_id' => (isset($response['retrievalReference']) ? $response['retrievalReference'] : null),
            'transaction_id' => (isset($response['transactionId']) ? $response['transactionId'] : null),
            'message' => $response_status['message']
        ];
    }

    /**
     * Retrieves the transaction status (approved, declined, error) based on the
     * response from the gateway.
     *
     * @param array $response A list of key/value pairs representing the response from the gateway
     * @return array The transaction status, including:
     *  - status (approved, declined, or error)
     *  - message The response message
     */
    private function getTransactionStatus(array $response)
    {
        // Assume status is an error
        $status = [
            'status' => 'error',
            'message' => (isset($response['statusDetail']) ? $response['statusDetail'] : null)
        ];

        // Check the response status
        if (isset($response['status'])) {
            if (strtolower($response['status']) == 'ok') {
                $status['status'] = 'approved';
            } else {
                $status['status'] = 'declined';
            }
        }

        return $status;
    }

    /**
     * Generates the card identifier.
     *
     * @param string $url The URL to post to
     * @param array $card_info An array of credit card info including:
     *  - first_name The first name on the card
     *  - last_name The last name on the card
     *  - card_number The card number
     *  - card_exp The card expiration date in yyyymm format
     *  - card_security_code The 3 or 4 digit security code of the card (if available)
     *  - type The credit card type
     *  - address1 The address 1 line of the card holder
     *  - address2 The address 2 line of the card holder
     *  - city The city of the card holder
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the state
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-character country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the card holder
     * @param string $merchant_session_key The merchant session key
     * @return string The card identifier
     */
    private function getCardIdentifier($url, $card_info, $merchant_session_key)
    {
        // Load the NET component, if not already loaded
        if (!isset($this->Net)) {
            Loader::loadComponents($this, ['Net']);
        }

        $this->Http = $this->Net->create('Http');

        // Build parameters array
        $params = $this->buildJson([
            'cardDetails' => [
                'cardholderName' => (isset($card_info['first_name']) ? $card_info['first_name'] : null)
                    . ' '
                    . (isset($card_info['last_name']) ? $card_info['last_name'] : null),
                'cardNumber' => (isset($card_info['card_number']) ? $card_info['card_number'] : null),
                'expiryDate' => substr((isset($card_info['card_exp']) ? $card_info['card_exp'] : null), 4, 2)
                    . substr((isset($card_info['card_exp']) ? $card_info['card_exp'] : null), 2, 2),
                'securityCode' => (isset($card_info['card_security_code']) ? $card_info['card_security_code'] : null)
            ]
        ]);

        // Submit the request
        $this->Http->setHeader('Content-Type: application/json');
        $this->Http->setHeader('Authorization: Bearer ' . $merchant_session_key);
        $response = $this->Http->post($url, $params);

        // Parse the response
        $response = $this->parseResponse($response);

        return (isset($response['cardIdentifier']) ? $response['cardIdentifier'] : null);
    }

    /**
     * Creates a merchant session key.
     *
     * @param string $url The URL to post to
     * @return string The merchant session key
     */
    private function getMerchantSessionKey($url)
    {
        // Load the NET component, if not already loaded
        if (!isset($this->Net)) {
            Loader::loadComponents($this, ['Net']);
        }

        $this->Http = $this->Net->create('Http');

        // Build parameters array
        $params = $this->buildJson([
            'vendorName' => (isset($this->meta['vendor_name']) ? $this->meta['vendor_name'] : null)
        ]);

        // Submit the request
        $this->Http->setHeader('Content-Type: application/json');
        $this->Http->setHeader(
            'Authorization: Basic '
            . base64_encode($this->meta['integration_key'] . ':' . $this->meta['integration_password'])
        );
        $response = $this->Http->post($url, $params);

        // Parse the response
        $response = $this->parseResponse($response);

        return (isset($response['merchantSessionKey']) ? $response['merchantSessionKey'] : null);
    }

    /**
     * Parses the response from the gateway into an associative array.
     *
     * @param string $response The response from the gateway
     * @return array A list of key/value pairs representing the response from the gateway
     */
    private function parseResponse($response)
    {
        return (array) json_decode($response);
    }

    /**
     * Log the request.
     *
     * @param array The input parameters sent to the gateway
     * @param array The response from the gateway
     * @param string $url The URL of the request was sent to
     * @param mixed $params
     * @param mixed $response
     */
    private function logRequest($params, $response, $url)
    {
        $mask_fields = [
            'merchantSessionKey',
            'cardIdentifier'
        ];

        // Determine response status from gateway
        $response_status = $this->getTransactionStatus($response);
        $success = ($response_status['status'] == 'approved');

        // Log data sent to the gateway
        $this->log($url, serialize($this->maskDataRecursive($params, $mask_fields)), 'input', true);

        // Log response from the gateway
        $this->log($url, serialize($this->maskDataRecursive($response, $mask_fields)), 'output', $success);
    }

    /**
     * Retrieves the API URL to post to based on the action.
     *
     * @param string $transaction_type The type of transaction to perform ("process", "refund")
     * @return string The URL to post to
     */
    private function getRequestUrl($transaction_type)
    {
        $url = '';

        // Use live mode only if developer is not set
        $test_mode = 'test';
        if ((isset($this->meta['developer_mode']) ? $this->meta['developer_mode'] : null) == 'false') {
            $test_mode = 'live';
        }

        switch ($transaction_type) {
            case 'process':
            case 'refund':
            case 'identifier':
            case 'session':
                $url = $this->api_urls[$test_mode][$transaction_type];
                break;
        }

        return $url;
    }
}
