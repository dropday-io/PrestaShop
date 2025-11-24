<?php
/**
* 2007-2021 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Dropday extends Module
{
    /**
     * @var string 
     */
    protected $api_uri = 'https://dropday.io/api/v1/';

    /**
     * @var string API key
     */
    protected $apiKey = null;

    /**
     * @var string Account ID
     */
    protected $accountId = null;

    /**
     * Dropday constructor.
     */
    public function __construct()
    {
        $this->name = 'dropday';
        $this->tab = 'shipping_logistics';
        $this->version = '1.6.0';
        $this->author = 'Dropday support@dropday.nl';
        $this->need_instance = 0;
        $this->module_key = '103f6d164a3687e35039203258788760';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Dropday');
        $this->description = $this->l('Order synchronisation with Dropday drop-shipping automation');

        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
    }

    /**
     * @return bool
     */
    public function install()
    {
        Configuration::updateValue('DROPDAY_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('actionOrderStatusUpdate');
    }

    /**
     * @return mixed
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $output = '';
        if (((bool)Tools::isSubmit('submitDropdayModule')) == true) {
            $output .= $this->postProcess();
        }
        $output .= $this->renderForm();
        return $output;
    }

    /**
     * @param string $type
     * @return string
     */
    public function getApiUrl($type = '')
    {
        return $type ? trim($this->api_uri, '/') . '/' . $type : trim($this->api_uri, '/');
    }

    /**
     * Get API Key (in-memory override or configuration)
     * 
     * @return string
     */
    protected function getApiKey()
    {
        return $this->apiKey !== null 
            ? $this->apiKey 
            : Configuration::get('DROPDAY_ACCOUNT_APIKEY');
    }

    /**
     * Get Account ID (in-memory override or configuration)
     * 
     * @return string
     */
    protected function getAccountId()
    {
        return $this->accountId !== null 
            ? $this->accountId 
            : Configuration::get('DROPDAY_ACCOUNT_ID');
    }

    /**
     * Set API Key (in-memory, does not persist to database)
     * 
     * @param string $apiKey
     */
    protected function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Set Account ID (in-memory, does not persist to database)
     * 
     * @param string $accountId
     */
    protected function setAccountId($accountId)
    {
        $this->accountId = $accountId;
    }

    /**
     * @return string
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitDropdayModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $this->context->smarty->assign([
            'module_dir' => $this->_path
        ]);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
        
        return $helper->generateForm($this->getConfigForm()) . $output;
    }

    /**
     * @return array[]
     */
    protected function getConfigForm()
    {
        // Get all order states for the select options
        $orderStates = OrderState::getOrderStates($this->context->language->id);
        $orderStateOptions = [
            [
                'id' => 'default',
                'name' => $this->l('Default behaviour (hookPaymentConfirmation)')
            ]
        ];
        
        foreach ($orderStates as $state) {
            $orderStateOptions[] = [
                'id' => $state['id_order_state'],
                'name' => $state['name']
            ];
        }

        return [
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('Settings'),
                        'icon' => 'icon-cogs',
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Live mode'),
                            'name' => 'DROPDAY_LIVE_MODE',
                            'is_bool' => true,
                            'desc' => $this->l('Use this module in live mode'),
                            'values' => [
                                [
                                    'id' => 'active_on',
                                    'value' => true,
                                    'label' => $this->l('Enabled')
                                ],
                                [
                                    'id' => 'active_off',
                                    'value' => false,
                                    'label' => $this->l('Disabled')
                                ]
                            ],
                        ],
                        [
                            'col' => 6,
                            'type' => 'text',
                            'prefix' => '<i class="icon icon-key"></i>',
                            'name' => 'DROPDAY_ACCOUNT_APIKEY',
                            'label' => $this->l('API Key'),
                        ],
                        [
                            'col' => 6,
                            'type' => 'text',
                            'name' => 'DROPDAY_ACCOUNT_ID',
                            'label' => $this->l('Account ID'),
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Order status trigger'),
                            'desc' => $this->l('Select which order statuses should trigger sending orders to Dropday. Select "Default behaviour" to use hookPaymentConfirmation only.'),
                            'name' => 'DROPDAY_ORDER_STATUSES[]',
                            'multiple' => true,
                            'size' => is_array($orderStateOptions) ? count($orderStateOptions) : 8,
                            'col' => 6,
                            'options' => [
                                'query' => $orderStateOptions,
                                'id' => 'id',
                                'name' => 'name'
                            ]
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save'),
                    ],
                ],
            ]
        ];
    }

    /**
     * @return array
     */
    protected function getConfigFormValues()
    {
        $orderStatuses = Configuration::get('DROPDAY_ORDER_STATUSES');
        if (!$orderStatuses) {
            $orderStatuses = ['default']; // Default behavior
        } elseif (is_string($orderStatuses)) {
            $orderStatuses = json_decode($orderStatuses, true) ?: ['default'];
        }
        
        return [
            'DROPDAY_LIVE_MODE' => Configuration::get('DROPDAY_LIVE_MODE'),
            'DROPDAY_ACCOUNT_ID' => Configuration::get('DROPDAY_ACCOUNT_ID'),
            'DROPDAY_ACCOUNT_APIKEY' => Configuration::get('DROPDAY_ACCOUNT_APIKEY', null),
            'DROPDAY_ORDER_STATUSES[]' => $orderStatuses,
        ];
    }

    /**
     * @return mixed
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        $defaultBehaviourSelected = false;
        
        foreach (array_keys($form_values) as $key) {
            // Handle order statuses specially
            if ($key === 'DROPDAY_ORDER_STATUSES[]') {
                $orderStatuses = Tools::getValue('DROPDAY_ORDER_STATUSES');
                
                // If default is selected or nothing is selected, remove configuration
                if (!$orderStatuses || in_array('default', $orderStatuses)) {
                    Configuration::deleteByName('DROPDAY_ORDER_STATUSES');
                    $defaultBehaviourSelected = true;
                } else {
                    // Store as JSON string
                    Configuration::updateValue('DROPDAY_ORDER_STATUSES', json_encode($orderStatuses));
                }
            } else {
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
        
        // Show appropriate confirmation message
        if ($defaultBehaviourSelected) {
            return $this->displayConfirmation($this->l('Default behaviour is reset: order is send when marked as paid'));
        } else {
            return $this->displayConfirmation($this->l('Settings updated successfully!'));
        }
    }

    /**
     * @param $id_order
     * @param OrderState $status
     * @return false
     */
    public function handleOrder($id_order, OrderState $status)
    {
        if (!$id_order || !Validate::isLoadedObject($status) || !$this->shouldHandleOrderStatus($status)) {
            return false;
        }

        $order = new Order((int) $id_order);
        $old_os = $order->getCurrentOrderState();

        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $order_date = $this->getOrderData($order);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiUrl('orders'));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_PORT, 443);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_date));
        if (!Configuration::get('PS_SSL_ENABLED') || 1) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Api-Key: ' . $this->getApiKey(),
            'Account-Id: ' . $this->getAccountId()
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            Logger::addLog('[dropday] error: ' . curl_error($ch), 3, null, 'Order', (int) $id_order, true);
        } else {
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result = json_decode($result, true);
            if ($httpcode == 200) {
                Logger::addLog('[dropday] API request sent successfully :#'.$result['reference'], 1, null, 'Order', (int) $id_order, true);
            } elseif ($httpcode == 422) {
                Logger::addLog('[dropday] Error: ' . json_encode($result['errors']), 3, null, 'Order', (int) $id_order, true);
            } else {
                Logger::addLog('[dropday] Unknown error: ' . json_encode($result), 3, $httpcode, 'Order', (int) $id_order, true);
            }
            error_log(json_encode($result));
        }

        curl_close($ch);
    }

    /**
     * Determines if an order should be handled based on configured order statuses
     * 
     * @param OrderState $status
     * @return bool
     */
    private function shouldHandleOrderStatus(OrderState $status)
    {
        $configuredStatuses = Configuration::get('DROPDAY_ORDER_STATUSES');
        
        // If no custom configuration exists, use default behavior (check if paid)
        if (!$configuredStatuses) {
            return $status->paid;
        }
        
        // Parse configured statuses
        $configuredStatuses = json_decode($configuredStatuses, true);
        
        // If configuration is invalid or empty, fallback to default behavior
        if (!is_array($configuredStatuses) || count($configuredStatuses) === 0) {
            return $status->paid;
        }
        
        // Check if current status is in configured statuses
        return in_array($status->id, $configuredStatuses);
    }

    public function getOrderData(Order $order)
    {
        $cart = new Cart((int)$order->id_cart);
        
        $shipping_cost = $cart->getTotalShippingCost(null, true, null);
        $shop = new Shop((int) $order->id_shop);
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $shippingInfo = $this->getShippingInfo($order, $cart);

        $orderData = [
            'external_id' => $order->reference,
            'source' => $shop->name,
            'total' => (float) $order->getOrdersTotalPaid(),
            'shipping_cost' => (float) $shipping_cost,
            'shipping' => $shippingInfo,
            'email' => $customer->email,
            'shipping_address' => [
                'first_name' => $address->firstname,
                'last_name' => $address->lastname,
                'company_name' => $address->company,
                'address1' => $address->address1,
                'address2' => ($address->address2 ? $address->address2 : $address->address2),
                'postcode' => $address->postcode,
                'city' => $address->city,
                'country' => Country::getNameById($order->id_lang, (int) $address->id_country),
                'phone' => $address->phone,
            ],
            'products' => []
        ];

        if ($state = State::getNameById($address->id_state)) {
            $orderData['shipping_address']['state'] = (string) $state;
        }

        if (!Configuration::get('DROPDAY_LIVE_MODE')) {
            $orderData['test'] = true;
        }
        
        $products = $order->getProducts();

        foreach ($products as $product) {
            
            $quantity = (int) (isset($product['customizationQuantityTotal']) && $product['customizationQuantityTotal'])
                ? $product['customizationQuantityTotal']
                : $product['product_quantity'];

            $stockQuantity = false;
            if (Configuration::get('PS_STOCK_MANAGEMENT')) {
                $stockAvailable = StockAvailable::getQuantityAvailableByProduct($product['product_id'], $product['product_attribute_id'], $this->context->shop->id);
                $stockQuantity = (int) $stockAvailable + (int) $product['product_quantity'];
            }

            $ean13 = false;
            if (Tools::strlen($product['product_ean13']) >= 13) {
                $ean13 = $product['product_ean13'];
            }

            $cat = new Category((int) $product['id_category_default'], (int) $order->id_lang);

            $link_rewrite = $this->getProductLinkRewrite((int) $product['product_id'], (int) $order->id_lang);

            $image_url = isset($product['image'])
                ? $this->context->link->getImageLink($link_rewrite, $product['image']->id, $this->imageTypeGetFormattedName('large'))
                : null;

            if ($productCustomizations = $cart->getProductCustomization($product['product_id'])) {
                $custom = [];

                $count = 1;
                foreach ($productCustomizations as $key => $productCustomization) {
                    $productCustomizationName = $this->getProductCustomizationFieldName($productCustomization);

                    $productCustomizationValue = $this->getProductCustomizationFieldValue($productCustomization);

                    if ($productCustomizationValue === false) {
                        continue;
                    }

                    if (!$productCustomizationName) {
                        $productCustomizationName = 'value_' . (string) $count;
                    }

                    $custom[$productCustomization['id_customization']][$productCustomizationName] = $productCustomizationValue;

                    $count++;
                }

                foreach ($custom as $id_customization => $customization) {
                    $product_data = [
                        'external_id' => (int) $product['product_id'],
                        'name' => (string) $product['product_name'],
                        'reference' => (string) $this->getProductReference($product),
                        'quantity' => (int) $productCustomization['quantity'],
                        'price' => (float) $product['product_price'],
                        'purchase_price' => isset($product['original_wholesale_price']) && $product['original_wholesale_price'] > 0 ? 
                            (float) $product['original_wholesale_price'] : (float) $product['wholesale_price'],
                        'image_url' => $image_url,
                        'brand' => (string) Manufacturer::getNameById((int) $product['id_manufacturer']),
                        'category' => (string) $cat->name,
                        'supplier' => (string) Supplier::getNameById((int) $product['id_supplier']),
                        'custom' => $customization
                    ];

                    if ($stockQuantity !== false) {
                        $product_data['stock_quantity'] = $stockQuantity;
                    }

                    if ($ean13 !== false) {
                        $product_data['ean13'] = $ean13;
                    }

                    $orderData['products'][$product['id_order_detail'] . '_' . $id_customization] = $product_data;
                }
            } else {
                $product_data = [
                    'external_id' => (int) $product['product_id'],
                    'name' => (string) $product['product_name'],
                    'reference' => (string) $this->getProductReference($product),
                    'quantity' => $quantity,
                    'price' => (float) $product['product_price'],
                    'purchase_price' => isset($product['original_wholesale_price']) && $product['original_wholesale_price'] > 0 ? 
                        (float) $product['original_wholesale_price'] : (float) $product['wholesale_price'],
                    'image_url' => $image_url,
                    'brand' => (string) Manufacturer::getNameById((int) $product['id_manufacturer']),
                    'category' => (string) $cat->name,
                    'supplier' => (string) Supplier::getNameById((int) $product['id_supplier']),
                ];

                if ($stockQuantity !== false) {
                    $product_data['stock_quantity'] = $stockQuantity;
                }

                if ($ean13 !== false) {
                    $product_data['ean13'] = $ean13;
                }

                $orderData['products'][$product['id_order_detail']] = $product_data;
            }

            $orderData['products'] = array_values($orderData['products']);
                        
            if (Tools::strlen($product['ean13']) >= 13) {
                $product_data['ean13'] = $product['ean13'];
            }
        }

        return $orderData;
    }

    /**
     * Get shipping information for Dropday API
     * 
     * @param Order $order
     * @param Cart $cart
     * @return array
     */
    private function getShippingInfo(Order $order, Cart $cart)
    {
        $shippingInfo = [
            'name' => '',
            'description' => '',
            'cost' => (float) $cart->getTotalShippingCost(null, true, null),
            'note' => '',
            'delivery_date' => ''
        ];

        if ($order->id_carrier > 0) {
            $carrier = new Carrier((int) $order->id_carrier);
            if (Validate::isLoadedObject($carrier)) {
                $shippingInfo['name'] = $carrier->name;
                
                if (!empty($carrier->delay) && is_array($carrier->delay)) {
                    $currentLangId = $this->context->language->id;
                    if (isset($carrier->delay[$currentLangId])) {
                        $shippingInfo['description'] = $carrier->delay[$currentLangId];
                    } else {
                        $shippingInfo['description'] = reset($carrier->delay);
                    }
                } elseif (!empty($carrier->delay)) {
                    $shippingInfo['description'] = $carrier->delay;
                }
            }
        }
        
        $orderMessages = Message::getMessagesByOrderId($order->id, false);
        if (!empty($orderMessages)) {
            $notes = [];
            foreach ($orderMessages as $message) {
                if (!empty($message['message'])) {
                    $notes[] = $message['message'];
                }
            }
            if (!empty($notes)) {
                $shippingInfo['note'] = implode('; ', $notes);
            }
        }

        return $shippingInfo;
    }

    /**
     * @param $id_product
     * @param $id_lang
     * @return mixed|string
     */
    private function getProductLinkRewrite($id_product, $id_lang)
    {
        $lr = '';
        $product_langs = Product::getUrlRewriteInformations($id_product);
        foreach ($product_langs as $value) {
            $lr = $value['link_rewrite'];
            if ($value['id_lang'] == $id_lang) {
                break;
            }
        }
        return $lr;
    }

    /**
     * @param $params
     */
    public function hookActionOrderStatusUpdate($params)
    {
        $this->handleOrder((int) $params['id_order'], $params['newOrderStatus']);
    }

    /**
     * Fixes backward compatibility issue
     *
     * @param string $size
     * @return mixed
     */
    private function imageTypeGetFormattedName($size = 'large')
    {
        if (method_exists('ImageType', 'getFormatedName')) {
            return ImageType::getFormatedName($size);
        }

        return ImageType::getFormattedName('large');
    }

    /**
     * Checks for combination and gets correct product reference
     * 
     * @param array $product
     * @return string
     */
    private function getProductReference($product)
    {
        $reference = $product['reference'];

        if ((int)$product['product_attribute_id'] > 0) {
            $combination = new Combination((int)$product['product_attribute_id']);
            if ((string)$combination->reference !== '') {
                $reference = $combination->reference;
            }
        }
        
        return $reference;
    }

    /**
     * Makes customization field name
     * 
     * @param $productCustomization
     * @return false|string
     */
    private function getProductCustomizationFieldName($productCustomization)
    {
        $sql = sprintf('SELECT `name` FROM `%scustomization_field_lang` WHERE `id_customization_field`=%s AND `id_lang`=%s AND `id_shop`=%s',
            _DB_PREFIX_,
            (int) $productCustomization['index'], 
            $this->context->language->id, 
            $this->context->shop->id
        );
        
        return DB::getInstance()->getValue($sql) ?: false;
    }

    /**
     * Makes customization field value
     * 
     * @param $productCustomization
     * @return false|string
     */
    private function getProductCustomizationFieldValue($productCustomization)
    {
        // compare as (strings) to avoid complications with '0'
        switch ((string)$productCustomization['type']) {
            case (string)Product::CUSTOMIZE_TEXTFIELD:
                $return = $productCustomization['value'];
                break;
            case (string)Product::CUSTOMIZE_FILE:
                $return = sprintf("%s/upload/%s",
                    rtrim($this->context->link->getPageLink('index'), '/'),
                    $productCustomization['value']
                );
                break;
            default:
                $return = false;
        }
        
        return $return;
    }
}
