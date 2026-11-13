<article class="dashboard-enter bg-white border border-slate-200 rounded-xl px-3 py-3 shadow-sm min-w-0" style="--dashboard-delay:<?= (int)$index * 35 ?>ms">
    <div class="flex items-start justify-between gap-2"><p class="text-[11px] font-semibold text-slate-500"><?= esc($card['label']) ?></p><i data-lucide="<?= esc($card['icon'], 'attr') ?>" class="w-4 h-4 shrink-0 text-brand-600" aria-hidden="true"></i></div>
    <p class="dashboard-kpi-value text-xl xl:text-2xl font-bold tracking-tight text-slate-900 mt-2 tabular-nums"><?= !empty($card['count']) ? number_format($card['value']) : '$' . number_format($card['value'], 2) ?></p>
    <p class="text-[10px] text-slate-500 mt-1 leading-relaxed"><?= esc($card['detail']) ?></p>
</article>
