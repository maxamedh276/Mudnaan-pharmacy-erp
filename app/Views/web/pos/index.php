<?php $receiptBranding=(new \App\Services\ReceiptBrandingService())->get(); ?>
<?php $brand=preg_match('/^#[a-f0-9]{6}$/i',$settings['brand_color']??'')?$settings['brand_color']:'#1967d2'; $brandPalette=\App\Services\AppearanceSettingsService::palette($brand); ?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title><?= esc($receiptBranding['store_name'].' POS') ?></title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    <?= $this->include('partials/typography_theme') ?>
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        brand: <?= json_encode($brandPalette) ?>
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Thermal Receipt Printing */
        @media print {
            body * {
                visibility: hidden !important;
            }
            #thermalReceiptPrint, #thermalReceiptPrint * {
                visibility: visible !important;
            }
            #thermalReceiptPrint {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 80mm !important;
                margin: 0 !important;
                padding: 10px !important;
                background: white !important;
                font-family: 'JetBrains Mono', monospace !important;
            }
        }
    </style>
    <link rel="stylesheet" href="<?= base_url('assets/css/pos-layout.css').'?v='.filemtime(FCPATH.'assets/css/pos-layout.css') ?>">
    <style>:root { --pos-brand: <?= $brand ?>; }</style>
    <?= $this->include('partials/typography') ?>
</head>
<body class="pos-shell h-full overflow-hidden text-slate-800 antialiased select-none">

    <!-- Pass Authentication & Base URL to Javascript -->
    <script>
        window.ERP_BASE_URL = '<?= rtrim(site_url(), '/') ?>';
        window.ERP_TOKEN = '<?= esc(session()->get('token') ?? '') ?>';
    </script>

    <!-- Main Full-Screen Split Layout -->
    <div id="posWorkspace" class="pos-workspace overflow-hidden" data-pos-view="catalog">
        <nav class="pos-mobile-navigation" aria-label="POS views">
            <button type="button" data-pos-view-button="catalog" aria-controls="posCatalogPanel" aria-pressed="true">Products</button>
            <button type="button" data-pos-view-button="ticket" aria-controls="posTicketPanel" aria-pressed="false">Current order (<span id="mobilePosCount">0</span>)</button>
        </nav>
        
        <!-- ========================================== -->
        <!-- LEFT PANEL: CATALOG & SEARCH (72% on desktop) -->
        <!-- ========================================== -->
        <div id="posCatalogPanel" class="pos-catalog flex flex-col bg-slate-50 min-w-0 overflow-hidden" aria-label="Product catalog">
            
            <!-- Top POS Header Bar -->
            <div class="pos-catalog-header bg-white border-b border-slate-200/90 px-4 py-3 flex items-center justify-between gap-3 shrink-0">
                
                <!-- Left: Back to ERP Dashboard & Search -->
                <div class="flex items-center gap-3 flex-1 max-w-2xl">
                    <a href="<?= site_url('dashboard') ?>" 
                       title="Back to ERP Dashboard"
                       class="w-9 h-9 rounded-xl border border-slate-200 hover:border-slate-300 bg-white text-slate-600 hover:text-slate-900 flex items-center justify-center transition-colors shadow-2xs shrink-0">
                        <i data-lucide="chevron-left" class="w-5 h-5"></i>
                    </a>

                    <!-- Search Input with Ctrl+K shortcut -->
                    <div class="pos-catalog-search relative flex-1">
                        <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                        <input type="text" id="productSearchInput" 
                               placeholder="Search products..." aria-label="Search products"
                               autocomplete="off"
                               class="w-full pl-9 pr-14 py-2 bg-slate-50 hover:bg-white focus:bg-white border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 rounded-xl text-xs sm:text-sm text-slate-800 placeholder-slate-400 transition-all outline-none">
                        <span class="pos-search-shortcut absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-slate-400 bg-slate-200/70 border border-slate-300/80 px-1.5 py-0.5 rounded shadow-2xs">Ctrl K</span>
                    </div>
                </div>

                <!-- Right: Active/Today Orders count button -->
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openOrdersDrawer()" 
                            class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white rounded-xl text-xs font-semibold shadow-sm transition-all">
                        <span>Orders</span>
                        <span id="posOrdersBadge" class="px-1.5 py-0.2 bg-white/25 rounded-md font-bold text-[11px]"><?= (int) ($ordersCount ?? 0) ?></span>
                    </button>
                </div>
            </div>

            <!-- Horizontal Category Navigation Bar -->
            <div class="pos-categories bg-white border-b border-slate-200/80 px-4 py-2.5 overflow-x-auto shrink-0 flex items-center gap-2 no-scrollbar">
                <button type="button" onclick="filterCategory('all')" 
                        class="cat-pill active px-4 py-1.5 rounded-xl text-xs font-semibold border transition-all whitespace-nowrap bg-brand-600 text-white border-brand-600 shadow-2xs"
                        data-cat="all">
                    All
                </button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" onclick="filterCategory(<?= (int) $cat['id'] ?>)" 
                            class="cat-pill px-4 py-1.5 rounded-xl text-xs font-semibold border transition-all whitespace-nowrap bg-white text-slate-600 hover:text-slate-900 border-slate-200 hover:border-slate-300"
                            data-cat="<?= (int) $cat['id'] ?>">
                        <?= esc($cat['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Product Cards Catalog Area (Scrollable Grid) -->
            <div class="pos-catalog-scroll flex-1 overflow-y-auto p-4 sm:p-5">
                <div id="productsGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3.5">
                    <?php foreach ($products as $p): ?>
                        <?php 
                            $isAvail = (bool) $p['is_active'];
                            $price = (float) $p['sell_price'];
                            $imagePath = trim((string) ($p['image_path'] ?? ''));
                            // Uploaded paths are relative to the app root, even on nested POS routes.
                            $img = $imagePath === '' || preg_match('~^(?:https?:)?//~i', $imagePath)
                                ? $imagePath
                                : base_url(ltrim($imagePath, '/'));
                        ?>
                        <div class="pos-product-card product-card group relative bg-white rounded-2xl border border-slate-200/90 hover:border-brand-400 hover:shadow-md transition-all duration-200 overflow-hidden flex flex-col <?= $isAvail ? 'cursor-pointer' : 'cursor-not-allowed opacity-80' ?>"
                             role="button" tabindex="<?= $isAvail ? '0' : '-1' ?>" aria-disabled="<?= $isAvail ? 'false' : 'true' ?>" aria-label="<?= esc('Add '.$p['name'].' to order','attr') ?>"
                             data-id="<?= (int) $p['id'] ?>"
                             data-barcode="<?= esc($p['barcode'] ?? '', 'attr') ?>"
                             data-sealed-barcode="<?= esc($p['sealed_barcode'] ?? '', 'attr') ?>"
                             data-sku="<?= esc($p['sku'] ?? '', 'attr') ?>"
                             data-name="<?= esc($p['name']) ?>"
                             data-price="<?= number_format($price, 2, '.', '') ?>"
                             data-cat="<?= (int) $p['category_id'] ?>"
                             data-available="<?= $isAvail ? '1' : '0' ?>"
                             onclick="handleCardClick(this)">
                            
                            <!-- Product Image Container -->
                            <div class="relative w-full h-32 sm:h-36 bg-slate-100 overflow-hidden shrink-0">
                                <span aria-hidden="true" class="absolute inset-0 grid place-items-center text-sm font-semibold text-slate-400">Product</span>
                                <?php if ($img !== ''): ?>
                                    <img src="<?= esc($img, 'attr') ?>"
                                         alt="<?= esc($p['name'], 'attr') ?>"
                                         onerror="this.remove()"
                                         class="relative w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                <?php endif; ?>
                                
                                <!-- Unavailable Overlay Badge -->
                                <?php if (! $isAvail): ?>
                                    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[1px] flex items-center justify-center p-2">
                                        <span class="px-3 py-1 bg-slate-800/90 text-white text-[11px] font-semibold rounded-full shadow-md tracking-wide">
                                            Unavailable
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Product Details & Add Button -->
                            <div class="p-3 flex-1 flex flex-col justify-between">
                                <div>
                                    <h3 class="text-xs sm:text-sm font-semibold text-slate-800 line-clamp-1 group-hover:text-brand-600 transition-colors">
                                        <?= esc($p['name']) ?>
                                    </h3>
                                </div>
                                <div class="mt-2.5 flex items-center justify-between">
                                    <span class="text-xs font-semibold text-slate-500 font-mono">
                                        USD <?= number_format($price, 2) ?>
                                    </span>

                                    <?php if ($isAvail): ?>
                                        <span aria-hidden="true"
                                                class="w-7 h-7 rounded-full bg-brand-50 text-brand-600 hover:bg-brand-600 hover:text-white flex items-center justify-center transition-all shadow-2xs group-active:scale-90">
                                            <i data-lucide="plus" class="w-4 h-4"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Centered Hint Message -->
                <div class="mt-8 mb-4 text-center">
                    <p class="text-xs text-slate-400 flex items-center justify-center gap-1.5">
                        <i data-lucide="corner-down-right" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Select a product to add it to the current order</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- RIGHT PANEL: CURRENT ORDER TICKET (28% on desktop) -->
        <!-- ========================================== -->
        <div id="posTicketPanel" class="pos-ticket bg-white border-l border-slate-200 flex flex-col shrink-0 shadow-lg lg:shadow-none z-10" aria-label="Current order and checkout">
            
            <!-- Order Panel Header -->
            <div class="pos-ticket-header px-5 py-3.5 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center shadow-xs">
                        <i data-lucide="coffee" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h2 id="posTicketTitle" tabindex="-1" class="text-sm font-bold text-slate-900 leading-tight">Current order</h2>
                    </div>
                </div>

                <a href="<?= site_url('dashboard') ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 hover:text-slate-900 rounded-lg text-xs font-semibold transition-colors shadow-2xs">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 text-brand-600"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <!-- Customer Selector Bar (Requested by user) -->
            <div class="px-4 py-2.5 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between gap-2 shrink-0">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Customer</label>
                        <button type="button" onclick="openAddCustomerModal()" class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:text-brand-700 hover:underline">
                            <i data-lucide="user-plus" class="w-3 h-3"></i>
                            <span>Add new customer</span>
                        </button>
                    </div>
                    <select id="posCustomerSelect" onchange="handleCustomerChange(this)"
                            class="w-full py-1.5 px-2 bg-white border border-slate-200 focus:border-brand-500 rounded-lg text-xs font-medium text-slate-800 outline-none transition-all shadow-2xs">
                        <option value="" data-balance="0.00" data-phone="">Walk-in Customer</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" 
                                    data-balance="<?= number_format((float) ($c['balance'] ?? 0), 2, '.', '') ?>"
                                    data-credit-limit="<?= esc($c['credit_limit'], 'attr') ?>" data-outstanding="<?= esc($c['outstanding'], 'attr') ?>" data-available-credit="<?= esc($c['available_credit'], 'attr') ?>"
                                    data-phone="<?= esc($c['phone'] ?? '') ?>">
                                <?= esc($c['name']) ?> (<?= (float) ($c['balance'] ?? 0) < 0 ? 'Owes $' . number_format(abs((float) $c['balance']), 2) : '$' . number_format((float) $c['balance'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pos-ticket-scroll">
            <section id="posCreditPanel" hidden class="m-3 mb-0 p-3 rounded-xl border border-slate-200 bg-slate-50 shrink-0" aria-label="Customer credit">
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div><span class="block text-[10px] text-slate-500">Credit limit</span><strong id="posCreditLimit" class="break-words">$0.00</strong></div>
                    <div><span class="block text-[10px] text-slate-500">Outstanding</span><strong id="posCreditOutstanding" class="break-words">$0.00</strong></div>
                    <div><span class="block text-[10px] text-slate-500">Available</span><strong id="posCreditAvailable" class="break-words">$0.00</strong></div>
                </div>
                <p id="posCreditMessage" class="text-[11px] mt-2 text-slate-500" aria-live="polite"></p>
                <button id="posCreditReload" type="button" class="text-xs text-brand-600 font-semibold mt-1 underline">Refresh credit</button>
            </section>
            <!-- Order Items List Area (Scrollable) -->
            <div id="cartItemsContainer" class="flex-1 overflow-y-auto p-4 space-y-2.5">
                <!-- Empty State -->
                <div id="cartEmptyState" class="h-full flex flex-col items-center justify-center text-center p-6 text-slate-400">
                    <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-slate-300 mb-3">
                        <i data-lucide="shopping-bag" class="w-6 h-6"></i>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Your order is empty.</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Tap a product to add it.</p>
                </div>

                <!-- Dynamic Items list rendered via Javascript -->
                <div id="cartItemsList" class="space-y-2"></div>
            </div>

            <!-- Checkout / Financial Inputs / Payment Methods Area (Sticky at bottom) -->
            <div class="pos-checkout border-t border-slate-200 bg-white p-3.5 space-y-2.5 shrink-0">
                
                <!-- 1. Financial Calculation Inputs: Total, VAT, Discount, Shipping (Placed before payment method selections) -->
                <div class="bg-slate-50/90 rounded-xl p-2.5 border border-slate-200/80">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <!-- Total ($) - Readonly (Computed from item lines) -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-0.5">Total ($)</label>
                            <input type="number" id="posInputTotal" step="0.01" readonly value="0.00"
                                   class="w-full px-2.5 py-1 bg-slate-100 border border-slate-200 rounded-lg text-xs font-mono font-bold text-slate-700 cursor-not-allowed outline-none">
                        </div>

                        <!-- VAT ($) - Readonly (For future VAT management section) -->
                        <div>
                            <div class="flex items-center justify-between mb-0.5">
                                <label id="posTaxLabel" class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Tax ($)</label>
                                <span class="text-[9px] text-slate-400 font-medium">Readonly</span>
                            </div>
                            <input type="number" id="posInputVat" step="0.01" readonly value="0.00"
                                   class="w-full px-2.5 py-1 bg-slate-100 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-500 cursor-not-allowed outline-none">
                        </div>

                        <!-- Discount ($) - Editable (ontype / onwriting live update) -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-0.5">Discount ($)</label>
                            <input type="number" id="posInputDiscount" step="0.01" min="0" value="0.00" placeholder="0.00"
                                   oninput="handleFinancialInputChange()"
                                   class="w-full px-2.5 py-1 bg-white border border-slate-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 rounded-lg text-xs font-mono font-semibold text-slate-800 outline-none transition-all shadow-2xs">
                        </div>

                        <!-- Shipping ($) - Editable (ontype / onwriting live update) -->
                        <div>
                            <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-0.5">Shipping ($)</label>
                            <input type="number" id="posInputShipping" step="0.01" min="0" value="0.00" placeholder="0.00"
                                   oninput="handleFinancialInputChange()"
                                   class="w-full px-2.5 py-1 bg-white border border-slate-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 rounded-lg text-xs font-mono font-semibold text-slate-800 outline-none transition-all shadow-2xs">
                        </div>
                    </div>
                </div>

                <!-- 3. Paid Amount Input (0.00 default, with quick fill & real-time credit indicator) -->
                <div class="bg-blue-50/60 rounded-xl p-2.5 border border-blue-100">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-[10px] font-bold text-slate-700 uppercase tracking-wider">Paid Amount ($)</label>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="setExactPaid()" class="text-[10px] px-2 py-0.5 rounded bg-white hover:bg-brand-50 text-brand-700 font-bold border border-blue-200 shadow-2xs transition-colors">
                                Exact Full
                            </button>
                            <button type="button" onclick="setZeroPaid()" class="text-[10px] px-2 py-0.5 rounded bg-white hover:bg-slate-50 text-slate-600 font-semibold border border-slate-200 shadow-2xs transition-colors">
                                $0.00 (Credit)
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <span class="absolute left-3 top-2 text-slate-400 font-mono text-sm font-bold">$</span>
                        <input type="number" id="posInputPaid" step="0.01" min="0" value="0.00" placeholder="0.00"
                               oninput="handlePaidInputChange()"
                               class="w-full pl-7 pr-3 py-1.5 bg-white border border-blue-200 focus:border-brand-600 focus:ring-1 focus:ring-brand-600 rounded-lg text-sm font-mono font-extrabold text-slate-900 outline-none transition-all shadow-2xs">
                    </div>
                    <!-- Live Settlement & Credit Status -->
                    <div id="paidStatusBanner" class="mt-1 text-[11px] font-medium flex items-center justify-between text-slate-600">
                        <span id="paidStatusText">Unpaid ($0.00) — Full Store Credit</span>
                    </div>
                </div>

                <!-- 2. Grand Total Display (Placed where Total section was) -->
                <div class="flex items-baseline justify-between border-t border-slate-100 pt-2 pb-1">
                    <div>
                        <span class="text-sm font-bold text-slate-900 tracking-tight">Grand Total</span>
                        <span id="posItemsCountBadge" class="text-[11px] text-slate-400 block font-normal">0 items in ticket</span>
                    </div>
                    <span id="cartGrandTotalLabel" class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono">$0.00</span>
                </div>

                <!-- 4. Dynamic POS Payment Methods (Loaded from Chart of Accounts) -->
                <div class="space-y-1.5 pt-0.5">
                    <div class="flex items-center justify-between mb-0.5">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Payment Method</label>
                        <a href="<?= site_url('finance/pos-methods') ?>" target="_blank" title="Manage POS Payment Methods in Chart of Accounts" class="text-[10px] font-semibold text-brand-600 hover:text-brand-700 hover:underline flex items-center gap-1">
                            <i data-lucide="settings-2" class="w-3 h-3"></i>
                            <span>Configure</span>
                        </a>
                    </div>

                    <select id="posPaymentMethod" aria-label="Payment method" onchange="handlePaymentMethodChange(this)" class="w-full border border-slate-200 rounded-lg text-xs">
                        <?php foreach (($posPaymentMethods ?? []) as $pm): ?>
                            <option value="<?= esc($pm['code'], 'attr') ?>" data-account-id="<?= (int)$pm['account_id'] ?>" data-name="<?= esc($pm['name'], 'attr') ?>" data-type="<?= esc($pm['type'], 'attr') ?>"><?= esc($pm['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($posPaymentMethods)): ?><option value="">No active payment methods</option><?php endif; ?>
                    </select>
                </div>

                <!-- 5. Single Primary Action Button -->
                <div class="pt-1">
                    <button type="button" id="payNowBtn" onclick="processCheckout()" 
                            class="w-full py-3 bg-brand-600 hover:bg-brand-700 active:scale-[0.99] text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-brand-500/20 flex items-center justify-center gap-2 transition-all">
                        <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                        <span id="payNowBtnLabel">Complete Sale ($0.00 Paid)</span>
                    </button>
                </div>

                <!-- 6. Reset Entire Ticket Button (Placed below payment button) -->
                <button type="button" onclick="confirmResetTicket()" 
                        class="w-full py-2 bg-slate-50 hover:bg-rose-50 border border-slate-200 hover:border-rose-200 text-slate-500 hover:text-rose-600 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition-all shadow-2xs">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                    <span>Reset Entire Ticket</span>
                </button>

            </div>
            </div><!-- Ticket scrolling surface -->
        </div>
        <footer class="pos-mobile-summary">
            <button type="button" id="mobilePosReview" aria-controls="posTicketPanel"><span>View order · <span id="mobilePosItems">0 items</span></span><strong id="mobilePosTotal">$0.00</strong></button>
        </footer>
    </div>

    <?= view('partials/copyright') ?>

    <!-- ========================================== -->
    <!-- MODAL: QUICK ADD CUSTOMER (POS Quick Modal) -->
    <!-- ========================================== -->
    <div id="quickCustomerModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-slate-800 animate-in fade-in zoom-in duration-150">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 bg-brand-50 text-brand-600 rounded-lg flex items-center justify-center">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900">Add New Customer</h3>
                </div>
                <button type="button" onclick="closeAddCustomerModal()" class="text-slate-400 hover:text-slate-600 p-1">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form id="quickCustomerForm" onsubmit="handleQuickCustomerSubmit(event)" class="space-y-3.5 text-xs">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Customer Full Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Sahra Jama"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Mobile Phone (Waafi / Evc)</label>
                    <input type="text" name="phone" placeholder="+252 61 1234567"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Email Address (Optional)</label>
                    <input type="email" name="email" placeholder="customer@mail.com"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                </div>
                
                <?= view('partials/customer_credit_field', ['creditFieldId' => 'quick-cust-credit-limit', 'canManageCredit' => $canManageCredit]) ?>
                <div class="pt-2 flex gap-2">
                    <button type="submit" id="saveCustBtn"
                            class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-xl transition-colors">
                        Save & Select
                    </button>
                    <button type="button" onclick="closeAddCustomerModal()" 
                            class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- DRAWER / MODAL: TODAY'S ORDERS & PAST BILLS -->
    <!-- ========================================== -->
    <div id="ordersDrawerModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-end">
        <div class="bg-white h-full max-w-md w-full shadow-2xl flex flex-col animate-in slide-in-from-right duration-200">
            <div class="p-4 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Today's Orders Register</h3>
                    <p class="text-xs text-slate-500">Live completed bills & credit tickets</p>
                </div>
                <button type="button" onclick="closeOrdersDrawer()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div id="ordersListArea" class="flex-1 overflow-y-auto p-4 space-y-2.5 text-xs">
                <?php if (empty($todaySales)): ?>
                    <div class="h-48 flex flex-col items-center justify-center text-slate-400">
                        <i data-lucide="receipt" class="w-8 h-8 text-slate-300 mb-2"></i>
                        <span>No orders recorded yet today.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($todaySales as $ts): ?>
                        <div class="p-3 bg-slate-50 hover:bg-slate-100/80 rounded-xl border border-slate-200/80 flex items-center justify-between transition-colors">
                            <div>
                                <div class="font-bold text-slate-800 font-mono"><?= esc($ts['invoice_no'] ?: '#' . $ts['id']) ?></div>
                                <div class="text-[11px] text-slate-500"><?= esc($ts['customer_name'] ?? 'Walk-in') ?> · <?= date('h:i A', strtotime($ts['sold_at'] ?? 'now')) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-slate-900 font-mono">$<?= number_format((float) $ts['net_total'], 2) ?></div>
                                <button type="button" onclick="reprintReceipt(<?= (int) $ts['id'] ?>)" 
                                        class="text-[11px] font-semibold text-brand-600 hover:text-brand-800 inline-flex items-center gap-1 mt-0.5">
                                    <i data-lucide="printer" class="w-3 h-3"></i>
                                    <span>Print</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="p-4 border-t border-slate-200 bg-slate-50">
                <a href="<?= site_url('sales') ?>" class="w-full py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 text-xs font-semibold rounded-xl flex items-center justify-center gap-1.5">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    <span>Open Sales Management Screen</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL: THERMAL RECEIPT (Printable & Screen) -->
    <!-- ========================================== -->
    <div id="receiptModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-slate-800 relative">
            <button type="button" onclick="closeReceiptModal()" 
                    class="print:hidden absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>

            <!-- Printable Thermal Container -->
            <div id="thermalReceiptPrint" class="text-center font-mono text-xs">
                <div class="flex flex-col items-center mb-3">
                    <div class="w-10 h-10 bg-brand-600 text-white rounded-xl flex items-center justify-center mb-2 shadow-xs">
                        <i data-lucide="coffee" class="w-6 h-6"></i>
                    </div>
                    <h3 class="text-base font-bold text-slate-900 tracking-tight"><?= esc($receiptBranding['store_name']) ?></h3>
                    <p class="text-[11px] text-slate-500"><?= esc($receiptBranding['address']) ?></p>
                    <p class="text-[10px] text-slate-400">Tel: <?= esc($receiptBranding['phone']) ?></p>
                    <?php if($receiptBranding['header']!==''): ?><p class="text-[11px] text-slate-500 mt-1"><?= esc($receiptBranding['header']) ?></p><?php endif; ?>
                </div>

                <div class="border-t border-b border-dashed border-slate-300 py-2.5 my-3 text-left space-y-1 text-[11px]">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Invoice:</span>
                        <span id="posRcptInvoice" class="font-bold text-slate-800">#0000</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Date:</span>
                        <span id="posRcptDate" class="text-slate-700">Nov 03, 2026</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Customer:</span>
                        <span id="posRcptCustomer" class="font-semibold text-slate-800">Walk-in Customer</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Cashier:</span>
                        <span id="posRcptCashier" class="text-slate-700"><?= esc($user['name'] ?? 'Cashier') ?></span>
                    </div>
                </div>

                <table class="w-full text-left my-2.5 text-[11px]">
                    <thead>
                        <tr class="border-b border-slate-300 text-slate-500">
                            <th class="py-1">Item</th>
                            <th class="py-1 text-center">Qty</th>
                            <th class="py-1 text-right">Price</th>
                            <th class="py-1 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody id="posRcptItemsBody" class="divide-y divide-slate-100">
                        <!-- Items populated dynamically -->
                    </tbody>
                </table>

                <div class="border-t border-dashed border-slate-300 pt-2 space-y-1 text-[11px] text-right">
                    <div class="flex justify-between text-slate-500">
                        <span>Subtotal:</span>
                        <span id="posRcptSubtotal">$0.00</span>
                    </div>
                    <div class="flex justify-between text-slate-500">
                        <span>Discount:</span>
                        <span id="posRcptDiscount">$0.00</span>
                    </div>
                    <div class="flex justify-between text-slate-500"><span>Tax:</span><span id="posRcptTax">$0.00</span></div>
                    <div class="flex justify-between text-slate-500"><span>Shipping:</span><span id="posRcptShipping">$0.00</span></div>
                    <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-1 mt-1">
                        <span>TOTAL:</span>
                        <span id="posRcptGrandTotal">$0.00</span>
                    </div>
                    <div id="posRcptReturnRow" class="hidden text-amber-700"><div class="flex justify-between"><span>Returns:</span><span id="posRcptReturned"></span></div><div class="flex justify-between font-bold"><span>Net total:</span><span id="posRcptNet"></span></div><div class="flex justify-between"><span>Refunded:</span><span id="posRcptRefunded"></span></div></div>
                </div>

                <div class="border-t border-dashed border-slate-300 pt-2 mt-2 text-left text-[11px] space-y-1">
                    <div class="font-bold text-slate-700 mb-0.5">Payment Details:</div>
                    <div id="posRcptPayments">
                        <!-- Payment row -->
                    </div>
                    <div class="flex justify-between font-bold text-slate-800 pt-1">
                        <span>Amount Paid:</span>
                        <span id="posRcptPaid">$0.00</span>
                    </div>
                    <div id="posRcptDueRow" class="flex justify-between font-semibold text-rose-600 hidden">
                        <span>Balance Due:</span>
                        <span id="posRcptDue">$0.00</span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-200 text-center text-[10px] text-slate-500">
                    <p class="font-medium whitespace-pre-line"><?= esc($receiptBranding['footer']) ?></p>
                    <p class="text-slate-400 mt-0.5">Powered by <?= esc($receiptBranding['system_name']) ?></p>
                </div>
            </div>

            <!-- Modal Action Buttons (Screen only) -->
            <div class="print:hidden mt-5 flex flex-col gap-2">
                <button type="button" onclick="printDirectThermal()" id="posDirectPrintBtn"
                        class="w-full inline-flex items-center justify-center gap-2 py-3 bg-brand-600 hover:bg-brand-700 active:scale-[0.99] text-white rounded-xl text-xs font-bold shadow-sm transition-all">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                    <span>Print</span>
                </button>
            </div>
        </div>
    </div>

    <?= view('partials/feedback', ['feedbackMessages'=>[]]) ?>
    <script src="<?= base_url('assets/js/erp-feedback.js').'?v='.filemtime(FCPATH.'assets/js/erp-feedback.js') ?>"></script>
    <?= view('partials/qz_printing') ?>

    <!-- ========================================== -->
    <!-- POS JAVASCRIPT LOGIC & CART STATE ENGINE -->
    <!-- ========================================== -->
    <script src="<?= base_url('assets/js/pos-credit.js').'?v='.filemtime(FCPATH.'assets/js/pos-credit.js') ?>"></script>
    <script>
        <?php $firstPm = ($posPaymentMethods ?? [])[0] ?? ['code' => 'cash', 'account_id' => 1, 'name' => 'Cash', 'type' => 'cash']; ?>
        // Reactive Cart State
        const POS = {
            products: <?= json_encode(array_column($products, null, 'id'), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
            cart: [], // [{ id, name, price, qty }]
            activeTax: <?= json_encode($activeTax??null, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
            productTaxRates: <?= json_encode($productTaxRates??(object)[], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
            checkoutUuid: crypto.randomUUID(),
            selectedCategory: 'all',
            selectedCustomer: null, // null = walk-in
            activePaymentMethod: {
                code: <?= json_encode($firstPm['code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                accountId: <?= (int)$firstPm['account_id'] ?>,
                name: '<?= esc($firstPm['name'], 'js') ?>',
                type: '<?= esc($firstPm['type']) ?>'
            },
            orderDiscount: 0.00,
        };

        // Utility: Toast notification
        function showToast(msg, isError = false) {
            erp.toast(msg, isError ? 'error' : 'success');
        }

        // Generic API Helper
        async function apiCall(endpoint, method = 'GET', data = null) {
            const token = window.ERP_TOKEN;
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            };
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const opts = { method, headers };
            if (data && (method === 'POST' || method === 'PUT')) {
                opts.body = JSON.stringify(data);
            }

            const res = await fetch(window.ERP_BASE_URL + '/api/v1' + endpoint, opts);
            const json = await res.json();
            if (!res.ok) {
                throw new Error(json.message || 'API request failed');
            }
            return json;
        }

        // ==========================================
        // 1. Category Filtering & Search
        // ==========================================
        function filterCategory(catId) {
            POS.selectedCategory = catId;
            document.querySelectorAll('.cat-pill').forEach(btn => {
                if (btn.getAttribute('data-cat') == catId) {
                    btn.className = 'cat-pill active px-4 py-1.5 rounded-xl text-xs font-semibold border transition-all whitespace-nowrap bg-brand-600 text-white border-brand-600 shadow-2xs';
                } else {
                    btn.className = 'cat-pill px-4 py-1.5 rounded-xl text-xs font-semibold border transition-all whitespace-nowrap bg-white text-slate-600 hover:text-slate-900 border-slate-200 hover:border-slate-300';
                }
            });
            renderProductFilter();
        }

        function renderProductFilter() {
            const query = (document.getElementById('productSearchInput').value || '').toLowerCase().trim();
            document.querySelectorAll('.product-card').forEach(card => {
                const name = (card.getAttribute('data-name') || '').toLowerCase();
                const cat = card.getAttribute('data-cat');
                
                const matchesCat = (POS.selectedCategory === 'all' || cat == POS.selectedCategory);
                const matchesSearch = !query || [name, card.dataset.barcode, card.dataset.sealedBarcode, card.dataset.sku]
                    .some(value => (value || '').toLowerCase().includes(query));

                if (matchesCat && matchesSearch) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        document.getElementById('productSearchInput').addEventListener('input', renderProductFilter);

        // Keyboard Shortcut: Ctrl + K focuses product search
        window.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                window.posLayout?.showCatalog();
                const searchInput = document.getElementById('productSearchInput');
                searchInput.focus();
                searchInput.select();
            }
        });

        // ==========================================
        // 2. Card Click & Cart Management
        // ==========================================
        async function handleCardClick(card) {
            const isAvail = card.getAttribute('data-available') === '1';
            if (!isAvail) {
                showToast('This item is currently unavailable', true);
                return;
            }

            const id = parseInt(card.getAttribute('data-id'), 10);
            const name = card.getAttribute('data-name');
            const price = parseFloat(card.getAttribute('data-price'));

            const product = POS.products[id];
            const mode = product?.default_sale_mode || 'loose';
            addToCart(id, name, Number(mode === 'sealed' ? product.package_price : price), 1, mode, mode === 'sealed' ? product.package_unit : product?.unit);
        }

        function escapePosText(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; }
        function cartKey(item) { return item.key || `${item.id}:${item.stock_mode || 'loose'}`; }
        function addToCart(id, name, price, qty = 1, mode = 'loose', unit = '') {
            const key = `${id}:${mode}`;
            const existing = POS.cart.find(item => cartKey(item) === key);
            if (existing) { existing.qty = Math.round((existing.qty + qty) * 1000) / 1000; existing.quantity_option = null; }
            else POS.cart.push({id, key, name, price, qty, stock_mode: mode, unit});
            renderCart();
        }
        async function refreshPosStock() {
            const response = await erp.web(<?= json_encode(site_url('sales/pos/stock-data')) ?>);
            // Keep scanned products outside the first catalog page available to their cart rows.
            POS.products = {...POS.products, ...Object.fromEntries(response.data.map(product => [product.id, product]))};
        }
        window.addEventListener('focus', () => { void refreshPosStock().catch(() => {}); });
        function poolMessage(product, mode, requested) {
            const split = product.stock_split;
            const available = mode === 'sealed' ? split.sealed : split.opened;
            const unit = mode === 'sealed' ? product.package_unit : product.unit;
            if (requested <= available) return '';
            return mode === 'sealed' ? `${product.name}: only ${available} sealed ${unit} available.` : `${product.name}: opened stock is ${available} ${unit}; requested ${requested} ${unit}. Fadlan stock-ga ka fur ${product.package_unit} cusub.`;
        }
        async function showStockShortage(message) {
            const result = await Swal.fire({title: 'Opened / sealed stock unavailable', text: message, icon: 'warning', showCancelButton: true, confirmButtonText: 'Go to Stock (new tab)', cancelButtonText: 'Keep ticket', returnFocus: true});
            if (result.isConfirmed) window.open(<?= json_encode(site_url('inventory/stock')) ?>, '_blank', 'noopener');
        }
        function setCartQuantity(key, value, quantityOption = null) {
            const item = POS.cart.find(i => cartKey(i) === key);
            if (!item) return;
            const qty = Number(value);
            if (!Number.isFinite(qty) || qty <= 0 || qty > 999999999.999 || Math.abs(qty*1000-Math.round(qty*1000)) > 0.000001 || (item.stock_mode === 'sealed' && !Number.isInteger(qty))) {
                showToast('Enter a positive quantity; sealed packages must be whole numbers.',true); renderCart(); return;
            }
            const product = POS.products[item.id];
            const message = product?.package_unit ? poolMessage(product,item.stock_mode || 'loose',qty) : '';
            if (message) { renderCart(); void showStockShortage(message); return; }
            item.qty = qty; item.quantity_option = quantityOption; renderCart();
        }
        function applyQuantityShortcut(key, index) {
            const item = POS.cart.find(i=>cartKey(i)===key);
            const option = item && POS.products[item.id]?.quantity_shortcuts?.[index];
            if (option && item.stock_mode !== 'sealed') setCartQuantity(key,option.quantity,{label:option.label,quantity:Number(option.quantity)});
        }
        function changeCartUnit(key, mode) {
            const item = POS.cart.find(i=>cartKey(i)===key);
            if (!item || !['loose','sealed'].includes(mode)) return;
            const product = POS.products[item.id];
            if (mode === 'sealed' && !product.package_unit) return;
            const target = POS.cart.find(i=>i!==item && i.id===item.id && i.stock_mode===mode);
            const increment = mode === 'loose' && product.stock_split.opened > 0 && product.stock_split.opened < 1 ? product.stock_split.opened : 1;
            const qty = target ? Math.round((target.qty+increment)*1000)/1000 : increment;
            const message = product.package_unit ? poolMessage(product,mode,qty) : '';
            if (message) { renderCart(); void showStockShortage(message); return; }
            if (target) { target.qty=qty; target.quantity_option=null; POS.cart=POS.cart.filter(i=>i!==item); }
            else { item.quantity_option=null;item.stock_mode=mode;item.key=`${item.id}:${mode}`;item.qty=qty;item.unit=mode==='sealed'?product.package_unit:product.unit;item.price=Number(mode==='sealed'?product.package_price:product.sell_price); }
            renderCart();
        }
        function updateCartQty(key, delta) {
            const item = POS.cart.find(i => cartKey(i) === key);
            if (!item) return;
            const step = item.stock_mode !== 'sealed' && item.quantity_option ? Number(item.quantity_option.quantity) : 1;
            const next = Math.round((item.qty + delta * step) * 1000) / 1000;
            const product = POS.products[item.id];
            if (next > 0 && product?.package_unit) {
                const message = poolMessage(product, item.stock_mode || 'loose', next);
                if (message) { void showStockShortage(message); return; }
            }
            item.qty = next;
            if (item.qty <= 0) POS.cart = POS.cart.filter(i => i !== item);
            renderCart();
        }
        function removeCartItem(key) {
            POS.cart = POS.cart.filter(i => cartKey(i) !== key);
            renderCart();
        }

        function clearCart() {
            POS.checkoutUuid = crypto.randomUUID();
            POS.cart = [];
            POS.orderDiscount = 0.00;
            const discountInput = document.getElementById('posInputDiscount');
            if (discountInput) discountInput.value = '0.00';
            const shippingInput = document.getElementById('posInputShipping');
            if (shippingInput) shippingInput.value = '0.00';
            const paidInput = document.getElementById('posInputPaid');
            if (paidInput) paidInput.value = '0.00';
            renderCart();
        }

        async function confirmResetTicket() {
            if (document.getElementById('payNowBtn').disabled) return;
            if (POS.cart.length === 0) {
                showToast('Ticket is already empty', true);
                return;
            }
            const confirmation = await Swal.fire({title:'Reset this ticket?',text:'Clear all items, paid amount, discount and shipping from this unsaved order?',icon:'warning',showCancelButton:true,confirmButtonText:'Reset ticket',cancelButtonText:'Keep ticket',focusCancel:true,returnFocus:true});
            if (confirmation.isConfirmed) {
                clearCart();
                showToast('Ticket reset successfully');
            }
        }

        async function applyOrderDiscountPrompt() {
            if (document.getElementById('payNowBtn').disabled) return;
            const currentSubtotal = calculateSubtotal();
            if (currentSubtotal <= 0) {
                showToast('Add items to order before applying discount', true);
                return;
            }
            const discountInput = document.getElementById('posInputDiscount');
            const currentDiscount = discountInput ? (parseFloat(discountInput.value) || 0) : 0;
            const result = await Swal.fire({
                title: 'Order discount', input: 'number', inputLabel: 'Discount (USD)',
                inputValue: currentDiscount.toFixed(2), inputAttributes: {min:'0',max:String(currentSubtotal),step:'0.01'},
                showCancelButton: true, confirmButtonText: 'Apply discount', cancelButtonText: 'Cancel', returnFocus: true,
                inputValidator: value => {
                    const amount = Number(value);
                    if (String(value).trim() === '' || !Number.isFinite(amount) || amount < 0 || amount > calculateSubtotal() || Math.abs(amount*100-Math.round(amount*100)) > .000001) return 'Enter a valid discount up to the subtotal, with at most two decimal places.';
                },
            });
            if (!result.isConfirmed || document.getElementById('payNowBtn').disabled) return;
            const amount = Number(result.value);
            if (!Number.isFinite(amount) || amount < 0 || amount > calculateSubtotal() || Math.abs(amount*100-Math.round(amount*100)) > .000001) { showToast('Invalid discount amount',true); return; }
            if (discountInput) discountInput.value = amount.toFixed(2);
            handleFinancialInputChange();
            showToast('Discount updated: $' + amount.toFixed(2));
        }

        function calculateSubtotal() {
            return Math.round((POS.cart.reduce((sum, item) => sum + Math.round((item.price * item.qty + Number.EPSILON) * 100) / 100, 0) + Number.EPSILON) * 100) / 100;
        }

        function getFinancialValues() {
            const total = calculateSubtotal();
            const money = value => Math.round((value + Number.EPSILON) * 100) / 100;
            const vat = money(POS.cart.reduce((sum, item) => {
                const rate = POS.activeTax ? Number(POS.activeTax.percentage) : Number(POS.productTaxRates[item.id] || 0);
                return sum + money(money(item.price * item.qty) * rate / 100);
            }, 0));
            const taxInput = document.getElementById('posInputVat');
            if (taxInput) taxInput.value = vat.toFixed(2);
            const taxLabel = document.getElementById('posTaxLabel');
            if (taxLabel) taxLabel.textContent = POS.activeTax ? `${POS.activeTax.name} (${Number(POS.activeTax.percentage).toFixed(2)}%) · $` : 'Tax ($)';
            const discount = parseFloat(document.getElementById('posInputDiscount')?.value) || 0;
            const shipping = parseFloat(document.getElementById('posInputShipping')?.value) || 0;
            const grandTotal = Math.max(0, money(total + vat - discount + shipping));
            const paid = parseFloat(document.getElementById('posInputPaid')?.value) || 0;

            return { total, vat, discount, shipping, grandTotal, paid };
        }

        function handleFinancialInputChange() {
            const vals = getFinancialValues();
            const totalLabel = document.getElementById('cartGrandTotalLabel');
            if (totalLabel) totalLabel.textContent = '$' + vals.grandTotal.toFixed(2);
            updatePaidStatusAndButton(vals);
        }

        function handlePaidInputChange() {
            const vals = getFinancialValues();
            updatePaidStatusAndButton(vals);
        }

        function setExactPaid() {
            const vals = getFinancialValues();
            const paidInput = document.getElementById('posInputPaid');
            if (paidInput) paidInput.value = vals.grandTotal.toFixed(2);
            handlePaidInputChange();
        }

        function setZeroPaid() {
            const paidInput = document.getElementById('posInputPaid');
            if (paidInput) paidInput.value = '0.00';
            handlePaidInputChange();
        }

        function updatePaidStatusAndButton(vals) {
            if (typeof ERPPosCredit !== 'undefined') ERPPosCredit.render(vals);
            const statusText = document.getElementById('paidStatusText');
            const btnLabel = document.getElementById('payNowBtnLabel');
            const btn = document.getElementById('payNowBtn');

            if (!statusText || !btnLabel || !btn) return;

            if (vals.grandTotal === 0) {
                statusText.innerHTML = `<span class="text-slate-400">Order is empty</span>`;
                btnLabel.textContent = 'Complete Sale ($0.00 Paid)';
                btn.className = "w-full py-3 bg-brand-600 hover:bg-brand-700 active:scale-[0.99] text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-brand-500/20 flex items-center justify-center gap-2 transition-all";
                return;
            }

            if (vals.paid >= vals.grandTotal) {
                const change = vals.paid - vals.grandTotal;
                if (change > 0) {
                    statusText.innerHTML = `<span class="text-emerald-700 font-bold">✓ Fully Settled — Change Due: $${change.toFixed(2)}</span>`;
                } else {
                    statusText.innerHTML = `<span class="text-emerald-700 font-bold">✓ Fully Settled ($${vals.grandTotal.toFixed(2)} Paid)</span>`;
                }
                btnLabel.textContent = `Complete Sale ($${vals.grandTotal.toFixed(2)} Paid)`;
                btn.className = "w-full py-3 bg-brand-600 hover:bg-brand-700 active:scale-[0.99] text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-brand-500/20 flex items-center justify-center gap-2 transition-all";
            } else if (vals.paid > 0 && vals.paid < vals.grandTotal) {
                const credit = vals.grandTotal - vals.paid;
                statusText.innerHTML = `<span class="text-amber-700 font-bold">⚠ Partial: $${vals.paid.toFixed(2)} Paid | $${credit.toFixed(2)} Customer Credit</span>`;
                btnLabel.textContent = `Complete Sale ($${vals.paid.toFixed(2)} Paid | $${credit.toFixed(2)} Credit)`;
                btn.className = "w-full py-3 bg-indigo-600 hover:bg-indigo-700 active:scale-[0.99] text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-indigo-500/20 flex items-center justify-center gap-2 transition-all";
            } else {
                statusText.innerHTML = `<span class="text-rose-700 font-bold">Unpaid ($0.00) — $${vals.grandTotal.toFixed(2)} Full Store Credit</span>`;
                btnLabel.textContent = `Charge to Customer Account ($${vals.grandTotal.toFixed(2)} Credit)`;
                btn.className = "w-full py-3 bg-slate-800 hover:bg-slate-900 active:scale-[0.99] text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-slate-900/20 flex items-center justify-center gap-2 transition-all";
            }
        }

        function renderCart() {
            const listEl = document.getElementById('cartItemsList');
            const emptyEl = document.getElementById('cartEmptyState');
            const totalInput = document.getElementById('posInputTotal');
            const totalLabel = document.getElementById('cartGrandTotalLabel');
            const itemsBadge = document.getElementById('posItemsCountBadge');

            if (POS.cart.length === 0) {
                emptyEl.classList.remove('hidden');
                listEl.innerHTML = '';
                if (totalInput) totalInput.value = '0.00';
                if (totalLabel) totalLabel.textContent = '$0.00';
                if (itemsBadge) itemsBadge.textContent = '0 items in ticket';
                updatePaidStatusAndButton(getFinancialValues());
                return;
            }

            emptyEl.classList.add('hidden');
            listEl.innerHTML = '';

            let totalQty = 0;
            let subtotal = 0;

            POS.cart.forEach(item => {
                totalQty += item.qty;
                const lineTotal = item.price * item.qty;
                subtotal += lineTotal;

                const row = document.createElement('div');
                row.className = 'pos-cart-row p-2.5 rounded-xl bg-slate-50 border border-slate-200/80 hover:border-slate-300 transition-colors flex items-center justify-between gap-2';
                row.innerHTML = `
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-bold text-slate-800 line-clamp-1">${escapePosText(item.name)}</div>
                        <div class="text-[11px] text-slate-500 font-mono mt-0.5">$${item.price.toFixed(2)} / ${escapePosText(item.unit || POS.products[item.id]?.unit || "unit")}</div>
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        <button type="button" onclick="updateCartQty('${cartKey(item)}', -1)"
                                class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 flex items-center justify-center font-bold text-xs shadow-2xs">
                            –
                        </button>
                        <input type="number" aria-label="Quantity" value="${item.qty}" min="${item.stock_mode === 'sealed' ? 1 : (item.quantity_option?.quantity || 0.001)}" step="${item.stock_mode === 'sealed' ? 1 : (item.quantity_option?.quantity || 0.001)}" onchange="setCartQuantity('${cartKey(item)}',this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}" class="w-20 px-2 py-2 border rounded-lg text-center text-sm font-semibold">
                        <button type="button" onclick="updateCartQty('${cartKey(item)}', 1)"
                                class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 flex items-center justify-center font-bold text-xs shadow-2xs">
                            +
                        </button>
                    </div>

                    <div class="text-right shrink-0 min-w-[55px]">
                        <div class="text-xs font-bold font-mono text-slate-900">$${lineTotal.toFixed(2)}</div>
                        <button type="button" onclick="removeCartItem('${cartKey(item)}')"
                                class="text-[10px] text-slate-400 hover:text-rose-600 transition-colors mt-0.5">
                            Remove
                        </button>
                    </div>
                `;
                const product = POS.products[item.id];
                const options = document.createElement('div'); options.className='pos-cart-options';
                if (product?.package_unit) {
                    const select = document.createElement('select'); select.dataset.nativeSelect='true';select.setAttribute('aria-label','Selling unit');select.className='pos-cart-unit';
                    select.add(new Option(product.unit + ' (opened)','loose'));select.add(new Option(product.package_unit + ' (sealed)','sealed'));select.value=item.stock_mode || 'loose';
                    select.addEventListener('change',()=>changeCartUnit(cartKey(item),select.value));options.append(select);
                }
                if (item.stock_mode !== 'sealed' && (product?.quantity_shortcuts?.length || item.quantity_option)) {
                    const quantities=document.createElement('select'); quantities.dataset.nativeSelect='true';quantities.className='pos-quantity-select';quantities.setAttribute('aria-label','Quantity option');
                    quantities.add(new Option(`${product.unit} / custom quantity`,''));
                    (product.quantity_shortcuts || []).forEach((option,index)=>quantities.add(new Option(`${option.label} (${option.quantity} ${product.unit})`,String(index))));
                    // Preserve the selected increment as quantity grows; do not infer it from the total.
                    const selected=item.quantity_option ? (product.quantity_shortcuts || []).findIndex(option=>option.label===item.quantity_option.label && Number(option.quantity)===item.quantity_option.quantity) : -1;
                    if (item.quantity_option && selected<0) quantities.add(new Option(`${item.quantity_option.label} (${item.quantity_option.quantity} ${product.unit})`,'saved'));
                    quantities.value=selected>=0 ? String(selected) : (item.quantity_option ? 'saved' : '');
                    quantities.addEventListener('change',()=>{
                        if(quantities.value==='') { item.quantity_option=null; renderCart(); }
                        else if(quantities.value!=='saved') applyQuantityShortcut(cartKey(item),Number(quantities.value));
                    });
                    options.append(quantities);
                }
                if(options.children.length)row.append(options);
                listEl.appendChild(row);
            });

            if (totalInput) totalInput.value = subtotal.toFixed(2);
            if (itemsBadge) itemsBadge.textContent = `${totalQty} item${totalQty === 1 ? '' : 's'} in ticket`;

            handleFinancialInputChange();
        }

        // ==========================================
        // 3. Payment Method Selection
        // ==========================================
        function selectPaymentMethod(code, accountId, name, type) {
            POS.activePaymentMethod = { code, accountId, name, type };
            const select = document.getElementById('posPaymentMethod');
            if (select) select.value = code;
        }

        function handlePaymentMethodChange(select) {
            const option = select.selectedOptions[0];
            if (!option || !option.value) {
                POS.activePaymentMethod = { code: '', accountId: null, name: '', type: '' };
                return;
            }
            selectPaymentMethod(option.value, Number(option.dataset.accountId), option.dataset.name, option.dataset.type);
        }

        // ==========================================
        // 4. Customer Selection & Quick Add Modal
        // ==========================================
        function handleCustomerChange(select) {
            const val = select.value;
            POS.selectedCustomer = val ? parseInt(val, 10) : null;
            if (typeof ERPPosCredit !== 'undefined') ERPPosCredit.select(select);
        }

        function openAddCustomerModal() {
            document.getElementById('quickCustomerModal').classList.remove('hidden');
            document.querySelector('#quickCustomerForm input[name="name"]').focus();
        }

        function closeAddCustomerModal() {
            document.getElementById('quickCustomerModal').classList.add('hidden');
            document.getElementById('quickCustomerForm').reset();
        }

        async function handleQuickCustomerSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('saveCustBtn');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            const form = e.target;
            const payload = {
                name: form.name.value.trim(),
                phone: form.phone.value.trim() || null,
                email: form.email.value.trim() || null,
            };

            try {
                <?php if ($canManageCredit): ?>payload.credit_limit = document.getElementById('quick-cust-credit-limit').value;<?php endif; ?>
                const response = await fetch(<?= json_encode(site_url('clients/create')) ?>, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify(payload)});
                const res = await response.json();
                if (!response.ok || !res.success) throw new Error(res.message || 'Customer could not be saved. Please try again.');
                const created = res.data;

                // Add to select dropdown and select it
                const select = document.getElementById('posCustomerSelect');
                const opt = document.createElement('option');
                opt.value = created.id;
                opt.textContent = `${created.name} ($0.00)`;
                opt.setAttribute('data-balance', '0.00');
                opt.setAttribute('data-phone', created.phone || '');
                opt.dataset.creditLimit = created.credit_limit;
                opt.dataset.outstanding = created.outstanding;
                opt.dataset.availableCredit = created.available_credit;
                select.appendChild(opt);
                select.value = created.id;
                POS.selectedCustomer = created.id;
                handleCustomerChange(select);
                if (window.jQuery) window.jQuery(select).trigger('change.select2');

                closeAddCustomerModal();
                showToast(`Customer "${created.name}" selected!`);
            } catch (err) {
                showToast(err.message || 'Failed to save customer', true);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save & Select';
            }
        }

        // ==========================================
        // 5. Checkout Processing (Unified Single Action)
        // ==========================================
        async function processCheckout() {
            if (document.getElementById('payNowBtn').disabled) return;
            if (POS.cart.length === 0) {
                showToast('Please add items to the order first', true);
                return;
            }

            const vals = getFinancialValues();

            // If not fully paid (i.e. remaining balance is taken on credit), a registered customer is required
            if (vals.paid < vals.grandTotal) {
                if (!POS.selectedCustomer) {
                    showToast('Credit or partial payment requires selecting a registered customer', true);
                    const custSelect = document.getElementById('posCustomerSelect');
                    if (custSelect) custSelect.focus();
                    return;
                }
            }

            if (vals.paid > 0 && !POS.activePaymentMethod.accountId) {
                showToast('Select an active payment method before completing the sale', true);
                return;
            }

            // Build items
            const items = POS.cart.map(item => ({
                product_id: item.id,
                quantity: item.qty,
                stock_mode: item.stock_mode || 'loose',
                unit_price: item.price,
                discount: 0,
            }));

            // Build payments linked to Chart of Accounts
            let payments = [];
            if (vals.paid > 0) {
                const paymentAmount = Math.min(vals.paid, vals.grandTotal);
                payments.push({
                    method: POS.activePaymentMethod.type || 'mobile_money',
                    account_id: POS.activePaymentMethod.accountId || null,
                    reference: POS.activePaymentMethod.name || 'Payment',
                    amount: paymentAmount
                });
            } else {
                payments = [];
            }

            const salePayload = {
                uuid: POS.checkoutUuid,
                tax_id: POS.activeTax ? Number(POS.activeTax.id) : null,
                customer_id: POS.selectedCustomer,
                discount: vals.discount,
                shipping: vals.shipping,
                items: items,
                payments: payments
            };

            const payBtn = document.getElementById('payNowBtn');
            const originalText = payBtn.innerHTML;
            payBtn.disabled = true;
            payBtn.innerHTML = '<span class="inline-block animate-spin mr-1">↻</span> Processing...';

            try {
                const response = await fetch(<?= json_encode(site_url('sales/pos/checkout')) ?>, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json',Accept:'application/json'}, body:JSON.stringify(salePayload)});
                const res = await response.json();
                if (!response.ok || !res.success) {
                    if (res.code === 'PACKAGE_STOCK_SHORTAGE') { await refreshPosStock().catch(() => {}); await showStockShortage(res.message); return; }
                    if (res.creditStatus && typeof ERPPosCredit !== 'undefined') ERPPosCredit.accept(res.creditStatus);
                    if (response.status === 409 && Object.prototype.hasOwnProperty.call(res,'activeTax')) {
                        POS.activeTax = res.activeTax;
                        handleFinancialInputChange();
                    }
                    throw new Error(res.message || 'Checkout failed. Please try again.');
                }
                POS.checkoutUuid = crypto.randomUUID();
                const sale = res.data;
                if (typeof ERPPosCredit !== 'undefined') void ERPPosCredit.refresh();
                // Start printing as soon as checkout commits, including fully unpaid credit sales.
                // A QZ failure is separate from saving the order and cannot retry checkout.
                POS.lastSaleId = sale.id;
                const autoPrint = res.autoPrint ?? <?= ($settings['thermal_printer_enabled']??'0')==='1'?'true':'false' ?>;
                if (autoPrint) void printDirectThermal(sale.id);


                // Update orders badge
                const badge = document.getElementById('posOrdersBadge');
                if (badge) {
                    badge.textContent = parseInt(badge.textContent || '0', 10) + 1;
                }

                if (vals.paid >= vals.grandTotal) {
                    showToast('Sale completed successfully!');
                } else if (vals.paid > 0) {
                    const credit = vals.grandTotal - vals.paid;
                    showToast(`Sale recorded ($${vals.paid.toFixed(2)} paid, $${credit.toFixed(2)} credit)!`);
                } else {
                    showToast('Credit sale posted to customer account!');
                }

                // Render thermal receipt modal
                showReceiptModal(sale);

                // Clear current ticket
                clearCart();
                void refreshPosStock().then(() => {
                    const finished = [...new Set(items.filter(item => item.stock_mode === 'loose').map(item => item.product_id))].map(id => POS.products[id]).filter(product => product?.package_unit && product.stock_split.opened === 0);
                    if (finished.length) void Swal.fire({title:'Opened stock finished / Kii furnaa wuu dhammaaday', text:finished.map(product => `${product.name}: 0 ${product.unit}. Stock-ga ka fur ${product.package_unit} cusub.`).join(' '), icon:'warning', confirmButtonText:'OK'});
                }).catch(() => {});
            } catch (err) {
                showToast(err.message || 'Checkout failed', true);
            } finally {
                payBtn.disabled = false;
                updatePaidStatusAndButton(getFinancialValues());
                lucide.createIcons();
            }
        }

        // ==========================================
        // 6. Thermal Receipt Modal Rendering & ESC/POS Printing
        // ==========================================
        function showReceiptModal(sale) {
            POS.lastSaleId = sale.id;
            document.getElementById('posRcptInvoice').textContent = sale.invoice_no || ('#' + sale.id);
            document.getElementById('posRcptDate').textContent = new Date(sale.sold_at || Date.now()).toLocaleString();
            document.getElementById('posRcptCustomer').textContent = sale.customer_name || 'Walk-in Customer';
            
            document.getElementById('posRcptSubtotal').textContent = '$' + parseFloat(sale.subtotal || 0).toFixed(2);
            document.getElementById('posRcptDiscount').textContent = '$' + parseFloat(sale.discount || 0).toFixed(2);
            document.getElementById('posRcptTax').textContent = '$' + parseFloat(sale.tax_total || 0).toFixed(2);
            document.getElementById('posRcptShipping').textContent = '$' + parseFloat(sale.shipping || 0).toFixed(2);
            document.getElementById('posRcptGrandTotal').textContent = '$' + parseFloat(sale.grand_total || 0).toFixed(2);
            document.getElementById('posRcptReturnRow').classList.toggle('hidden', !Number(sale.returned_total));
            document.getElementById('posRcptReturned').textContent = '-$' + Number(sale.returned_total || 0).toFixed(2);
            document.getElementById('posRcptNet').textContent = '$' + Number(sale.net_total ?? sale.grand_total).toFixed(2);
            document.getElementById('posRcptRefunded').textContent = '$' + Number(sale.refunded_total || 0).toFixed(2);
            document.getElementById('posRcptPaid').textContent = '$' + parseFloat(sale.paid_total || 0).toFixed(2);

            const due = parseFloat(sale.net_total ?? sale.grand_total ?? 0) - parseFloat(sale.paid_total || 0);
            const dueRow = document.getElementById('posRcptDueRow');
            if (due > 0.005) {
                dueRow.classList.remove('hidden');
                document.getElementById('posRcptDue').textContent = '$' + due.toFixed(2);
            } else {
                dueRow.classList.add('hidden');
            }

            // Items
            const itemsBody = document.getElementById('posRcptItemsBody');
            itemsBody.innerHTML = '';
            (sale.items || []).forEach(item => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="py-1 text-slate-800">${item.description}</td>
                    <td class="py-1 text-center font-mono">${item.quantity}</td>
                    <td class="py-1 text-right font-mono">$${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td class="py-1 text-right font-mono font-medium">$${parseFloat(item.line_total).toFixed(2)}</td>
                `;
                itemsBody.appendChild(tr);
            });

            // Payments
            const payArea = document.getElementById('posRcptPayments');
            payArea.innerHTML = '';
            if (sale.payments && sale.payments.length > 0) {
                sale.payments.forEach(p => {
                    const r = document.createElement('div');
                    r.className = 'flex justify-between text-slate-600';
                    r.innerHTML = `
                        <span>${p.reference || p.method}:</span>
                        <span class="font-mono">$${parseFloat(p.amount).toFixed(2)}</span>
                    `;
                    payArea.appendChild(r);
                });
            } else if (due > 0) {
                payArea.innerHTML = '<div class="text-amber-600 italic">Pay Later (Account Credit)</div>';
            }

            document.getElementById('receiptModal').classList.remove('hidden');
            lucide.createIcons();
        }

        // Direct ESC/POS Thermal Printing (mike42/escpos-php)
        const pendingPrintSales = new Set();
        let printerQueue = Promise.resolve();
        function printDirectThermal(saleId = null) {
            const id = saleId || POS.lastSaleId;
            if (!id || pendingPrintSales.has(id)) return;
            pendingPrintSales.add(id);
            const send = async () => {

            const btn = document.getElementById('posDirectPrintBtn');
            const oldHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="inline-block animate-spin mr-1">↻</span> Printing…`;
            }

            try {
                const res = {data: await window.erpPrinting.sale(id)};
                if (res.data && res.data.success) {
                    showToast('✓ ' + res.data.message);
                } else {
                    showToast((res.data && res.data.error) ? res.data.error : 'Thermal printer not responding.', true);
                }
            } catch (err) {
                showToast(err.message || 'Thermal printer communication error', true);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                pendingPrintSales.delete(id);
            }
            };
            printerQueue = printerQueue.then(send, send);
            return printerQueue;
        }

        function downloadEscposBinary(saleId = null) {
            const id = saleId || POS.lastSaleId;
            if (!id) return;
            window.location.href = window.ERP_BASE_URL + '/api/v1/sales/' + id + '/escpos';
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.add('hidden');
        }

        async function reprintReceipt(saleId) {
            try {
                const res = await apiCall('/sales/' + saleId);
                showReceiptModal(res.data);
                closeOrdersDrawer();
            } catch (err) {
                showToast(err.message || 'Failed to load receipt', true);
            }
        }

        // ==========================================
        // 7. Orders Drawer
        // ==========================================
        function openOrdersDrawer() {
            document.getElementById('ordersDrawerModal').classList.remove('hidden');
        }

        function closeOrdersDrawer() {
            document.getElementById('ordersDrawerModal').classList.add('hidden');
        }

        // Initial setup
        document.addEventListener('DOMContentLoaded', () => {
            ERPPosCredit.init({baseUrl: <?= json_encode(rtrim(site_url('clients'), '/')) ?>, values: getFinancialValues});
            lucide.createIcons();
            handlePaymentMethodChange(document.getElementById('posPaymentMethod'));
            renderCart();
        });
    </script>
    <?= view('partials/searchable_selects') ?>
    <script src="<?= base_url('assets/js/pos-layout.js').'?v='.filemtime(FCPATH.'assets/js/pos-layout.js') ?>"></script>
    <script src="<?= base_url('assets/js/pos-scanner.js').'?v='.filemtime(FCPATH.'assets/js/pos-scanner.js') ?>" data-scan-url="<?= site_url('sales/pos/scan') ?>"></script>
    <script src="<?= base_url('assets/js/web-auth-session.js') ?>" data-authenticated="true" data-login-url="<?= esc(site_url('login'), 'attr') ?>"></script>
</body>
</html>
