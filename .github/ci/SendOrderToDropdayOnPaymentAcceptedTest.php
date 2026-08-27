<?php
/**
 * CI smoke test: on payment accepted, Dropday posts an order payload (HTTP faked).
 *
 * Assumes PrestaShop Docker auto-install already ran (shop up, demo orders present).
 */

class SendOrderToDropdayOnPaymentAcceptedTest
{
    public function run()
    {
        $this->bootPrestaShop();
        $this->installDropdayWithEmptySettings();

        $order = $this->firstOrder();
        $context = Context::getContext();
        $context->cart = new Cart((int) $order->id_cart);
        $context->customer = new Customer((int) $order->id_customer);

        DropdayHttp::fake();

        Hook::exec('actionOrderStatusUpdate', [
            'id_order' => (int) $order->id,
            'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_PAYMENT')),
        ], (int) Module::getModuleIdByName('dropday'));

        $this->assertOrderWasPosted((int) $order->id);
    }

    private function bootPrestaShop()
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            define('_PS_ADMIN_DIR_', dirname(__FILE__));
        }

        require_once '/var/www/html/config/config.inc.php';

        // PS 9+: AppKernel is abstract; use AdminKernel. PS 8: AppKernel is concrete.
        global $kernel;
        if (!is_object($kernel)) {
            if (class_exists('AdminKernel')) {
                $kernel = new AdminKernel('dev', true);
            } elseif (class_exists('AppKernel')) {
                $kernel = new AppKernel('dev', true);
            }

            if (isset($kernel) && is_object($kernel)) {
                $kernel->boot();
            }
        }

        $context = Context::getContext();
        $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));

        $employees = Employee::getEmployees();
        if (!empty($employees[0]['id_employee'])) {
            $context->employee = new Employee((int) $employees[0]['id_employee']);
        }

        Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);

        require_once _PS_MODULE_DIR_ . 'dropday/classes/Http.php';
    }

    private function installDropdayWithEmptySettings()
    {
        $module = Module::getInstanceByName('dropday');
        // Uninstalled modules have no id — do not use Validate::isLoadedObject() here.
        if (!$module instanceof Module) {
            $this->fail('Dropday module not found on disk at ' . _PS_MODULE_DIR_ . 'dropday');
        }
        if (!Module::isInstalled('dropday') && !$module->install()) {
            $this->fail('Failed to install dropday');
        }
        if (!Module::isEnabled('dropday')) {
            $module->enable();
        }

        Configuration::deleteByName('DROPDAY_ACCOUNT_ID');
        Configuration::deleteByName('DROPDAY_ACCOUNT_APIKEY');
        Configuration::deleteByName('DROPDAY_ORDER_STATUSES');
        Configuration::updateValue('DROPDAY_LIVE_MODE', false);
    }

    /**
     * @return Order
     */
    private function firstOrder()
    {
        $orderId = (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` ORDER BY `id_order` ASC'
        );
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->fail('First order not found');
        }

        return $order;
    }

    /**
     * @param int $orderId
     */
    private function assertOrderWasPosted($orderId)
    {
        $recorded = DropdayHttp::recorded();
        if (count($recorded) === 0) {
            $this->fail('No HTTP request was sent');
        }

        $request = $recorded[0];
        $headers = implode("\n", $request['headers']);
        $body = $request['body'];

        if (strpos($request['url'], '/orders') === false) {
            $this->fail('Request URL does not contain /orders: ' . $request['url']);
        }
        if (strpos($headers, 'Api-Key:') === false) {
            $this->fail('Missing Api-Key header');
        }
        if (strpos($headers, 'Account-Id:') === false) {
            $this->fail('Missing Account-Id header');
        }
        if (!is_array($body) || empty($body['external_id'])) {
            $this->fail('Request body is missing external_id');
        }
        if (empty($body['products']) || !is_array($body['products'])) {
            $this->fail('Request body is missing products');
        }

        foreach ($body['products'] as $index => $product) {
            if (!array_key_exists('height', $product)
                || !array_key_exists('width', $product)
                || !array_key_exists('length', $product)
            ) {
                $this->fail("Product #{$index} is missing height/width/length");
            }
        }

        echo "OK: Dropday posted order payload to fake HTTP for order #{$orderId}\n";
    }

    /**
     * @param string $message
     */
    private function fail($message)
    {
        echo $message . "\n";
        exit(1);
    }
}

$test = new SendOrderToDropdayOnPaymentAcceptedTest();
$test->run();
exit(0);
