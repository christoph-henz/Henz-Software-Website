<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/experience.php');
$items = $cfg['items'] ?? [];

?>
<div class="relative z-10 border-t border-b" data-fg-d3bl120="0.8:1.32440:/src/app/App.tsx:404:9:15769:770:e:div:e"
    data-fgid-d3bl120=":r2n:" style="border-color: rgba(0, 200, 255, 0.08);">
    <div class="max-w-7xl mx-auto px-6 py-8 grid grid-cols-2 md:grid-cols-4 gap-8"
        data-fg-d3bl121="0.8:1.32440:/src/app/App.tsx:408:11:15906:618:e:div:x" data-fgid-d3bl121=":r2o:">
        <?php foreach ($items as $item): ?>
            <?php
            $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="text-center" data-fg-d3bl122="0.8:1.32440:/src/app/App.tsx:409:13:15964:440:e:div:ete"
                data-fgid-d3bl122=":r2p:">
                <div class="text-3xl font-bold mb-1" data-fg-d3bl124="0.8:1.32440:/src/app/App.tsx:411:17:16109:215:e:div:x"
                    data-fgid-d3bl124=":r2q:"
                    style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(0, 200, 255);">
                    <?= $title; ?>
                </div>
                <div class="text-xs uppercase tracking-widest"
                    data-fg-d3bl126="0.8:1.32440:/src/app/App.tsx:417:17:16341:129:e:div:x" data-fgid-d3bl126=":r2r:"
                    style="color: rgb(90, 116, 148);">
                    <?= $description; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</section>