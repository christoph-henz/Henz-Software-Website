<?php

declare(strict_types=1);

$footerConfig = require __DIR__ . '/../_config/footer.php';
$brand = htmlspecialchars((string) ($footerConfig['brand'] ?? 'Henz Software Solutions'), ENT_QUOTES, 'UTF-8');
$note = htmlspecialchars((string) ($footerConfig['note'] ?? 'Henz Software'), ENT_QUOTES, 'UTF-8');
$links = $footerConfig['links'] ?? [];
?>
<footer class="py-12 border-t" data-fg-d3bl245="0.8:1.32440:/src/app/App.tsx:766:7:31035:1386:e:footer:e"
    data-fgid-d3bl245=":r8t:" style="border-color: rgba(0, 200, 255, 0.08); background: rgb(6, 10, 15);">
    <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row items-center justify-between gap-6"
        data-fg-d3bl246="0.8:1.32440:/src/app/App.tsx:770:9:31173:1232:e:div:etete" data-fgid-d3bl246=":r8u:">
        <div class="flex items-center gap-2" data-fg-d3bl247="0.8:1.32440:/src/app/App.tsx:771:11:31285:384:e:div:ete"
            data-fgid-d3bl247=":r8v:"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-terminal w-4 h-4"
                data-fg-d3bl248="0.8:1.32440:node_modules/lucide-react:772:13:31339:61:e:Terminal::::::DcQ8"
                data-fgid-d3bl248=":r90:" style="color: rgb(0, 200, 255);">
                <polyline points="4 17 10 11 4 5"></polyline>
                <line x1="12" x2="20" y1="19" y2="19"></line>
            </svg><span class="text-base font-bold"
                data-fg-d3bl249="0.8:1.32440:/src/app/App.tsx:773:13:31413:239:e:span:te" data-fgid-d3bl249=":r91:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(232, 237, 245);"><?= $note; ?><span
                    data-fg-d3bl251="0.8:1.32440:/src/app/App.tsx:777:23:31587:45:e:span:t" data-fgid-d3bl251=":r92:"
                    style="color: rgb(0, 200, 255);">.com</span></span>
        </div>
        <div class="flex gap-8" data-fg-d3bl253="0.8:1.32440:/src/app/App.tsx:780:11:31680:519:e:div:x"
            data-fgid-d3bl253=":r93:">
            <?php foreach ($links as $link): ?>
                <?php
                $label = htmlspecialchars((string) ($link['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $href = htmlspecialchars((string) ($link['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
                ?>
                <a href="<?= $href; ?>" class="text-xs transition-colors duration-200"
                    data-fg-d3bl255="0.8:1.32440:/src/app/App.tsx:782:15:31762:404:e:a:x" data-fgid-d3bl255=":r94:"
                    style="color: rgb(90, 116, 148);"><?= $label; ?></a>
            <?php endforeach; ?>
        </div>
        <div class="text-xs" data-fg-d3bl257="0.8:1.32440:/src/app/App.tsx:794:11:32210:180:e:div:t"
            data-fgid-d3bl257=":r99:"
            style="color: rgb(90, 116, 148); font-family: &quot;JetBrains Mono&quot;, monospace;">© <?= date("Y"); ?>
            <?= $brand; ?>
        </div>
    </div>
</footer>