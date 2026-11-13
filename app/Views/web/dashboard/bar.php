<?php
$maximum = 1;
foreach ($points as $point) foreach ($series as $key=>$label) $maximum = max($maximum, abs((float)($point[$key] ?? 0)));
$step = 660 / max(1, count($points));
$colors = ['var(--system-brand, #1967d2)', '#0d9488'];
$description = [];
foreach ($points as $point) { $values=[]; foreach ($series as $key=>$label) $values[]=$label.' '.number_format((float)($point[$key]??0),2); $description[]=$point['label'].': '.implode(', ',$values); }
?>
<article class="dashboard-enter bg-white rounded-xl border border-slate-200 p-3 shadow-sm min-w-0">
    <div class="flex flex-wrap items-start justify-between gap-2 mb-2"><div><h2 class="text-sm font-bold text-slate-900"><?= esc($heading) ?></h2><p class="text-[10px] text-slate-500 mt-0.5"><?= esc($subtitle) ?></p></div><div class="flex flex-wrap gap-3 text-[10px] text-slate-500"><?php $i=0; foreach ($series as $key=>$label): ?><span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-sm" style="background:<?= $colors[$i++%2] ?>"></span><?= esc($label) ?></span><?php endforeach; ?></div></div>
    <svg viewBox="0 0 720 205" class="dashboard-bar-chart w-full h-48 sm:h-52" role="img" aria-label="<?= esc($heading . ' in USD', 'attr') ?>"><desc><?= esc(implode('; ', $description)) ?></desc>
        <?php for ($tick=0; $tick<=3; $tick++): $y=168-$tick*47; ?><line x1="48" y1="<?= $y ?>" x2="712" y2="<?= $y ?>" stroke="#e2e8f0" stroke-dasharray="3 4"/><text x="40" y="<?= $y+4 ?>" text-anchor="end" fill="#94a3b8" font-size="10"><?= number_format($maximum*$tick/3,0) ?></text><?php endfor; ?>
        <?php foreach ($points as $index=>$point): $i=0; foreach ($series as $key=>$label): $height=141*max(0,(float)($point[$key]??0))/$maximum; $width=max(1,($step-6)/count($series)); $x=50+$index*$step+$i*$width; ?>
            <rect class="dashboard-bar" x="<?= $x ?>" y="<?= 168-$height ?>" width="<?= max(1,$width-1) ?>" height="<?= $height ?>" rx="2" fill="<?= $colors[$i++%2] ?>"><title><?= esc($point['label'].' · '.$label.' $'.number_format((float)($point[$key]??0),2)) ?></title></rect>
        <?php endforeach; if ($index % max(1,(int)ceil(count($points)/8))===0): ?><text x="<?= 50+$index*$step+$step/2 ?>" y="190" text-anchor="middle" fill="#64748b" font-size="10"><?= esc($point['label']) ?></text><?php endif; endforeach; ?>
    </svg>
    <?php if (!empty($footer)): ?><p class="text-[10px] text-center border-t border-slate-100 pt-2 text-slate-500"><?= esc($footer) ?></p><?php endif; ?>
</article>
