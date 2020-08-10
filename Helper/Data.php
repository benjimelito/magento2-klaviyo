<?php
namespace Klaviyo\Reclaim\Helper;

use \Klaviyo\Reclaim\Helper\ScopeSetting;
use \Magento\Framework\App\Helper\Context;
use \Klaviyo\Reclaim\Helper\Logger;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const USER_AGENT = 'Klaviyo/1.0';
    const KLAVIYO_HOST = 'https://a.klaviyo.com/';
    const LIST_V2_API = 'api/v2/list/';
    const PLACED_ORDER = 'Placed Order Webhook';
    const REFUND_ORDER = 'Refunded Order Webhook';

    /**
     * Klaviyo logger helper
     * @var \Klaviyo\Reclaim\Helper\Logger $klaviyoLogger
     */
    protected $_klaviyoLogger;

    /**
     * Klaviyo scope setting helper
     * @var \Klaviyo\Reclaim\Helper\ScopeSetting $klaviyoScopeSetting
     */
    protected $_klaviyoScopeSetting;

    public function __construct(
        Context $context,
        Logger $klaviyoLogger,
        ScopeSetting $klaviyoScopeSetting
    ) {
        parent::__construct($context);
        $this->_klaviyoLogger = $klaviyoLogger;
        $this->_klaviyoScopeSetting = $klaviyoScopeSetting;
    }

    public function getKlaviyoLists($api_key=null){
        if (!$api_key) $api_key = $this->_klaviyoScopeSetting->getPrivateApiKey();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://a.klaviyo.com/api/v1/lists?api_key=' . $api_key);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $output = json_decode(curl_exec($ch));
        curl_close($ch);

        if (property_exists($output, 'status')) {
            $status = $output->status;
            if ($status === 403) {
                $reason = 'The Private Klaviyo API Key you have set is invalid.';
            } elseif ($status === 401) {
                $reason = 'The Private Klaviyo API key you have set is no longer valid.';
            } else {
                $reason = 'Unable to verify Klaviyo Private API Key.';
            }

            $result = [
                'success' => false,
                'reason' => $reason
            ];
        } else {
            $static_groups = array_filter($output->data, function($list) {
                return $list->list_type === 'list';
            });

            usort($static_groups, function($a, $b) {
                return strtolower($a->name) > strtolower($b->name) ? 1 : -1;
            });

            $result = [
                'success' => true,
                'lists' => $static_groups
            ];
        }

        return $result;
    }

    /**
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     * @param string $source
     * @return bool|string
     */
    public function subscribeEmailToKlaviyoList($email, $firstName = null, $lastName = null, $source = null)
    {
        $listId = $this->_klaviyoScopeSetting->getNewsletter();
        $optInSetting = $this->_klaviyoScopeSetting->getOptInSetting();

        $properties = [];
        $properties['email'] = $email;
        if ($firstName) $properties['$first_name'] = $firstName;
        if ($lastName) $properties['$last_name'] = $lastName;
        if ($source) $properties['$source'] = $source;

        $propertiesVal = ['profiles' => $properties];

        $path = self::LIST_V2_API . $listId . $optInSetting;

        try {
            $response = $this->sendApiRequest($path, $propertiesVal, 'POST');
        } catch (\Exception $e) {
            $this->_klaviyoLogger->log(sprintf('Unable to subscribe %s to list %s: %s', $email, $listId, $e));
            $response = false;
        }

        return $response;
    }

    /**
     * @param string $email
     * @return bool|string
     */
    public function unsubscribeEmailFromKlaviyoList($email)
    {
        $listId = $this->_klaviyoScopeSetting->getNewsletter();

        $path = self::LIST_V2_API . $listId . ScopeSetting::API_SUBSCRIBE;
        $fields = [
            'emails' => [(string)$email],
        ];

        try {
            $response = $this->sendApiRequest($path, $fields, 'DELETE');
        } catch (\Exception $e) {
            $this->_klaviyoLogger->log(sprintf('Unable to unsubscribe %s from list %s: %s', $email, $listId, $e));
            $response = false;
        }

        return $response;
    }


    /**
     * @param string $order
     * @return bool|string
     */
    public function sendOrderToKlaviyo($order)
    {
        $payload = $this->map_payload_object($order);
        return $this->klaviyoTrackEvent(self::PLACED_ORDER, $payload['customer_properties'], $payload['properties'], time());

    }

     /**
     * @param string $order
     * @return bool|string
     */
    public function sendRefundToKlaviyo($order)
    {
        $payload = $this->map_payload_object($order);
        // Check if there is a way to grab the comment of refund.
        $payload['properties']['Reason'] = 'Not Needed';
        return $this->klaviyoTrackEvent(self::REFUND_ORDER, $payload['customer_properties'], $payload['properties'], time());

    }



    public function klaviyoTrackEvent($event, $customer_properties=array(), $properties=array(), $timestamp=NULL)
    {
        if ((!array_key_exists('$email', $customer_properties) || empty($customer_properties['$email']))
            && (!array_key_exists('$id', $customer_properties) || empty($customer_properties['$id']))) {

            return 'You must identify a user by email or ID.';
        }
        $params = array(
            'token' => $this->_klaviyoScopeSetting->getPublicApiKey(),
            'event' => $event,
            'properties' => $properties,
            'customer_properties' => $customer_properties
        );

        if (!is_null($timestamp)) {
            $params['time'] = $timestamp;
        }
        $encoded_params = $this->build_params($params);
        return $this->make_request('api/track', $encoded_params);

    }
    protected function build_params($params) {
        return 'data=' . urlencode(base64_encode(json_encode($params)));
    }

    protected function make_request($path, $params) {
        $url = self::KLAVIYO_HOST . $path . '?' . $params;
        $response = file_get_contents($url);
        return $response == '1';
    }

    /**
     * Helper function that takes the order object and returns a mapped out array
     * @return array
     */
    private function map_payload_object($order)
    {
        $customer_properties = [];
        $properties = [];
        $items = [];

        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();

        foreach ($order->getAllVisibleItems() as $item) {
                $items[] = [
                    'ProductId' => $item->getProductId(),
                    'SKU' => $item->getSku(),
                    'ProductName' => $item->getName(),
                    'Quanitity' => (int)$item->getQtyOrdered(),
                    'ItemPrice' => (float)$item->getPrice()
                ];
            }

        if ($order->getCustomerEmail()) $customer_properties['$email'] = $order->getCustomerEmail();
        if ($order->getCustomerName()) $customer_properties['$first_name'] = $order->getCustomerFirstName();
        if ($order->getCustomerLastname()) $customer_properties['$last_name'] = $order->getCustomerLastname();

        if ($shipping->getTelephone()) $customer_properties['$phone_number'] = $shipping->getTelephone();
        if ($shipping->getCity()) $customer_properties['$city'] = $shipping->getCity();
        if ($shipping->getStreet()) $customer_properties['$address1'] = $shipping->getStreet();
        if ($shipping->getPostcode()) $customer_properties['$zip'] = $shipping->getPostcode();
        if ($shipping->getRegion()) $customer_properties['$region'] = $shipping->getRegion();
        if ($shipping->getCountryId()) $customer_properties['$country'] = $shipping->getCountryId();

        if ($order->getGrandTotal()) $properties['$value'] = (float)$order->getGrandTotal();
        if ($order->getQuoteId()) $properties['$event_id'] = $order->getQuoteId();
        if ($order->getDiscountAmount()) $properties['Discount Value'] = (float)$order->getDiscountAmount();
        if ($order->getCouponCode()) $properties['Discount Code'] = $order->getCouponCode();

        $properties['BillingAddress'] = $this->map_address($billing);
        $properties['ShippingAddress'] = $this->map_address($shipping);
        $properties['Items'] = $items;

        return ['customer_properties' => $customer_properties, 'properties' => $properties];

    }

    /**
     * Helper function that takes the address_type object and returns a mapped out array
     * @return array
     */
    private function map_address($address_type)
    {
        $address = [];
        if ($address_type->getFirstname()) $address['FirstName'] = $address_type->getFirstname();
        if ($address_type->getLastname()) $address['LastName'] = $address_type->getLastname();
        if ($address_type->getCompany()) $address['Company'] = $address_type->getCompany();
        if ($address_type->getStreet()) $address['Address1'] = $address_type->getStreet();
        if ($address_type->getCity()) $address['City'] = $address_type->getCity() ;
        if ($address_type->getRegion()) $address['Region'] = $address_type->getRegion();
        if ($address_type->getRegionCode()) $address['RegionCode'] = $address_type->getRegionCode();
        if ($address_type->getCountryId()) $address['CountryCode'] = $address_type->getCountryId();
        if ($address_type->getPostCode()) $address['Zip'] = $address_type->getPostCode();
        if ($address_type->getTelephone()) $address['Phone'] = $address_type->getTelephone();

        return $address;

    }

    /**
     * @param string $path
     * @param array $params
     * @param string $method
     * @return bool|string
     * @throws \Exception
     */
    private function sendApiRequest(string $path, array $params, string $method = null)
    {
        $url = self::KLAVIYO_HOST . $path;

        //Add API Key to params
        $params['api_key'] = $this->_klaviyoScopeSetting->getPrivateApiKey();

        $curl = curl_init();
        $encodedParams = json_encode($params);

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => (!empty($method)) ? $method : 'POST',
            CURLOPT_POSTFIELDS => $encodedParams,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($encodedParams)
            ],
        ]);

        // Submit the request
        $response = curl_exec($curl);
        $err = curl_errno($curl);

        if ($err) {
            throw new \Exception(curl_error($curl));
        }

        // Close cURL session handle
        curl_close($curl);

        return $response;
    }
}