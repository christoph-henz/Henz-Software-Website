<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/technology.php');
$slug = (string) ($cfg['slug'] ?? 'Technologie-Stack');
$items = $cfg['items'] ?? [];

?>
<section class="py-20" data-fg-d3bl187="0.8:1.32440:/src/app/App.tsx:595:7:23868:1686:e:section:e"
    data-fgid-d3bl187=":r6f:" style="background: rgb(12, 21, 32); border-top: 1px solid rgba(0, 200, 255, 0.08);">
    <div class="max-w-7xl mx-auto px-6" data-fg-d3bl188="0.8:1.32440:/src/app/App.tsx:599:9:24006:1531:e:div:ete"
        data-fgid-d3bl188=":r6g:">
        <div class="text-xs uppercase tracking-widest mb-8 text-center"
            data-fg-d3bl189="0.8:1.32440:/src/app/App.tsx:600:11:24057:224:e:div:t" data-fgid-d3bl189=":r6h:"
            style="color: rgb(90, 116, 148); font-family: &quot;JetBrains Mono&quot;, monospace;">//
            <?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="flex flex-wrap justify-center gap-3"
            data-fg-d3bl191="0.8:1.32440:/src/app/App.tsx:606:11:24292:1230:e:div:x" data-fgid-d3bl191=":r6i:">
            <?php foreach ($items as $item): ?>
                <span class="px-4 py-2 rounded text-sm border transition-all duration-200 cursor-default"
                    data-fg-d3bl193="0.8:1.32440:/src/app/App.tsx:608:15:24395:1094:e:span:x" data-fgid-d3bl193=":r6j:"
                    style="color: rgb(90, 116, 148); border-color: rgba(90, 116, 148, 0.2); background: rgba(90, 116, 148, 0.05); font-family: &quot;JetBrains Mono&quot;, monospace;"><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>