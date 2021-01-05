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
    protected $image_format = 'large_default';
    protected $api_uri = 'https://dropday.io/api/v1/';

    public function __construct()
    {
        $this->name = 'dropday';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Dropday support@dropday.nl';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Dropday');
        $this->description = $this->l('Order synchronisation with Dropday drop-shipping automation');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        Configuration::updateValue('DROPDAY_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        if (((bool)Tools::isSubmit('submitDropdayModule')) == true) {
            $output .= $this->postProcess();
        }
        $output .= $this->renderForm();
        return $output;
    }
    
    public function getApiUrl($type = '') {
        if ($type) {
            return trim($this->api_uri, '/') . '/' . $type;
        } else {
            return trim($this->api_uri, '/');
        }
    }

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

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'DROPDAY_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Enter a valid email address'),
                        'name' => 'DROPDAY_ACCOUNT_APIKEY',
                        'label' => $this->l('API Key'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'desc' => $this->l('Enter a valid email address'),
                        'name' => 'DROPDAY_ACCOUNT_ID',
                        'label' => $this->l('Account ID'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'DROPDAY_LIVE_MODE' => Configuration::get('DROPDAY_LIVE_MODE'),
            'DROPDAY_ACCOUNT_ID' => Configuration::get('DROPDAY_ACCOUNT_ID'),
            'DROPDAY_ACCOUNT_APIKEY' => Configuration::get('DROPDAY_ACCOUNT_APIKEY', null),
        );
    }
    
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
        return $this->displayConfirmation($this->l('Settings updated successfully!'));
    }
    
    public function handleOrder($id_order, OrderState $status)
    {
        if (!$id_order || !Validate::isLoadedObject($status) || !$status->paid ) {
            return false;
        }
        
        $order = new Order((int)$id_order);
        $old_os = $order->getCurrentOrderState();
        if (!Validate::isLoadedObject($order) || ($old_os->id != $status->id && $old_os->paid)) {
            return false;
        }
        
        $shop = new Shop((int)$order->id_shop);
        $customer = new Customer((int)$order->id_customer);
        $address = new Address((int)$order->id_address_delivery);
        $order_data = array(
            'external_id' => (int)$id_order,
            'source' => $shop->name,
            'total' => $order->getOrdersTotalPaid(),
            'shipping_cost' => Cart::getTotalCart($order->id_cart, true, Cart::ONLY_SHIPPING),
            'email' => $customer->email,
            'shipping_address' => array(
                'firstname' => $address->firstname,
                'lastname' => $address->lastname,
                'company_name' => $address->company,
                'address1' => $address->address1,
                'address2' => ($address->address2 ? $address->address2 : $address->address1),
                'postcode' => $address->postcode,
                'city' => $address->city,
                'country' => Country::getNameById($order->id_lang, (int)$address->id_country),
                'phone' => $address->phone,
            ),
            'products' => array()
        );
        if (!Configuration::get('DROPDAY_LIVE_MODE')) {
            $order_data['test'] = true;
        }
        $products = $order->getProducts();
        foreach ($products as $product) {
            $cat = new Category((int) $product['id_category_default'], (int)$order->id_lang);
            $p = array(
                'external_id' => (int) $product['product_id'],
                'name' => ''.$product['product_name'],
                'reference' => ''.$product['reference'],
                'quantity' => (int) (isset($product['customizationQuantityTotal']) && $product['customizationQuantityTotal']) ? $product['customizationQuantityTotal'] : $product['product_quantity'],
                'price' => (float) $product['product_price'],
                'image_url' => $this->context->link->getImageLink($this->getProductLinkRewrite((int)$product['product_id'], (int)$order->id_lang), $product['image']->id, $this->image_format ),
                'brand' => ''.Manufacturer::getNameById((int) $product['id_manufacturer']),
                'category' => ''.$cat->name,
                'supplier' => ''.Supplier::getNameById((int) $product['id_supplier']),
            );
            
            if (Tools::strlen($product['ean13']) >= 13) {
                $p['ean13'] = $product['ean13'];
            }
            
            $order_data['products'][] = $p;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiUrl('orders'));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_PORT, 443);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
        if (!Configuration::get('PS_SSL_ENABLED') || 1) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $headers = array(
            'Content-Type: application/json',
            'Api-Key: '.Configuration::get('DROPDAY_ACCOUNT_APIKEY'),
            'Account-Id: '.Configuration::get('DROPDAY_ACCOUNT_ID')
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            PrestaShopLogger::addLog('[dropday] error: ' . curl_error($ch), 3, null, 'Order', (int)$id_order, true);
        } else {
            PrestaShopLogger::addLog('[dropday] Order created' . curl_error($ch), 1, null, 'Order', (int)$id_order, true);
            error_log(json_encode($result));
        }
        curl_close($ch);
    }
    
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

    public function hookActionOrderStatusUpdate($params)
    {
        return $this->handleOrder((int)$params['id_order'], $params['newOrderStatus']);
    }

    public function hookActionValidateOrder($params)
    {
       return $this->handleOrder((int)$params['order']->id, $params['orderStatus']);
    }
}