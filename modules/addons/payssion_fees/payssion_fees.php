<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

use WHMCS\Database\Capsule;

/**
 * Addon config：列出所有啟用的 payment gateways，提供 fee_1_/fee_2_ 欄位
 */
function payssion_fees_config()
{
    $config = [
        "name"        => "Gateway Fees (All Gateways)",
        "description" => "Configure fixed + percent fees per payment gateway.",
        "version"     => "1.1.0",
        "author"      => "You",
        "fields"      => [],
    ];

    // 取所有啟用中的 gateway（依 WHMCS 慣例，tblpaymentgateways 有多行，同名 gateway 不同 setting）
    $gateways = Capsule::table('tblpaymentgateways')
        ->select('gateway')
        ->groupBy('gateway')
        ->pluck('gateway');

    foreach ($gateways as $gw) {
        // 友好顯示名稱（若有自訂 name 就用，否則用系統名）
        $friendly = Capsule::table('tblpaymentgateways')
            ->where('gateway', $gw)
            ->where('setting', 'name')
            ->value('value');
        $label = $friendly ?: $gw;

        $config['fields']["fee_1_{$gw}"] = [
            "FriendlyName" => "{$label} - Fixed",
            "Type"         => "text",
            "Size"         => "10",
            "Default"      => "0.00",
            "Description"  => "Fixed fee amount (in invoice currency)",
        ];
        $config['fields']["fee_2_{$gw}"] = [
            "FriendlyName" => "{$label} - Percent",
            "Type"         => "text",
            "Size"         => "10",
            "Default"      => "0.00",
            "Description"  => "Percent fee (e.g. 4.4 for 4.4%)",
        ];
    }

    return $config;
}

/**
 * 啟用：為現有所有 gateway 建立預設鍵（不存在才建立）
 */
function payssion_fees_activate()
{
    $gateways = Capsule::table('tblpaymentgateways')
        ->select('gateway')
        ->groupBy('gateway')
        ->pluck('gateway');

    foreach ($gateways as $gw) {
        foreach (['fee_1_', 'fee_2_'] as $prefix) {
            $key = $prefix . $gw;
            $exists = Capsule::table('tbladdonmodules')
                ->where('module', 'payssion_fees')
                ->where('setting', $key)
                ->exists();
            if (!$exists) {
                Capsule::table('tbladdonmodules')->insert([
                    'module'  => 'payssion_fees',
                    'setting' => $key,
                    'value'   => '0.00',
                ]);
            }
        }
    }

    return ['status' => 'success', 'description' => 'Initialized fee keys for all gateways.'];
}

/**
 * 停用時不刪資料（保留設定）
 */
function payssion_fees_deactivate()
{
    return ['status' => 'success', 'description' => 'Addon deactivated. Settings preserved.'];
}
