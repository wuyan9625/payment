<?php
/**
 * Payssion / Gateway Fees hook (WHMCS 8.x+)
 * - 讀取 Addon: module='payssion_fees' 中的 fee_1_{gateway}（固定額）與 fee_2_{gateway}（百分比）
 * - 在發票新增一筆「Payment Gateway Fees」，避免重複
 * - 付款方式變更時會自動重新計算
 * - 結帳頁即時顯示估算費用（僅顯示，不寫入，實際以發票為準）
 */
use WHMCS\Database\Capsule;

const FEE_NOTES_MARK = 'gateway_fees';
const FEE_DESC_PREFIX = 'Payment Gateway Fees';

/* ---------- 工具函數 ---------- */

function pgf_getInvoiceById($invoiceId) {
    $resp = localAPI('GetInvoice', ['invoiceid' => (int)$invoiceId]);
    return ($resp['result'] ?? '') === 'success' ? $resp : null;
}

function pgf_getAddonFeesMap() {
    // 讀取 addon 設定：module='payssion_fees' 且 setting LIKE 'fee_%'
    $rows = Capsule::table('tbladdonmodules')
        ->select('setting', 'value')
        ->where('module', '=', 'payssion_fees')
        ->where('setting', 'like', 'fee\_%')
        ->get();

    $fees = []; // $fees[gateway] = ['fixed' => x, 'percent' => y]
    foreach ($rows as $r) {
        $setting = $r->setting;    // fee_1_{gateway} or fee_2_{gateway}
        $value   = (float)$r->value;
        if (strpos($setting, 'fee_1_') === 0) {
            $gateway = substr($setting, strlen('fee_1_'));
            $fees[$gateway]['fixed'] = $value;
        } elseif (strpos($setting, 'fee_2_') === 0) {
            $gateway = substr($setting, strlen('fee_2_'));
            $fees[$gateway]['percent'] = $value;
        }
    }
    return $fees;
}

function pgf_calcFeeAmount($totalAmount, $gateway, $feesMap) {
    if (!isset($feesMap[$gateway])) {
        return 0.0;
    }
    $fixed   = (float)($feesMap[$gateway]['fixed']   ?? 0);
    $percent = (float)($feesMap[$gateway]['percent'] ?? 0);
    $fee = $fixed + $totalAmount * ($percent / 100.0);
    // 進位到小數點兩位（和舊版一致）
    return ceil($fee * 100) / 100;
}

function pgf_removeOldFeeItems($invoiceId) {
    // 刪掉 notes = gateway_fees 的行項，避免重複
    $items = Capsule::table('tblinvoiceitems')
        ->select('id')
        ->where('invoiceid', '=', $invoiceId)
        ->where('notes', '=', FEE_NOTES_MARK)
        ->get();

    foreach ($items as $it) {
        localAPI('DeleteInvoiceItem', ['invoiceitemid' => (int)$it->id]);
    }
}

/**
 * 在發票新增費用行項
 */
function pgf_addFeeItem($invoiceId, $gateway, $amount) {
    if ($amount <= 0) return;

    // 描述文字：帶出 5 + 4.4% 之類
    $descParts = [];
    $feesMap = pgf_getAddonFeesMap();
    $fixed   = (float)($feesMap[$gateway]['fixed']   ?? 0);
    $percent = (float)($feesMap[$gateway]['percent'] ?? 0);
    if ($fixed > 0 && $percent > 0) {
        $descParts[] = "{$fixed}+{$percent}%";
    } elseif ($percent > 0) {
        $descParts[] = "{$percent}%";
    } elseif ($fixed > 0) {
        $descParts[] = (string)$fixed;
    }
    $descTail = $descParts ? ' ('.implode('', $descParts).')' : '';

    localAPI('AddInvoiceItem', [
        'invoiceid'   => (int)$invoiceId,
        'userid'      => Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid') ?? 0,
        'type'        => 'Fee',
        'notes'       => FEE_NOTES_MARK,
        'description' => FEE_DESC_PREFIX . $descTail,
        'amount'      => number_format($amount, 2, '.', ''),
        'taxed'       => 0,
        // duedate 使用今天即可，WHMCS 會以發票本身為準
    ]);
}

/**
 * 核心：依當前 gateway 套用費用
 */
function pgf_applyInvoiceFee($invoiceId, $gateway = null) {
    $inv = pgf_getInvoiceById($invoiceId);
    if (!$inv) return;

    $gateway = $gateway ?? ($inv['paymentmethod'] ?? '');
    if ($gateway === '') return;

    // 移除舊的費用行項
    pgf_removeOldFeeItems($invoiceId);

    // 重新計算發票，拿到目前總額（未含本費）
    localAPI('UpdateInvoice', ['invoiceid' => (int)$invoiceId]);
    $inv = pgf_getInvoiceById($invoiceId);
    if (!$inv) return;

    $total = (float)($inv['total'] ?? 0);
    if ($total <= 0) return;

    $feesMap = pgf_getAddonFeesMap();
    $fee = pgf_calcFeeAmount($total, $gateway, $feesMap);
    if ($fee > 0) {
        pgf_addFeeItem($invoiceId, $gateway, $fee);
        // 再重算一次總額
        localAPI('UpdateInvoice', ['invoiceid' => (int)$invoiceId]);
    }
}

/* ---------- 後端：在發票層加費 ---------- */

// 建立發票時
add_hook('InvoiceCreated', 1, function($vars) {
    $invoiceId = (int)$vars['invoiceid'];
    // 讀取該發票的付款方式後套用
    $gateway = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod') ?? '';
    pgf_applyInvoiceFee($invoiceId, $gateway);
});

// 管理員/客戶更改發票付款方式時
add_hook('InvoiceChangeGateway', 1, function($vars) {
    $invoiceId  = (int)$vars['invoiceid'];
    $newGateway = (string)$vars['paymentmethod'];
    pgf_applyInvoiceFee($invoiceId, $newGateway);
});

// 後台寄送前再保險重算一次（避免人工作業插入/刪除造成偏差）
add_hook('InvoiceCreationPreEmail', 1, function($vars) {
    $invoiceId = (int)$vars['invoiceid'];
    $gateway = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod') ?? '';
    pgf_applyInvoiceFee($invoiceId, $gateway);
});

// 後台發票操作按鈕（保險重算）
add_hook('AdminInvoicesControlsOutput', 1, function($vars) {
    $invoiceId = (int)$vars['invoiceid'];
    if ($invoiceId > 0) {
        $gateway = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod') ?? '';
        pgf_applyInvoiceFee($invoiceId, $gateway);
    }
    return ''; // 不輸出額外按鈕
});

/* ---------- 前端：結帳頁即時顯示（只顯示，不落單） ---------- */

add_hook('AfterCalculateCartTotals', 1, function($vars) {
    // 把小計/總計與顯示字串暫存到 session 給前端腳本用
    $_SESSION['pgf_cart_totals'] = [
        'totalFull' => method_exists($vars['total'], 'toFull') ? $vars['total']->toFull() : '',
        'total'     => method_exists($vars['total'], 'toNumeric') ? (float)$vars['total']->toNumeric() : 0.0,
    ];
});

add_hook('ClientAreaFooterOutput', 1, function($vars) {
    if (($vars['action'] ?? '') !== 'checkout') {
        return '';
    }

    $totals = $_SESSION['pgf_cart_totals'] ?? null;
    if (!$totals) return '';

    $totalFull = $totals['totalFull'] ?? '';
    $totalNum  = (float)($totals['total'] ?? 0);

    // 準備：讀取所有 gateway 的費率，生成 switch-case
    $feesMap = pgf_getAddonFeesMap();
    $cases = '';
    foreach ($feesMap as $gateway => $cfg) {
        $fixed   = (float)($cfg['fixed']   ?? 0);
        $percent = (float)($cfg['percent'] ?? 0);
        $est     = ceil( ($fixed + $totalNum * ($percent/100)) * 100 ) / 100;
        // 用目前貨幣格式字串替換數字，保留貨幣符號
        $display = $totalFull ? preg_replace('/[0-9.,]+/', number_format($est, 2, '.', ''), $totalFull) : number_format($est, 2, '.', '');
        $cases  .= "case '{$gateway}': feeStr='{$display}'; break;";
    }

    $js = <<<HTML
<script>
(function(){
    function updatePayssionFees(){
        var sel = document.querySelector('input[name="paymentmethod"]:checked');
        var feeEl = document.getElementById('payssion_fees');
        if(!feeEl) return;
        var feeStr = '';
        if(sel){
            switch (sel.value) { {$cases} default: break; }
        }
        feeEl.textContent = feeStr ? (' + (Payment Gateway Fees ' + feeStr + ')') : '';
    }
    function ensureLabel(){
        if(document.getElementById('payssion_fees')) return;
        var small = document.createElement('small');
        small.id = 'payssion_fees';
        // 嘗試插到幾個常見容器
        var targets = [
            document.getElementById('totalDueToday'),
            document.querySelector('.alert-success'),
            document.querySelector('.total > .text-center')
        ];
        for (var i=0;i<targets.length;i++){
            if(targets[i]){
                targets[i].appendChild(small);
                break;
            }
        }
    }
    document.addEventListener('change', function(e){
        if(e.target && e.target.name === 'paymentmethod'){ updatePayssionFees(); }
    });
    document.addEventListener('DOMContentLoaded', function(){
        ensureLabel();
        updatePayssionFees();
    });
})();
</script>
HTML;

    return $js;
});
