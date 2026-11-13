<!DOCTYPE html>
<html lang="en" class="h-full bg-[#eef2f6]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Sign In — '.\App\Services\ReceiptBrandingService::SYSTEM_NAME) ?></title>
    
    <!-- Compiled CSS and pinned libraries are served locally. -->
    <link rel="stylesheet" href="<?= base_url('assets/css/application.css').'?v='.filemtime(FCPATH.'assets/css/application.css') ?>">
    <style>:root { <?php foreach (\App\Services\AppearanceSettingsService::palette('#1967d2') as $shade => $color): ?>--brand-<?= (int)$shade ?>:<?= esc($color) ?>;<?php endforeach; ?> }</style>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* Concentric circle ripples effect on left column */
        .ripple-circle {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid rgba(255, 255, 255, 0.18);
            pointer-events: none;
        }
    </style>
    <?= $this->include('partials/typography') ?>
</head>
<body class="erp-auth min-h-full flex flex-col items-center justify-center p-4 sm:p-6 md:p-10">

    <!-- Login Modal Card -->
    <div class="w-full max-w-4xl bg-white rounded-3xl shadow-[0_20px_60px_-15px_rgba(0,0,0,0.12)] overflow-hidden grid grid-cols-1 md:grid-cols-2 min-h-[540px]">
        
        <!-- LEFT COLUMN: Vibrant Blue Brand Banner -->
        <div class="relative bg-[#1967d2] p-8 sm:p-10 md:p-12 flex flex-col justify-between text-white overflow-hidden">
            
            <!-- Concentric Circle Watermarks (Matching Image 1) -->
            <div class="ripple-circle w-[320px] h-[320px] -bottom-24 -right-24"></div>
            <div class="ripple-circle w-[460px] h-[460px] -bottom-40 -right-40"></div>
            <div class="ripple-circle w-[600px] h-[600px] -bottom-56 -right-56"></div>
            <div class="ripple-circle w-[740px] h-[740px] -bottom-72 -right-72"></div>

            <!-- Top Header: Logo + Business Name -->
            <div class="relative z-10 flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-slate-900/90 flex items-center justify-center p-1 shadow-inner">
                    <img src="<?= base_url('assets/images/mudnaan-logo.jpeg') ?>" 
                         onerror="this.onerror=null; this.src='<?= base_url('public/assets/images/mudnaan-logo.jpeg') ?>';" 
                         alt="Mudnaan" class="w-full h-full object-cover rounded">
                </div>
                <span class="text-base font-bold tracking-tight text-white"><?= esc($businessName ?? \App\Services\ReceiptBrandingService::SYSTEM_NAME) ?></span>
            </div>

            <!-- Middle Section: Main Copy (Exact from Image 1) -->
            <div class="relative z-10 my-10 md:my-0">
                <p class="text-[11px] font-semibold tracking-widest uppercase text-blue-200/90 mb-3">
                    Welcome to Maareeye
                </p>
                <h2 class="text-3xl sm:text-4xl font-bold leading-tight text-white mb-4">
                    Everything your Shop needs, in one place.
                </h2>
                <p class="text-sm text-blue-100/85 leading-relaxed font-normal max-w-sm">
                    Stay close to the details that matter—from the first order to the final report.
                </p>
            </div>

            <!-- Bottom Footer: Technology Logo Pill (Exact from Image 1) -->
            <div class="relative z-10 flex items-center gap-2.5">
                <div class="bg-white rounded px-2 py-0.5 shadow-2xs flex items-center justify-center">
                    <span class="text-[10px] font-bold text-slate-900 tracking-tight">mudnaan</span>
                </div>
                <span class="text-xs font-medium text-white/90">mudnaan Technology</span>
            </div>
        </div>

        <!-- RIGHT COLUMN: White Form Area -->
        <div class="p-8 sm:p-10 md:p-12 flex flex-col justify-center bg-white">
            
            <!-- Workspace Subtitle & Welcome Heading -->
            <div class="mb-8">
                <p class="text-[11px] font-bold tracking-wider uppercase text-[#1967d2] mb-1">
                    Your Workspace
                </p>
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                    Welcome back.
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    Sign in to pick up where your team left off.
                </p>
            </div>

            <?php
            $loginNotices = [];
            if (!empty($error)) {
                $loginNotices[] = ['message' => (string) $error, 'type' => 'error'];
            }
            if (!empty($success)) {
                $loginNotices[] = ['message' => (string) $success, 'type' => 'success'];
            }
            ?>
            <noscript>
                <?php foreach ($loginNotices as $notice): ?>
                    <p role="<?= $notice['type'] === 'error' ? 'alert' : 'status' ?>"
                       class="mb-5 text-sm <?= $notice['type'] === 'error' ? 'text-rose-700' : 'text-emerald-700' ?>"><?= esc($notice['message']) ?></p>
                <?php endforeach; ?>
            </noscript>
            <!-- Form -->
            <form id="login-form" action="<?= site_url('login') ?>" method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="tenant" value="<?= esc(config(\Config\Tenants::class)->default, 'attr') ?>">

                <!-- Email or phone field -->
                <div>
                    <label for="identity" class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Email or phone
                    </label>
                    <input type="text" id="identity" name="identity" required
                           value="<?= esc($identity ?? '') ?>"
                           placeholder="Enter your email or phone"
                           class="w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:border-[#1967d2] focus:ring-1 focus:ring-[#1967d2] transition-colors">
                </div>

                <!-- Password field with Show toggle -->
                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required
                               placeholder="••••••••"
                               class="w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 pr-16 focus:outline-none focus:border-[#1967d2] focus:ring-1 focus:ring-[#1967d2] transition-colors">
                        <button type="button" onclick="togglePasswordVisibility()" 
                                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-medium text-slate-500 hover:text-slate-800 focus:outline-none select-none">
                            <span id="show-text">Show</span>
                        </button>
                    </div>
                </div>

                <!-- Sign in Button -->
                <div class="pt-2">
                    <button type="submit" id="submit-btn"
                            class="w-full py-2.5 px-4 bg-[#1967d2] hover:bg-[#1558b0] active:scale-[0.99] text-white text-sm font-semibold rounded-lg shadow-sm transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1967d2]">
                        Sign in
                    </button>
                </div>

                <!-- Forgot Password -->
                <div class="text-center pt-2">
                    <button type="button" data-toast-type="info" data-toast-message="Please contact your store administrator to reset your password."
                       class="text-xs text-slate-500 hover:text-slate-800 transition-colors font-medium">
                        Forgot Password
                    </button>
                </div>
            </form>
        </div>

    </div>

    <?= view('partials/copyright') ?>
    <?= view('partials/feedback', ['feedbackMessages'=>$loginNotices]) ?>
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/erp-feedback.js').'?v='.filemtime(FCPATH.'assets/js/erp-feedback.js') ?>"></script>
    <!-- Password visibility and sign-in form state -->
    <script>
        function togglePasswordVisibility() {
            const pwd = document.getElementById('password');
            const showText = document.getElementById('show-text');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                showText.textContent = 'Hide';
            } else {
                pwd.type = 'password';
                showText.textContent = 'Show';
            }
        }

        // Show progress while the standard sign-in request is submitted.
        document.getElementById('login-form').addEventListener('submit', async function(e) {
            const submitBtn = document.getElementById('submit-btn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Signing in...';
        });
    </script>
<script src="<?= base_url('assets/js/web-auth-session.js') ?>" data-authenticated="false" data-login-url="<?= esc(site_url('login'), 'attr') ?>"></script>
</body>
</html>
