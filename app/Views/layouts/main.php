<?php $appearance=(new \App\Models\SettingModel())->allAssoc(); $brand=preg_match('/^#[a-f0-9]{6}$/i',$appearance['brand_color']??'')?$appearance['brand_color']:'#1967d2'; $accent=preg_match('/^#[a-f0-9]{6}$/i',$appearance['accent_color']??'')?$appearance['accent_color']:'#059669'; $background=preg_match('/^#[a-f0-9]{6}$/i',$appearance['background_color']??'')?$appearance['background_color']:'#f8fafc'; $brandPalette=\App\Services\AppearanceSettingsService::palette($brand); ?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="web-csrf-token" content="<?= esc(session()->get('web_csrf')??'', 'attr') ?>">
    <script src="<?= base_url('assets/js/web-csrf.js').'?v='.filemtime(FCPATH.'assets/js/web-csrf.js') ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <?php $businessIdentity=(new \App\Services\ReceiptBrandingService())->get(); ?>
    <title><?= esc(explode(' — ', $title ?? 'Dashboard')[0].' — '.$businessIdentity['store_name']) ?></title>
    
    <!-- Compiled CSS and pinned libraries are served locally. -->
    <link rel="stylesheet" href="<?= base_url('assets/css/application.css').'?v='.filemtime(FCPATH.'assets/css/application.css') ?>">
    <script src="<?= base_url('assets/js/vendor/lucide-0.468.0.js') ?>"></script>
    <script src="<?= base_url('assets/js/vendor/jquery-3.7.1.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/vendor/jquery.dataTables-1.13.7.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/vendor/dataTables.responsive-2.5.0.min.js') ?>"></script>
    <style>:root { <?php foreach ($brandPalette as $shade => $color): ?>--brand-<?= (int)$shade ?>:<?= esc($color) ?>;<?php endforeach; ?> }</style>
    <style>
        html, body, main { background-color: <?= $background ?> !important; }
        :root { --system-accent: <?= $accent ?>; --system-brand: <?= $brand ?>; }
        .text-emerald-600, .text-emerald-700 { color: var(--system-accent); }
        body {
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        /* Custom scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Tailored DataTables Styling for Tailwind */
        .dataTables_wrapper {
            font-size: 0.75rem;
            color: #334155;
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 0.75rem;
        }
        .dataTables_wrapper .dataTables_filter input {
            padding: 0.4rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            outline: none;
            font-size: 0.75rem;
            margin-left: 0.5rem;
            transition: all 0.15s ease;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #1967d2;
            box-shadow: 0 0 0 2px rgba(25, 103, 210, 0.15);
        }
        .dataTables_wrapper .dataTables_length select {
            padding: 0.35rem 1.5rem 0.35rem 0.6rem;
            border-radius: 0.5rem;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            outline: none;
            font-size: 0.75rem;
            margin: 0 0.4rem;
        }
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 0.85rem;
            padding-top: 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.25rem 0.6rem;
            border-radius: 0.375rem;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569 !important;
            cursor: pointer;
            margin: 0 0.15rem;
            display: inline-block;
            transition: all 0.15s ease;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #1967d2 !important;
            border-color: #1967d2 !important;
            color: #ffffff !important;
            font-weight: 600;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        table.dataTable.no-footer {
            border-bottom: 1px solid #e2e8f0;
        }
    </style>
    <link rel="stylesheet" href="<?= base_url('assets/css/responsive-layout.css').'?v='.filemtime(FCPATH.'assets/css/responsive-layout.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/workspace-navigation.css').'?v='.filemtime(FCPATH.'assets/css/workspace-navigation.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/entry-forms.css').'?v='.filemtime(FCPATH.'assets/css/entry-forms.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/pharmacy-receipt.css').'?v='.filemtime(FCPATH.'assets/css/pharmacy-receipt.css') ?>">
    <?= $this->include('partials/typography') ?>
</head>
<body class="erp-shell <?= service('request')->getGet('workspace_panel') === '1' ? 'workspace-embedded' : '' ?> h-full flex overflow-hidden bg-[#f8fafc] text-slate-800">

    <!-- Mobile Sidebar Backdrop -->
    <div id="mobile-backdrop" class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- Left Sidebar (Permanent on Desktop, Drawer on Mobile) -->
    <aside id="app-sidebar" aria-label="Primary navigation" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-slate-200/80 flex flex-col transition-transform duration-200 ease-in-out -translate-x-full lg:translate-x-0 lg:static lg:z-auto">
        <?= $this->include('partials/sidebar') ?>
    </aside>

    <!-- Main Content Container -->
    <div id="app-content" class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Mobile Topbar Header -->
        <header class="erp-mobile-header lg:hidden bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between gap-3">
            <button id="mobile-menu-toggle" type="button" aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="false" onclick="toggleSidebar()" class="p-1.5 shrink-0 text-slate-600 hover:text-slate-900 rounded-lg hover:bg-slate-100 focus:outline-none">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <div class="flex items-center gap-2 min-w-0">
                <img data-workspace-logo src="<?= esc(\App\Services\AppearanceSettingsService::logoUrl($appearance),'attr') ?>"
                     onerror="this.onerror=null; this.src='<?= base_url('public/assets/images/mudnaan-logo.jpeg') ?>';" 
                     alt="Logo" class="w-7 h-7 rounded bg-white object-contain shadow-sm">
                <span class="font-semibold text-sm text-slate-900 truncate max-w-[12rem]"><?= esc($businessIdentity['store_name']) ?></span>
            </div>
            <a href="<?= site_url('logout') ?>" class="p-1.5 text-slate-500 hover:text-red-600 rounded-lg">
                <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
        </header>

        <!-- Flash Messages -->
        <?php if (session()->getFlashdata('success')): ?>
            <div id="flash-banner" class="bg-emerald-50 border-b border-emerald-200 px-6 py-2.5 flex items-center justify-between text-xs text-emerald-800">
                <div class="flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i>
                    <span><?= esc(session()->getFlashdata('success')) ?></span>
                </div>
                <button onclick="document.getElementById('flash-banner').remove()" class="text-emerald-600 hover:text-emerald-900">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if (session()->getFlashdata('error')): ?>
            <div id="flash-banner-err" class="bg-rose-50 border-b border-rose-200 px-6 py-2.5 flex items-center justify-between text-xs text-rose-800">
                <div class="flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i>
                    <span><?= esc(session()->getFlashdata('error')) ?></span>
                </div>
                <button onclick="document.getElementById('flash-banner-err').remove()" class="text-rose-600 hover:text-rose-900">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Scrollable Page Content -->
        <main id="app-main" class="flex-1 overflow-y-auto flex flex-col <?= !empty($compactDashboard) ? 'p-3 sm:p-4' : 'p-4 md:p-8' ?>">
            <div class="erp-page w-full flex-1 <?= !empty($compactDashboard) ? '' : 'max-w-7xl mx-auto' ?>">
                <?= $this->include('partials/module_navigation') ?>
                <?= $this->renderSection('content') ?>
            </div>
            <?= view('partials/copyright') ?>
        </main>
    </div>

    <!-- Quick Search Command Palette Modal (Ctrl + K) -->
    <div id="search-modal" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden flex items-start justify-center pt-24 px-4" onclick="closeSearch(event)">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-xl overflow-hidden" onclick="event.stopPropagation()">
            <div class="flex items-center px-4 border-b border-slate-100">
                <i data-lucide="search" class="w-5 h-5 text-slate-400"></i>
                <input id="quick-search-input" type="text" placeholder="Search menu, pages or orders..." 
                       class="w-full py-3.5 px-3 text-sm text-slate-800 placeholder-slate-400 focus:outline-none">
                <span class="text-[10px] bg-slate-100 text-slate-500 rounded px-1.5 py-0.5 font-medium border border-slate-200">ESC</span>
            </div>
            <div class="p-2 max-h-80 overflow-y-auto divide-y divide-slate-50 text-xs text-slate-600">
                <div class="px-3 py-1.5 text-[10px] uppercase font-bold text-slate-400 tracking-wider">Pages & Modules</div>
                <?php foreach ((new \App\Services\WorkspaceNavigationService())->modules() as $searchId=>$searchModule): ?>
                <a href="<?= site_url('workspace/'.$searchId) ?>" data-module-link class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-50 hover:text-brand-600">
                    <i data-lucide="<?= esc($searchModule['icon'],'attr') ?>" class="w-4 h-4"></i> <?= esc($searchModule['label']) ?>
                </a>
                <?php foreach ($searchModule['groups'] as $searchGroup): foreach ($searchGroup['pages'] as $searchPage): ?>
                <a href="<?= site_url($searchPage['route']) ?>" class="flex items-center gap-2.5 px-3 py-2 pl-9 rounded-lg hover:bg-slate-50 hover:text-brand-600"><?= esc($searchPage['label']) ?></a>
                <?php endforeach; endforeach; endforeach; ?>
            </div>
        </div>
    </div>

    <!-- UI Scripts -->
    <script>
        window.lucide?.createIcons();

        function toggleSidebar() {
            if (window.erpLayout) return window.erpLayout.toggleSidebar();
            const sidebar = document.getElementById('app-sidebar');
            const backdrop = document.getElementById('mobile-backdrop');
            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            }
        }

        // Quick search modal with Ctrl+K
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openSearch();
            }
            if (e.key === 'Escape') {
                closeSearch();
            }
        });

        function openSearch() {
            window.erpLayout?.closeSidebar();
            const modal = document.getElementById('search-modal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                document.getElementById('quick-search-input').focus();
            }, 50);
        }

        function closeSearch() {
            document.getElementById('search-modal').classList.add('hidden');
        }

        // Store and synchronize session token
        window.ERP_TOKEN = "<?= esc(session()->get('token') ?? '') ?>";
        if (window.ERP_TOKEN) {
            localStorage.setItem('mudnaan_erp_token', window.ERP_TOKEN);
        } else {
            const match = document.cookie.match(new RegExp('(^| )erp_token=([^;]+)'));
            if (match && match[2]) {
                localStorage.setItem('mudnaan_erp_token', match[2]);
            }
        }

        // Global SPA & API Helper
        window.erp = {
            async api(endpoint, options = {}) {
                const token = window.ERP_TOKEN || localStorage.getItem('mudnaan_erp_token') || '';
                const headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...(options.headers || {})
                };
                if (token) {
                    headers['Authorization'] = 'Bearer ' + token;
                }
                options.headers = headers;
                
                const base = '<?= site_url('api/v1/') ?>';
                const url = endpoint.startsWith('http') ? endpoint : (base + endpoint.replace(/^\//, ''));
                
                try {
                    const res = await fetch(url, options);
                    const json = await res.json().catch(() => ({ success: false, error: { message: 'Unexpected server response (' + res.status + ')' } }));
                    
                    if (!res.ok || json.success === false) {
                        const errMsg = json.error?.message || json.message || json.messages?.error || (json.error ? Object.values(json.error).join(', ') : 'Request failed');
                        erp.toast(errMsg, 'error');
                        const errorObj = new Error(errMsg);
                        errorObj.reported = true;
                        throw errorObj;
                    }
                    return json.data;
                } catch (err) {
                    if (!err.reported && !err.message.includes('Request failed') && !err.message.includes('Unexpected')) {
                        erp.toast(err.message, 'error');
                    }
                    throw err;
                }
            },

            initDataTable(tableSelector, customOptions = {}) {
                if (!$.fn.DataTable) return null;
                // Suppress raw browser alert popups from DataTables
                $.fn.dataTable.ext.errMode = 'none';
                const defaultOptions = {
                    responsive: true,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                    language: {
                        search: "",
                        searchPlaceholder: "Fast search...",
                        lengthMenu: "Show _MENU_",
                        info: "Showing _START_ to _END_ of _TOTAL_",
                        emptyTable: "No records found in this view.",
                        zeroRecords: "No matching records found.",
                        paginate: {
                            previous: "Prev",
                            next: "Next"
                        }
                    }
                };
                return $(tableSelector).DataTable($.extend(true, defaultOptions, customOptions));
            }
        };
    </script>

    <?= view('partials/feedback', ['feedbackMessages'=>[]]) ?>
    <?= view('partials/pharmacy_receipt') ?>
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/erp-feedback.js').'?v='.filemtime(FCPATH.'assets/js/erp-feedback.js') ?>"></script>
    <?= view('partials/qz_printing') ?>
    <script src="<?= base_url('assets/js/web-auth-session.js') ?>" data-authenticated="true" data-login-url="<?= esc(site_url('login'), 'attr') ?>"></script>
    <?= view('partials/searchable_selects') ?>
    <script src="<?= base_url('assets/js/responsive-layout.js').'?v='.filemtime(FCPATH.'assets/js/responsive-layout.js') ?>"></script>
    <script src="<?= base_url('assets/js/workspace-navigation.js').'?v='.filemtime(FCPATH.'assets/js/workspace-navigation.js') ?>"></script>
    <script src="<?= base_url('assets/js/entry-forms.js').'?v='.filemtime(FCPATH.'assets/js/entry-forms.js') ?>"></script>
    <script src="<?= base_url('assets/js/pharmacy-receipt.js').'?v='.filemtime(FCPATH.'assets/js/pharmacy-receipt.js') ?>"></script>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
