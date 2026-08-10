<?php

declare(strict_types=1);
$project = require __DIR__ . '/../_config/project.php';
$slug = (string) ($project['slug'] ?? 'Unsere Projekte');
$header1 = (string) ($project['header1'] ?? 'In die Produktion');
$header2 = (string) ($project['header2'] ?? 'integriert');
$projects_cta = (array) ($project['projects_cta'] ?? ['text' => 'Alle Projekte ansehen', 'url' => '/projekte']);
$rows = is_array($project['rows'] ?? null) ? $project['rows'] : [];
$cta_text = (string) ($project['cta_text'] ?? 'Alle Angebote ansehen');
$resolveProjectMedia = static function (array $row): array {
    $mediaFile = trim((string) ($row['project_media_path'] ?? ''));

    if ($mediaFile === '') {
        return [
            'src' => '/ui/_assets/images/profile-placeholder.svg',
            'isVideo' => false,
            'mimeType' => null,
        ];
    }

    $mediaFile = ltrim(str_replace('\\', '/', $mediaFile), '/');
    $mediaFile = preg_replace('#^(?:storage/media/)?referenced_projects/#i', '', $mediaFile) ?? $mediaFile;
    $absolutePath = base_path('/storage/media/referenced_projects/' . $mediaFile);

    if (!is_file($absolutePath)) {
        return [
            'src' => '/ui/_assets/images/profile-placeholder.svg',
            'isVideo' => false,
            'mimeType' => null,
        ];
    }

    $extension = strtolower(pathinfo($mediaFile, PATHINFO_EXTENSION));
    $videoMimeTypes = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
    ];

    $resolvedMediaFile = $mediaFile;
    if (!isset($videoMimeTypes[$extension])) {
        $optimizedFile = preg_replace('/\.[^.]+$/', '-960.jpg', $mediaFile) ?? $mediaFile;
        $optimizedPath = base_path('/storage/media/referenced_projects/' . $optimizedFile);
        if (is_file($optimizedPath)) {
            $resolvedMediaFile = $optimizedFile;
        }
    }

    return [
        'src' => '/storage/media/referenced_projects/' . rawurlencode($resolvedMediaFile),
        'isVideo' => isset($videoMimeTypes[$extension]),
        'mimeType' => $videoMimeTypes[$extension] ?? null,
    ];
};
if (empty($rows)) {
    return;
}
?>
<section id="projekte" class="py-28" data-fg-d3bl155="0.8:1.32440:/src/app/App.tsx:500:7:19581:4259:e:section:e"
    data-fgid-d3bl155=":r53:" style="border-top: 1px solid rgba(0, 200, 255, 0.08);">
    <div class="max-w-7xl mx-auto px-6" data-fg-d3bl156="0.8:1.32440:/src/app/App.tsx:501:9:19673:4150:e:div:ete"
        data-fgid-d3bl156=":r54:">
        <div class="mb-12 flex flex-col items-start justify-between gap-6 md:mb-16 md:flex-row md:items-end"
            data-fg-d3bl157="0.8:1.32440:/src/app/App.tsx:502:11:19724:1436:e:div:ete" data-fgid-d3bl157=":r55:">
            <div data-fg-d3bl158="0.8:1.32440:/src/app/App.tsx:503:13:19791:586:e:div:ete" data-fgid-d3bl158=":r56:">
                <div class="text-xs uppercase tracking-widest mb-4"
                    data-fg-d3bl159="0.8:1.32440:/src/app/App.tsx:504:15:19811:229:e:div:t" data-fgid-d3bl159=":r57:"
                    style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">
                    // <?= $slug ?></div>
                <h2 class="text-4xl lg:text-5xl font-bold"
                    data-fg-d3bl161="0.8:1.32440:/src/app/App.tsx:510:15:20055:303:e:h2:tete" data-fgid-d3bl161=":r58:"
                    style="font-family: &quot;JetBrains Mono&quot;, monospace; color: var(--foreground);">
                    <?= $header1 ?><br data-fg-d3bl163="0.8:1.32440:/src/app/App.tsx:515:17:20263:6:e:br"
                        data-fgid-d3bl163=":r59:"><span
                        data-fg-d3bl164="0.8:1.32440:/src/app/App.tsx:516:17:20286:52:e:span:t"
                        data-fgid-d3bl164=":r5a:" style="color: rgb(0, 200, 255);"><?= $header2 ?></span>
                </h2>
            </div><button
                class="hidden md:flex items-center gap-2 text-sm border px-5 py-2.5 rounded transition-all duration-200"
                data-fg-d3bl166="0.8:1.32440:/src/app/App.tsx:519:13:20390:753:e:button:te" data-fgid-d3bl166=":r5b:"
                style="color: var(--muted-foreground); border-color: var(--border);"><?= $projects_cta['text'] ?><svg
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-arrow-right w-4 h-4"
                    data-fg-d3bl168="0.8:1.32440:node_modules/lucide-react:531:32:21087:34:e:ArrowRight::::::s5N"
                    data-fgid-d3bl168=":r5c:" onclick="<?= $projects_cta['url'] ?>">
                    <path d="M5 12h14"></path>
                    <path d="m12 5 7 7-7 7"></path>
                </svg></button>
        </div>
        <div class="flex flex-col gap-6" data-fg-d3bl169="0.8:1.32440:/src/app/App.tsx:535:11:21172:2636:e:div:x"
            data-fgid-d3bl169=":r5d:">
            <?php foreach ($rows as $row): ?>
                <?php $media = $resolveProjectMedia(is_array($row) ? $row : []); ?>
                <div class="group grid md:grid-cols-2 gap-0 rounded-xl overflow-hidden border cursor-pointer transition-all duration-300"
                    data-fg-d3bl171="0.8:1.32440:/src/app/App.tsx:537:15:21285:2490:e:div:ete" data-fgid-d3bl171=":r5e:"
                    style="border-color: rgba(0, 200, 255, 0.1);">
                    <div class="relative overflow-hidden"
                        data-fg-d3bl172="0.8:1.32440:/src/app/App.tsx:548:17:21854:598:e:div:ete" data-fgid-d3bl172=":r5f:"
                        style="min-height: 260px; background: var(--card);">
                        <?php if ($media['isVideo']): ?>
                                            <video autoplay muted loop playsinline preload="none" data-deferred-video="1" data-video-src="<?= $media['src'] ?>" data-video-type="<?= $media['mimeType'] ?>"
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                                data-fg-d3bl173="0.8:1.32440:/src/app/App.tsx:549:19:21959:265:e:img" data-fgid-d3bl173=":r5g:"
                                style="position: absolute; inset: 0px;">
                            </video>
                        <?php else: ?>
                            <img src="<?= $media['src'] ?>" alt="<?= $row['title'] ?? 'Project Image' ?>" loading="lazy" decoding="async"
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                                data-fg-d3bl173="0.8:1.32440:/src/app/App.tsx:549:19:21959:265:e:img" data-fgid-d3bl173=":r5g:"
                                style="position: absolute; inset: 0px;">
                        <?php endif; ?>
                        <div class="absolute inset-0" data-fg-d3bl174="0.8:1.32440:/src/app/App.tsx:555:19:22243:186:e:div"
                            data-fgid-d3bl174=":r5h:"
                            style="background: linear-gradient(135deg, rgba(0, 200, 255, 0.12) 0%, transparent 60%);">
                        </div>
                    </div>
                    <div class="min-w-0 flex flex-col justify-between p-6 sm:p-8 lg:p-10"
                        data-fg-d3bl175="0.8:1.32440:/src/app/App.tsx:560:17:22469:1285:e:div:ete" data-fgid-d3bl175=":r5i:"
                        style="background: var(--card);">
                        <div data-fg-d3bl176="0.8:1.32440:/src/app/App.tsx:564:19:22627:701:e:div:etete"
                            data-fgid-d3bl176=":r5j:">
                            <div class="mb-4 text-xs uppercase tracking-widest break-words [overflow-wrap:anywhere] hyphens-auto"
                                data-fg-d3bl177="0.8:1.32440:/src/app/App.tsx:565:21:22653:253:e:div:x"
                                data-fgid-d3bl177=":r5k:"
                                style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">
                                <?= $row['slug'] ?? '' ?></div>
                            <h3 class="mb-4 text-xl sm:text-2xl font-bold break-words [overflow-wrap:anywhere] hyphens-auto leading-tight"
                                data-fg-d3bl179="0.8:1.32440:/src/app/App.tsx:571:21:22927:233:e:h3:x"
                                data-fgid-d3bl179=":r5l:"
                                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: var(--foreground);">
                                <?= $row['title'] ?? 'No title available' ?>
                            </h3>
                            <p class="text-sm leading-relaxed break-words [overflow-wrap:anywhere] hyphens-auto"
                                data-fg-d3bl181="0.8:1.32440:/src/app/App.tsx:577:21:23181:122:e:p:x"
                                data-fgid-d3bl181=":r5m:" style="color: var(--muted-foreground);">
                                <?= $row['description'] ?? 'No description available' ?></p>
                        </div>
                        <a class="flex items-center gap-2 mt-8 text-sm opacity-0 group-hover:opacity-100 transition-all duration-200 -translate-x-2 group-hover:translate-x-0"
                            data-fg-d3bl183="0.8:1.32440:/src/app/App.tsx:581:19:23347:384:e:div:te"
                            data-fgid-d3bl183=":r5n:"
                            style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;" href="/<?= $row['project_slug'] ?? '#' ?>">
                            <?= $row['cta_text'] ?? 'View case study' ?><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-4 h-4"
                                data-fg-d3bl185="0.8:1.32440:node_modules/lucide-react:585:37:23672:34:e:ArrowRight::::::s5N"
                                data-fgid-d3bl185=":r5o:">
                                <path d="M5 12h14"></path>
                                <path d="m12 5 7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>