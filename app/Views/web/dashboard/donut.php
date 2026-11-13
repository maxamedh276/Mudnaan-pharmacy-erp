<?php
$negative = abs(array_sum(array_filter($segments, static fn($v)=>(float)$v<0)));
$segments = array_filter($segments, static fn($v)=>(float)$v>0);
arsort($segments);
if (count($segments)>4) $segments=array_slice($segments,0,3,true)+['Other'=>array_sum(array_slice($segments,3,null,true))];
$total=array_sum($segments); $offset=0; $colors=['var(--system-brand, #1967d2)','#0d9488','#f59e0b','#e11d48'];
?>
<article class="dashboard-enter bg-white rounded-xl border border-slate-200 p-3 shadow-sm min-w-0">
    <h2 class="text-sm font-bold text-slate-900"><?= esc($heading) ?></h2><p class="text-[10px] text-slate-500 mt-0.5"><?= esc($subtitle) ?></p>
    <div class="flex items-center gap-3 mt-3 min-h-40">
        <svg viewBox="0 0 120 120" role="img" aria-label="<?= esc($heading, 'attr') ?>" class="w-28 xl:w-32 shrink-0"><circle cx="60" cy="60" r="43" fill="none" stroke="#f1f5f9" stroke-width="18"/>
            <?php $index=0; foreach ($segments as $label=>$amount): $share=100*$amount/$total; ?><circle class="dashboard-ring" cx="60" cy="60" r="43" pathLength="100" fill="none" stroke="<?= $colors[$index++%4] ?>" stroke-width="18" stroke-dasharray="<?= $share ?> <?= 100-$share ?>" stroke-dashoffset="<?= -$offset ?>" transform="rotate(-90 60 60)"><title><?= esc($label.': '.number_format($amount,2)) ?></title></circle><?php $offset+=$share; endforeach; ?>
            <text x="60" y="57" text-anchor="middle" font-size="<?= $total>=100000?'10':'12' ?>" font-weight="700" fill="#0f172a"><?= $currency?'$'.number_format($total,0):number_format($total,0) ?></text><text x="60" y="72" text-anchor="middle" font-size="9" fill="#64748b"><?= $currency?'USD':'Orders' ?></text>
        </svg>
        <div class="flex-1 min-w-0 space-y-2"><?php $index=0; foreach ($segments as $label=>$amount): ?><div class="text-[10px]"><div class="flex items-start gap-1.5"><span class="shrink-0 w-2 h-2 rounded-sm mt-0.5" style="background:<?= $colors[$index++%4] ?>"></span><span class="break-words text-slate-600"><?= esc($label) ?></span></div><p class="font-semibold text-slate-900 ml-3.5 tabular-nums"><?= $currency?'$'.number_format($amount,2):number_format($amount) ?><span class="text-slate-400 font-normal"> · <?= number_format(100*$amount/$total,1) ?>%</span></p></div><?php endforeach; ?><?php if (!$total): ?><p class="text-xs text-slate-400">No activity in this period.</p><?php endif; ?></div>
    </div>
    <?php if ($negative>0): ?><p class="text-[10px] text-slate-500 mt-2">Credits / reversals of $<?= number_format($negative,2) ?> reduce net expenses and are excluded from the chart.</p><?php endif; ?>
</article>
