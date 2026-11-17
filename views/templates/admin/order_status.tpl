{*
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
* @author PrestaShop SA <contact@prestashop.com>
* @copyright 2007-2021 PrestaShop SA
* @license http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
* International Registered Trademark & Property of PrestaShop SA
*}

{if $dropday_new_theme}
<div class="card mt-2" id="dropday_order_status_block">
    <div class="card-header">
        <h3 class="card-header-title">
            {l s='Dropday' mod='dropday'}
        </h3>
    </div>
    <div class="card-body">
        {include file="./order_status_content.tpl"}
    </div>
</div>
{else}
<div class="panel" id="dropday_order_status_block">
    <div class="panel-heading">
        {l s='Dropday' mod='dropday'}
    </div>
    <div class="row">
        <div class="col-xs-12">
            {include file="./order_status_content.tpl"}
        </div>
    </div>
</div>
{/if}
