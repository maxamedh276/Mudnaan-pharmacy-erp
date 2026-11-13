<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header & Timeframe Filter Toolbar -->
<div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Reports & Business Analytics
            </h1>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">
                Intelligence
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Real-time financial performance, product sales velocity, inventory asset valuation, and customer aging.
        </p>
    </div>

    <!-- Actions: Print & Export -->
    <div class="flex items-center gap-2 print:hidden">
        <button type="button" onclick="window.print()" 
                class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg shadow-2xs transition-all">
            <i data-lucide="printer" class="w-4 h-4 text-slate-500"></i>
            <span>Print Report</span>
        </button>
        <button type="button" onclick="exportActiveTabCsv()" 
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Export CSV</span>
        </button>
    </div>
</div>

<!-- Date Filter Form Card (Screen Only) -->
<div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-2xs mb-6 print:hidden">
    <form method="GET" action="<?= site_url('reports') ?>" class="flex flex-wrap items-center justify-between gap-3 text-xs">
        <input type="hidden" name="tab" value="<?= esc($tab) ?>">

        <!-- Preset Quick Filter Buttons -->
        <div class="flex items-center gap-1.5 overflow-x-auto">
            <span class="text-slate-400 font-medium mr-1 text-[11px] uppercase tracking-wider">Presets:</span>
            <button type="button" onclick="setPreset('today')" class="px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 font-medium transition-colors">
                Today
            </button>
            <button type="button" onclick="setPreset('7days')" class="px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 font-medium transition-colors">
                Last 7 Days
            </button>
            <button type="button" onclick="setPreset('month')" class="px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 font-medium transition-colors">
                This Month
            </button>
            <button type="button" onclick="setPreset('30days')" class="px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 font-medium transition-colors">
                Last 30 Days
            </button>
        </div>

        <!-- Custom Date Range Picker -->
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5">
                <span class="text-slate-500 font-medium">From</span>
                <input type="date" name="date_from" id="filterFrom" value="<?= esc($dateFrom) ?>" 
                       class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-slate-700 focus:border-brand-500 outline-none text-xs">
            </div>
            <div class="flex items-center gap-1.5">
                <span class="text-slate-500 font-medium">To</span>
                <input type="date" name="date_to" id="filterTo" value="<?= esc($dateTo) ?>" 
                       class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-slate-700 focus:border-brand-500 outline-none text-xs">
            </div>
            <button type="submit" class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-semibold rounded-lg transition-colors">
                Apply
            </button>
        </div>
    </form>
</div>

<!-- Report Navigation Tabs -->
<div class="border-b border-slate-200 mb-6 print:hidden">
    <nav class="flex space-x-6 overflow-x-auto text-xs font-semibold" aria-label="Tabs">
        <a href="<?= site_url('reports?tab=sales&date_from=' . $dateFrom . '&date_to=' . $dateTo) ?>" 
           class="pb-3 border-b-2 flex items-center gap-2 <?= $tab === 'sales' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <i data-lucide="line-chart" class="w-4 h-4"></i>
            <span>Sales & Revenue Analytics</span>
        </a>

        <a href="<?= site_url('reports?tab=financial&date_from=' . $dateFrom . '&date_to=' . $dateTo) ?>" 
           class="pb-3 border-b-2 flex items-center gap-2 <?= $tab === 'financial' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <i data-lucide="scale" class="w-4 h-4"></i>
            <span>Financial Statements (P&L & BS)</span>
            <span class="text-[10px] px-1.5 py-0.2 bg-amber-50 text-amber-700 rounded border border-amber-200">PRO</span>
        </a>

        <a href="<?= site_url('reports?tab=inventory&date_from=' . $dateFrom . '&date_to=' . $dateTo) ?>" 
           class="pb-3 border-b-2 flex items-center gap-2 <?= $tab === 'inventory' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <i data-lucide="boxes" class="w-4 h-4"></i>
            <span>Inventory Valuation</span>
        </a>

        <a href="<?= site_url('reports?tab=customers&date_from=' . $dateFrom . '&date_to=' . $dateTo) ?>" 
           class="pb-3 border-b-2 flex items-center gap-2 <?= $tab === 'customers' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <i data-lucide="users" class="w-4 h-4"></i>
            <span>Customer Receivables & Aging</span>
        </a>
    </nav>
</div>

<!-- ========================================================== -->
<!-- TAB 1: SALES & REVENUE ANALYTICS -->
<!-- ========================================================== -->
<?php if ($tab === 'sales'): ?>
    <?php
        $totals = $salesReport['totals'] ?? [];
        $grossSales = (float) ($totals['total_sales'] ?? 0);
        $netSales   = (float) ($totals['net'] ?? 0);
        $taxTotal   = (float) ($totals['tax'] ?? 0);
        $discount   = (float) ($totals['discount'] ?? 0);
        $txCount    = (int) ($totals['transactions'] ?? 0);
        $netProfit  = (float) ($salesReport['net_profit'] ?? 0);
        $aov        = $txCount > 0 ? ($grossSales / $txCount) : 0;
        $topProducts = $salesReport['top_products'] ?? [];
    ?>

    <!-- 4 KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Gross Sales</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">USD <?= number_format($grossSales, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5"><?= number_format($txCount) ?> completed orders</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Estimated Gross Profit</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1">USD <?= number_format($netProfit, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Revenue minus COGS</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Average Order Value (AOV)</span>
            <div class="text-2xl font-bold text-brand-600 mt-1">USD <?= number_format($aov, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Ticket size per customer</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Discounts & Deductions</span>
            <div class="text-2xl font-bold text-amber-600 mt-1">USD <?= number_format($discount, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Total promotions applied</div>
        </div>
    </div>

    <!-- 2-Column Split: Top Products & Category Breakdown -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start mb-6">
        
        <!-- Top Selling Menu Items (7 cols) -->
        <div class="lg:col-span-7 bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Top Selling Products</h3>
                    <p class="text-xs text-slate-500">Fastest selling café items ranked by volume & revenue</p>
                </div>
                <span class="text-xs text-slate-400 font-mono">Ranked</span>
            </div>

            <div class="p-4 sm:p-6 overflow-x-auto">
                <?php if (empty($topProducts)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs">
                        No product sales recorded in the selected date range.
                    </div>
                <?php else: ?>
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                                <th class="py-2.5 px-3">Rank</th>
                                <th class="py-2.5 px-3">Product Item</th>
                                <th class="py-2.5 px-3 text-center">Qty Sold</th>
                                <th class="py-2.5 px-3 text-right">Gross Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php $rank = 1; foreach ($topProducts as $tp): ?>
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="py-2.5 px-3">
                                        <span class="w-5 h-5 rounded-full inline-flex items-center justify-center text-[10px] font-bold <?= $rank <= 3 ? 'bg-brand-50 text-brand-700 border border-brand-200' : 'bg-slate-100 text-slate-600' ?>">
                                            <?= $rank++ ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 font-semibold text-slate-800">
                                        <?= esc($tp['name']) ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center font-mono font-medium">
                                        <?= number_format((float) $tp['quantity_sold']) ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-mono font-bold text-slate-900">
                                        $<?= number_format((float) $tp['revenue'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sales by Category (5 cols) -->
        <div class="lg:col-span-5 bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">Sales by Category</h3>
                <p class="text-xs text-slate-500">Revenue contribution per menu classification</p>
            </div>

            <div class="p-4 sm:p-6 space-y-4">
                <?php if (empty($categorySales)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs">
                        No category data recorded in this period.
                    </div>
                <?php else: ?>
                    <?php 
                        $maxCatTotal = max(array_column($categorySales, 'total')) ?: 1;
                        foreach ($categorySales as $cs): 
                            $pct = round(($cs['total'] / $maxCatTotal) * 100);
                    ?>
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-slate-800"><?= esc($cs['label']) ?></span>
                                <span class="font-bold font-mono text-slate-900">$<?= number_format((float) $cs['total'], 2) ?></span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-brand-600 h-2 rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-slate-400 mt-0.5">
                                <span><?= number_format((float) $cs['quantity']) ?> items sold</span>
                                <span><?= $pct ?>% share</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

<!-- ========================================================== -->
<!-- TAB 2: FINANCIAL STATEMENTS (P&L, BALANCE SHEET, TRIAL BALANCE) -->
<!-- ========================================================== -->
<?php elseif ($tab === 'financial'): ?>
    <?php
        $incIncome  = (float) ($incomeStatement['total_income'] ?? 0);
        $incExpense = (float) ($incomeStatement['total_expense'] ?? 0);
        $netOperatingProfit = (float) ($incomeStatement['net_profit'] ?? 0);

        $bsAssets      = (float) ($balanceSheet['total_assets'] ?? 0);
        $bsLiabilities = (float) ($balanceSheet['total_liabilities'] ?? 0);
        $bsEquity      = (float) ($balanceSheet['total_equity'] ?? 0);
        $isBalanced    = (bool) ($balanceSheet['balanced'] ?? false);
    ?>

    <!-- Financial Statement Highlight Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Net Operating Profit</span>
            <div class="text-2xl font-bold <?= $netOperatingProfit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-1">
                USD <?= number_format($netOperatingProfit, 2) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">Operating Income minus Operating Expenses</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Current Assets</span>
            <div class="text-2xl font-bold text-brand-600 mt-1">
                USD <?= number_format($bsAssets, 2) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">Cash, Bank Accounts, Receivables & Stock</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Accounts Payable & Liabilities</span>
            <div class="text-2xl font-bold text-amber-600 mt-1">
                USD <?= number_format($bsLiabilities, 2) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">Outstanding supplier bills and obligations</div>
        </div>
    </div>

    <!-- 2-Column Split: Income Statement (P&L) vs Balance Sheet -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        
        <!-- 1. Income Statement (Profit & Loss) -->
        <div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs p-5">
            <div class="border-b border-slate-100 pb-3 mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Income Statement (Profit & Loss)</h3>
                    <p class="text-xs text-slate-500">For period <?= esc($dateFrom) ?> to <?= esc($dateTo) ?></p>
                </div>
                <span class="text-[10px] font-semibold px-2 py-0.5 bg-brand-50 text-brand-700 rounded border border-brand-200">Accrual GL</span>
            </div>

            <!-- Revenue Section -->
            <div class="space-y-2 mb-4 text-xs">
                <div class="font-bold text-slate-800 uppercase tracking-wider text-[11px]">1. Operating Revenue</div>
                <?php if (empty($incomeStatement['income'])): ?>
                    <div class="text-slate-400 italic pl-3">No revenue posted yet.</div>
                <?php else: ?>
                    <?php foreach ($incomeStatement['income'] as $inc): ?>
                        <div class="flex justify-between pl-3 text-slate-600 hover:text-slate-900">
                            <span><?= esc($inc['code']) ?> - <?= esc($inc['name']) ?></span>
                            <span class="font-mono font-medium">$<?= number_format((float) $inc['amount'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="flex justify-between pt-1 border-t border-slate-100 font-semibold text-slate-900">
                    <span>Total Revenue</span>
                    <span class="font-mono">$<?= number_format($incIncome, 2) ?></span>
                </div>
            </div>

            <!-- Expenses Section -->
            <div class="space-y-2 mb-4 text-xs">
                <div class="font-bold text-slate-800 uppercase tracking-wider text-[11px]">2. Operating Expenses & COGS</div>
                <?php if (empty($incomeStatement['expenses'])): ?>
                    <div class="text-slate-400 italic pl-3">No expenses posted yet.</div>
                <?php else: ?>
                    <?php foreach ($incomeStatement['expenses'] as $exp): ?>
                        <div class="flex justify-between pl-3 text-slate-600 hover:text-slate-900">
                            <span><?= esc($exp['code']) ?> - <?= esc($exp['name']) ?></span>
                            <span class="font-mono font-medium">$<?= number_format((float) $exp['amount'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="flex justify-between pt-1 border-t border-slate-100 font-semibold text-slate-900">
                    <span>Total Operating Expenses</span>
                    <span class="font-mono">$<?= number_format($incExpense, 2) ?></span>
                </div>
            </div>

            <!-- Net Result -->
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between text-xs font-bold">
                <span class="text-slate-900">NET PROFIT / (LOSS)</span>
                <span class="font-mono text-sm <?= $netOperatingProfit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                    $<?= number_format($netOperatingProfit, 2) ?>
                </span>
            </div>
        </div>

        <!-- 2. Balance Sheet Summary -->
        <div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs p-5">
            <div class="border-b border-slate-100 pb-3 mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Balance Sheet Statement</h3>
                    <p class="text-xs text-slate-500">As of <?= esc($dateTo) ?></p>
                </div>
                <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded border <?= $isBalanced ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                    <i data-lucide="<?= $isBalanced ? 'check-circle' : 'alert-circle' ?>" class="w-3 h-3"></i>
                    <span><?= $isBalanced ? 'Balanced' : 'Out of Balance' ?></span>
                </span>
            </div>

            <div class="space-y-4 text-xs">
                <!-- Assets -->
                <div>
                    <div class="font-bold text-slate-800 uppercase tracking-wider text-[11px] mb-1">Assets</div>
                    <?php foreach (($balanceSheet['assets'] ?? []) as $a): ?>
                        <div class="flex justify-between pl-3 text-slate-600">
                            <span><?= esc($a['name']) ?></span>
                            <span class="font-mono font-medium">$<?= number_format((float) $a['amount'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="flex justify-between pt-1 border-t border-slate-100 font-bold text-slate-900">
                        <span>Total Assets</span>
                        <span class="font-mono">$<?= number_format($bsAssets, 2) ?></span>
                    </div>
                </div>

                <!-- Liabilities -->
                <div>
                    <div class="font-bold text-slate-800 uppercase tracking-wider text-[11px] mb-1">Liabilities</div>
                    <?php foreach (($balanceSheet['liabilities'] ?? []) as $l): ?>
                        <div class="flex justify-between pl-3 text-slate-600">
                            <span><?= esc($l['name']) ?></span>
                            <span class="font-mono font-medium">$<?= number_format((float) $l['amount'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="flex justify-between pt-1 border-t border-slate-100 font-bold text-slate-900">
                        <span>Total Liabilities</span>
                        <span class="font-mono">$<?= number_format($bsLiabilities, 2) ?></span>
                    </div>
                </div>

                <!-- Equity -->
                <div>
                    <div class="font-bold text-slate-800 uppercase tracking-wider text-[11px] mb-1">Equity</div>
                    <div class="flex justify-between pl-3 text-slate-600">
                        <span>Retained Earnings / Current Period Profit</span>
                        <span class="font-mono font-medium">$<?= number_format((float) ($balanceSheet['net_profit'] ?? 0), 2) ?></span>
                    </div>
                    <div class="flex justify-between pt-1 border-t border-slate-100 font-bold text-slate-900">
                        <span>Total Equity</span>
                        <span class="font-mono">$<?= number_format($bsEquity, 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- 3. Trial Balance Register Table Card -->
    <div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Trial Balance General Ledger</h3>
                <p class="text-xs text-slate-500">Every active account balance debit and credit verification</p>
            </div>
        </div>

        <div class="p-4 sm:p-6 overflow-x-auto">
            <table id="trialBalanceTable" class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                        <th class="py-2.5 px-3">Account Code</th>
                        <th class="py-2.5 px-3">Account Title</th>
                        <th class="py-2.5 px-3 text-right">Debit Balance</th>
                        <th class="py-2.5 px-3 text-right">Credit Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700 font-mono">
                    <?php foreach (($trialBalance['lines'] ?? []) as $tb): ?>
                        <tr class="hover:bg-slate-50/60">
                            <td class="py-2 px-3 font-semibold text-slate-900"><?= esc($tb['code']) ?></td>
                            <td class="py-2 px-3 font-sans font-medium text-slate-800"><?= esc($tb['name']) ?></td>
                            <td class="py-2 px-3 text-right text-slate-900"><?= $tb['debit'] > 0 ? '$' . number_format($tb['debit'], 2) : '—' ?></td>
                            <td class="py-2 px-3 text-right text-slate-900"><?= $tb['credit'] > 0 ? '$' . number_format($tb['credit'], 2) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-bold text-slate-900 border-t border-slate-200 font-mono text-xs">
                        <td colspan="2" class="py-3 px-3 font-sans uppercase">Total Trial Balance</td>
                        <td class="py-3 px-3 text-right text-emerald-600">$<?= number_format((float) ($trialBalance['totals']['debit'] ?? 0), 2) ?></td>
                        <td class="py-3 px-3 text-right text-emerald-600">$<?= number_format((float) ($trialBalance['totals']['credit'] ?? 0), 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<!-- ========================================================== -->
<!-- TAB 3: INVENTORY VALUATION & STOCK HEALTH -->
<!-- ========================================================== -->
<?php elseif ($tab === 'inventory'): ?>
    <?php
        $invRows    = $stockValuation['rows'] ?? [];
        $totalCostVal = (float) ($stockValuation['total_cost'] ?? 0);
        $totalRetailVal = (float) ($stockValuation['total_retail'] ?? 0);
        $marginVal = $totalRetailVal - $totalCostVal;
    ?>

    <!-- 3 KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Stock Asset Value (At Cost)</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">USD <?= number_format($totalCostVal, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Wholesale purchasing value on hand</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Potential Retail Value</span>
            <div class="text-2xl font-bold text-brand-600 mt-1">USD <?= number_format($totalRetailVal, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Estimated gross proceeds if all sold</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Unrealized Inventory Margin</span>
            <div class="text-2xl font-bold text-emerald-600 mt-1">USD <?= number_format($marginVal, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Projected profit margin across active stock</div>
        </div>
    </div>

    <!-- Stock Valuation Data Table Card -->
    <div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden mb-6">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Catalogue Stock Valuation Register</h3>
                <p class="text-xs text-slate-500">Itemized breakdown of product on-hand levels, costs, and assets</p>
            </div>
        </div>

        <div class="p-4 sm:p-6 overflow-x-auto">
            <table id="inventoryValuationTable" class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                        <th class="py-2.5 px-3">Product Name</th>
                        <th class="py-2.5 px-3 text-center">In-Stock Qty</th>
                        <th class="py-2.5 px-3 text-right">Unit Cost</th>
                        <th class="py-2.5 px-3 text-right">Unit Retail</th>
                        <th class="py-2.5 px-3 text-right">Total Cost Value</th>
                        <th class="py-2.5 px-3 text-right">Total Retail Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($invRows as $ir): ?>
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-2.5 px-3 font-semibold text-slate-900">
                                <?= esc($ir['name']) ?>
                            </td>
                            <td class="py-2.5 px-3 text-center font-mono font-bold <?= $ir['quantity'] <= 0 ? 'text-rose-600' : 'text-slate-800' ?>">
                                <?= number_format($ir['quantity']) ?>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-500">
                                $<?= number_format($ir['cost_price'], 2) ?>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-800">
                                $<?= number_format($ir['sell_price'], 2) ?>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-medium text-slate-900">
                                $<?= number_format($ir['cost_value'], 2) ?>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-bold text-brand-600">
                                $<?= number_format($ir['retail_value'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ========================================================== -->
<!-- TAB 4: CUSTOMER RECEIVABLES & AGING -->
<!-- ========================================================== -->
<?php elseif ($tab === 'customers'): ?>
    
    <!-- 3 KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Customer Receivables</span>
            <div class="text-2xl font-bold text-rose-600 mt-1">USD <?= number_format($totalOwed, 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Total credit owed by registered patrons</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Outstanding Debtor Accounts</span>
            <div class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($totalDebtors) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">Customers with pending store credit balance</div>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200/80 shadow-2xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Average Credit Balance</span>
            <div class="text-2xl font-bold text-slate-900 mt-1">
                USD <?= $totalDebtors > 0 ? number_format($totalOwed / $totalDebtors, 2) : '0.00' ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">Average debt per credit patron</div>
        </div>
    </div>

    <!-- Customer Balances Table Card -->
    <div class="bg-white rounded-xl border border-slate-200/80 shadow-2xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Customer Balances & Credit Ledger</h3>
                <p class="text-xs text-slate-500">Every client with an active credit account</p>
            </div>
        </div>

        <div class="p-4 sm:p-6 overflow-x-auto">
            <table id="customerBalancesTable" class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                        <th class="py-2.5 px-3">Customer Name</th>
                        <th class="py-2.5 px-3">Phone Number</th>
                        <th class="py-2.5 px-3 text-right">Amount Owed (Receivable)</th>
                        <th class="py-2.5 px-3 text-center">Status</th>
                        <th class="py-2.5 px-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($customerBalances as $cb): ?>
                        <?php if ($cb['owed'] > 0.005): ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="py-2.5 px-3 font-semibold text-slate-900">
                                    <?= esc($cb['name']) ?>
                                </td>
                                <td class="py-2.5 px-3 font-mono text-slate-600">
                                    <?= esc($cb['phone'] ?: '—') ?>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-rose-600">
                                    USD <?= number_format($cb['owed'], 2) ?>
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                        Pending Credit
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <a href="<?= site_url('clients') ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-800">
                                        <span>Statement</span>
                                        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<style>
@media print {
    body {
        background: white !important;
        color: black !important;
    }
    aside, header, #mobile-backdrop, .print\:hidden {
        display: none !important;
    }
    main {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }
}
</style>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        if ($('#trialBalanceTable').length) {
            erp.initDataTable('#trialBalanceTable', { pageLength: 25 });
        }
        if ($('#inventoryValuationTable').length) {
            erp.initDataTable('#inventoryValuationTable', { pageLength: 25 });
        }
        if ($('#customerBalancesTable').length) {
            erp.initDataTable('#customerBalancesTable', { pageLength: 25 });
        }
    });

    function setPreset(type) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayStr = `${yyyy}-${mm}-${dd}`;

        let fromStr = todayStr;
        if (type === 'today') {
            fromStr = todayStr;
        } else if (type === '7days') {
            const d = new Date();
            d.setDate(d.getDate() - 6);
            fromStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        } else if (type === 'month') {
            fromStr = `${yyyy}-${mm}-01`;
        } else if (type === '30days') {
            const d = new Date();
            d.setDate(d.getDate() - 29);
            fromStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        document.getElementById('filterFrom').value = fromStr;
        document.getElementById('filterTo').value = todayStr;
        document.querySelector('form[action*="reports"]').submit();
    }

    function exportActiveTabCsv() {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'sales';
        const from = urlParams.get('date_from') || '<?= $dateFrom ?>';
        const to = urlParams.get('date_to') || '<?= $dateTo ?>';

        if (tab === 'sales') {
            window.location.href = `<?= site_url('api/v1/reports/sales') ?>?date_from=${from}&date_to=${to}`;
        } else if (tab === 'financial') {
            window.location.href = `<?= site_url('api/v1/reports/trial-balance') ?>?date_to=${to}`;
        } else if (tab === 'inventory') {
            window.location.href = `<?= site_url('api/v1/reports/stock-valuation') ?>`;
        } else if (tab === 'customers') {
            window.location.href = `<?= site_url('api/v1/reports/customer-balances') ?>`;
        }
    }
</script>
<?= $this->endSection() ?>
