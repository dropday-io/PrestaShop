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

{if $dropday_status == 'sent'}
    <div class="alert alert-success mb-1">
        {l s='Sent to Dropday' mod='dropday'}{if $dropday_reference} ({if $dropday_order_url}<a href="{$dropday_order_url}" target="_blank" rel="noopener noreferrer">#{$dropday_reference|escape:'htmlall':'UTF-8'}</a>{else}#{$dropday_reference|escape:'htmlall':'UTF-8'}{/if}){/if}
    </div>
{elseif $dropday_status == 'failed'}
    <div class="alert alert-danger mb-1">
        {l s='Failed to send to Dropday' mod='dropday'}{if $dropday_last_error}: {$dropday_last_error|escape:'htmlall':'UTF-8'}{/if}
    </div>
{elseif $dropday_status == 'never'}
    <p class="mb-1">{l s='Never sent to Dropday' mod='dropday'}</p>
{else}
    <p class="mb-1">
        {l s='Waiting to be sent to Dropday' mod='dropday'} ({l s='attempt' mod='dropday'} {$dropday_attempts})
    </p>
{/if}

{if $dropday_status == 'never'}
    <a href="{$dropday_resend_url}" class="btn {if $dropday_new_theme}btn-primary{else}btn-default{/if} btn-sm">
        {l s='Send to Dropday' mod='dropday'}
    </a>
    <p class="text-muted mb-1 mt-1">
        {l s='Sending now ignores the order status rule in the settings.' mod='dropday'}
    </p>
{elseif $dropday_status != 'sent'}
    <a href="{$dropday_resend_url}" class="btn {if $dropday_new_theme}btn-outline-secondary{else}btn-default{/if} btn-sm">
        {l s='Send to Dropday again' mod='dropday'}
    </a>
{/if}
