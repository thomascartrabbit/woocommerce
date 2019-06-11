<?php

namespace Rnoc\Retainful\Integrations;

class Currency
{
    function __construct()
    {
        add_filter('rnoc_get_current_currency_code', array($this, 'getCurrentCurrencyCode'));
        add_filter('rnoc_get_currency_rate', array($this, 'getCurrencyRate'), 10, 2);
        add_action('rnoc_set_current_currency_code', array($this, 'setCurrentCurrency'));
    }

    function getCurrentCurrencyCode($default_currency_code)
    {
        if (class_exists('WOOMULTI_CURRENCY_F_Data')) {
            $setting = new \WOOMULTI_CURRENCY_F_Data();
            $default_currency_code = $setting->get_current_currency();
        } elseif (class_exists('WOOMULTI_CURRENCY_Data')) {
            $setting = new \WOOMULTI_CURRENCY_Data();
            $default_currency_code = $setting->get_current_currency();
        }
        return $default_currency_code;
    }

    function setCurrentCurrency($currency_code)
    {
        if (class_exists('WOOMULTI_CURRENCY_F_Data')) {
            $setting = new \WOOMULTI_CURRENCY_F_Data();
            $setting->set_current_currency($currency_code);
        } elseif (class_exists('WOOMULTI_CURRENCY_Data')) {
            $setting = new \WOOMULTI_CURRENCY_Data();
            $setting->set_current_currency($currency_code);
        }
    }

    function getCurrencyRate($value, $currency_code)
    {
        if (class_exists('WOOMULTI_CURRENCY_F_Data')) {
            $setting = new \WOOMULTI_CURRENCY_F_Data();
            $selected_currencies = $setting->get_list_currencies();
            $value = isset($selected_currencies[$currency_code]['rate']) ? $selected_currencies[$currency_code]['rate'] : NULL;
        } elseif (class_exists('WOOMULTI_CURRENCY_Data')) {
            $setting = new \WOOMULTI_CURRENCY_Data();
            $selected_currencies = $setting->get_list_currencies();
            $value = isset($selected_currencies[$currency_code]['rate']) ? $selected_currencies[$currency_code]['rate'] : NULL;
        }
        return $value;
    }
}
