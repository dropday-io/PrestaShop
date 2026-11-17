<?php
/**
 * CI smoke test: an order that was already sent to Dropday can be sent again
 * from the order page (e.g. because it was deleted in Dropday). The resend
 * must hit the API again and store the new Dropday reference.
 *
 * Assumes PrestaShop Docker auto-install already ran (shop up, demo orders present).
 */

class SendOrderToDropdayResendsSentOrderTest
{
    public function run()
    {
        $this->bootPrestaShop();
        $this->installDropdayWithEmptySettings();

        $order = $this->firstOrder();
        $orderId = (int) $order->id;
        $this->resetQueueRow($orderId);

        $context = Context::getContext();
        $context->cart = new Cart((int) $order->id_cart);
        $context->customer = new Customer((int) $order->id_customer);

        $params = [
            'id_order' => $orderId,
            'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_PAYMENT')),
        ];
        $idModule = (int) Module::getModuleIdByName('dropday');

        // First send succeeds.
        DropdayHttp::fake(function () {
            return DropdayHttp::response(['reference' => 'FIRST-REF'], 200);
        });
        Hook::exec('actionOrderStatusUpdate', $params, $idModule);
        $this->assertStatus($orderId, DropdayOrder::STATUS_SENT, 'after the initial send');

        // The order was deleted in Dropday; the merchant resends it.
        DropdayHttp::fake(function () {
            return DropdayHttp::response(['reference' => 'SECOND-REF'], 200);
        });

        $module = Module::getInstanceByName('dropday');
        $resetForResend = new ReflectionMethod($module, 'resetForResend');
        $resetForResend->setAccessible(true);
        $resetForResend->invoke($module, $orderId);
        $module->processOrder($orderId);

        if (count(DropdayHttp::recorded()) !== 1) {
            $this->fail('Expected the resend to call the Dropday API once, got ' . count(DropdayHttp::recorded()));
        }

        $this->assertStatus($orderId, DropdayOrder::STATUS_SENT, 'after resending');

        $queueOrder = new DropdayOrder($orderId);
        if ($queueOrder->dropday_reference !== 'SECOND-REF') {
            $this->fail('Expected the new Dropday reference "SECOND-REF", got "' . $queueOrder->dropday_reference . '"');
        }

        echo "OK: sent order #{$orderId} was sent to Dropday again\n";
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
        require_once _PS_MODULE_DIR_ . 'dropday/classes/DropdayOrder.php';
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
     * Other CI tests may have already queued/sent this same demo order -
     * start from a clean slate so this test is independent of run order.
     *
     * @param int $orderId
     */
    private function resetQueueRow($orderId)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . DropdayOrder::tableName() . '` WHERE `id_order` = ' . (int) $orderId
        );
    }

    /**
     * @param int $orderId
     * @param string $expectedStatus
     * @param string $when
     */
    private function assertStatus($orderId, $expectedStatus, $when)
    {
        $queueOrder = new DropdayOrder($orderId);
        if (!Validate::isLoadedObject($queueOrder)) {
            $this->fail('No queue row found for order #' . $orderId);
        }

        if ($queueOrder->status !== $expectedStatus) {
            $this->fail('Expected queue status "' . $expectedStatus . '" ' . $when . ', got "' . $queueOrder->status . '"');
        }
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

$test = new SendOrderToDropdayResendsSentOrderTest();
$test->run();
exit(0);
