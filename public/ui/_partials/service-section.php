<?php

declare(strict_types=1);
$service = require __DIR__ . '/../_config/service.php';
$slug = (string) ($service['slug'] ?? 'services');
$header1 = (string) ($service['header1'] ?? 'Was wir');
$header2 = (string) ($service['header2'] ?? 'machen');
$rows = is_array($service['rows'] ?? null) ? $service['rows'] : [];
$cta_text = (string) ($service['cta_text'] ?? 'Alle Angebote ansehen');
$resolveIconPath = static function (array $row): string {
    $iconFile = trim((string) ($row['icon_path'] ?? ''));
    $iconFile = match ($iconFile) {
        'html_tag.svg' => 'html_tags.svg',
        default => $iconFile,
    };

    if ($iconFile === '') {
        return '/ui/_assets/images/profile-placeholder.svg';
    }

    $iconFile = ltrim(str_replace('\\', '/', $iconFile), '/');
    $absolutePath = base_path('public/ui/_assets/images/' . $iconFile);

    if (!is_file($absolutePath)) {
        return '/ui/_assets/images/profile-placeholder.svg';
    }

    return '/ui/_assets/images/' . rawurlencode($iconFile);
};
if (empty($rows)) {
    return;
}
?>
<section class="relative py-28" data-fg-d3bl129="0.8:1.32440:/src/app/App.tsx:427:7:16587:2967:e:section:e"
    data-fgid-d3bl129=":r35:">
    <div class="max-w-7xl mx-auto px-6" data-fg-d3bl130="0.8:1.32440:/src/app/App.tsx:428:9:16632:2905:e:div:ete"
        data-fgid-d3bl130=":r36:">
        <div class="mb-16" data-fg-d3bl131="0.8:1.32440:/src/app/App.tsx:429:11:16683:576:e:div:ete"
            data-fgid-d3bl131=":r37:">
            <div class="text-xs uppercase tracking-widest mb-4"
                data-fg-d3bl132="0.8:1.32440:/src/app/App.tsx:430:13:16719:214:e:div:t" data-fgid-d3bl132=":r38:"
                style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">
                // <?= $slug ?></div>
            <h2 class="text-4xl lg:text-5xl font-bold max-w-2xl"
                data-fg-d3bl134="0.8:1.32440:/src/app/App.tsx:436:13:16946:296:e:h2:tete" data-fgid-d3bl134=":r39:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(232, 237, 245);">
                <?= $header1 ?><br data-fg-d3bl136="0.8:1.32440:/src/app/App.tsx:441:15:17153:6:e:br"
                    data-fgid-d3bl136=":r3a:"><span
                    data-fg-d3bl137="0.8:1.32440:/src/app/App.tsx:442:15:17174:50:e:span:t" data-fgid-d3bl137=":r3b:"
                    style="color: rgb(0, 200, 255);"><?= $header2 ?></span>
            </h2>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-px"
            data-fg-d3bl139="0.8:1.32440:/src/app/App.tsx:446:11:17271:2251:e:div:x" data-fgid-d3bl139=":r3c:"
            style="background: rgba(0, 200, 255, 0.08);">
            <?php foreach ($rows as $row): ?>
            <?php $iconSrc = $resolveIconPath(is_array($row) ? $row : []); ?>
            <div class="group h-full p-8 transition-all duration-300 cursor-pointer flex flex-col"
                data-fg-d3bl141="0.8:1.32440:/src/app/App.tsx:448:15:17458:2031:e:div:etetete" data-fgid-d3bl141=":r3d:"
                style="background: rgb(6, 10, 15);">
                <div class="flex items-start justify-between mb-6"
                    data-fg-d3bl142="0.8:1.32440:/src/app/App.tsx:459:17:17932:810:e:div:ete" data-fgid-d3bl142=":r3e:">
                    <div class="p-3 rounded" data-fg-d3bl143="0.8:1.32440:/src/app/App.tsx:460:19:18006:280:e:div:e"
                        data-fgid-d3bl143=":r3f:"
                        style="background: rgba(0, 200, 255, 0.08); border: 1px solid rgba(0, 200, 255, 0.15);">
                        <img src="<?= htmlspecialchars($iconSrc, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="" class="w-6 h-6" data-fg-d3bl144="0.8:1.32440:/src/app/App.tsx:463:21:18287:36:e:img"
                            data-fgid-d3bl144=":r3g:">
                    </div><span class="text-xs px-2 py-1 rounded"
                        data-fg-d3bl145="0.8:1.32440:/src/app/App.tsx:466:19:18305:414:e:span:x"
                        data-fgid-d3bl145=":r3h:"
                        style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(90, 116, 148); background: rgba(90, 116, 148, 0.1); border: 1px solid rgba(90, 116, 148, 0.2);"><?= htmlspecialchars($row['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <h3 class="text-lg font-bold mb-3 group-hover:text-[#00c8ff] transition-colors duration-200"
                    data-fg-d3bl147="0.8:1.32440:/src/app/App.tsx:478:17:18759:227:e:h3:x" data-fgid-d3bl147=":r3i:"
                    style="color: rgb(232, 237, 245);"><?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="text-sm leading-relaxed" data-fg-d3bl149="0.8:1.32440:/src/app/App.tsx:484:17:19003:114:e:p:x"
                    data-fgid-d3bl149=":r3j:" style="color: rgb(90, 116, 148);"><?= htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <a class="flex items-center gap-1 mt-auto pt-6 text-xs opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                    data-fg-d3bl151="0.8:1.32440:/src/app/App.tsx:487:17:19134:334:e:div:te" data-fgid-d3bl151=":r3k:"
                    style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;" href="<?= htmlspecialchars($row['cta_url'] ?? '#', ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($cta_text ?? 'Learn more', ENT_QUOTES, 'UTF-8'); ?><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" class="lucide lucide-chevron-right w-3 h-3"
                        data-fg-d3bl153="0.8:1.32440:node_modules/lucide-react:491:30:19409:36:e:ChevronRight::::::ByYJ"
                        data-fgid-d3bl153=":r3l:">
                        <path d="m9 18 6-6-6-6"></path>
                    </svg></a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>