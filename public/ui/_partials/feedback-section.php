<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/feedback.php');
$slug = (string) ($cfg['slug'] ?? 'Kundenfeedback');
$header1 = (string) ($cfg['header1'] ?? 'Trusted by');
$header2 = (string) ($cfg['header2'] ?? 'engineering teams');
$items = $cfg['items'] ?? [];
$resolveIconPath = static function (array $item): string {
    $iconFile = trim((string) ($item['icon'] ?? ''));

    if ($iconFile === '') {
        return '/ui/_assets/images/profile-placeholder.svg';
    }

    $iconFile = ltrim(str_replace('\\', '/', $iconFile), '/');
    $absolutePath = base_path('/storage/media/referenced_projects/' . $iconFile);

    if (!is_file($absolutePath)) {
        return '/ui/_assets/images/profile-placeholder.svg';
    }

    return '/storage/media/referenced_projects/' . rawurlencode($iconFile);
};
if (empty($items)) {
    return;
}
?>
<section class="py-28" data-fg-d3bl196="0.8:1.32440:/src/app/App.tsx:636:7:25589:2515:e:section:e"
    data-fgid-d3bl196=":r6v:" style="border-top: 1px solid rgba(0, 200, 255, 0.08);">
    <div class="max-w-7xl mx-auto px-6" data-fg-d3bl197="0.8:1.32440:/src/app/App.tsx:637:9:25681:2406:e:div:ete"
        data-fgid-d3bl197=":r70:">
        <div class="mb-16" data-fg-d3bl198="0.8:1.32440:/src/app/App.tsx:638:11:25732:585:e:div:ete"
            data-fgid-d3bl198=":r71:">
            <div class="text-xs uppercase tracking-widest mb-4"
                data-fg-d3bl199="0.8:1.32440:/src/app/App.tsx:639:13:25768:221:e:div:t" data-fgid-d3bl199=":r72:"
                style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">
                // <?= $slug; ?></div>
            <h2 class="text-4xl lg:text-5xl font-bold"
                data-fg-d3bl201="0.8:1.32440:/src/app/App.tsx:645:13:26002:298:e:h2:tete" data-fgid-d3bl201=":r73:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: var(--foreground);">
                <?= $header1; ?><br data-fg-d3bl203="0.8:1.32440:/src/app/App.tsx:650:15:26202:6:e:br"
                    data-fgid-d3bl203=":r74:"><span
                    data-fg-d3bl204="0.8:1.32440:/src/app/App.tsx:651:15:26223:59:e:span:t" data-fgid-d3bl204=":r75;"
                    style="color: rgb(0, 200, 255);"><?= $header2; ?></span></h2>
        </div>
        <div class="grid md:grid-cols-3 gap-6" data-fg-d3bl206="0.8:1.32440:/src/app/App.tsx:655:11:26329:1743:e:div:x"
            data-fgid-d3bl206=":r76:">
            <?php foreach ($items as $item):
                $icon = $resolveIconPath(is_array($item) ? $item : []);
                $name = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $role = htmlspecialchars((string) ($item['role'] ?? ''), ENT_QUOTES, 'UTF-8');
                $feedback = htmlspecialchars((string) ($item['feedback'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>
            <div class="p-8 rounded-xl border flex flex-col justify-between"
                data-fg-d3bl208="0.8:1.32440:/src/app/App.tsx:657:15:26456:1583:e:div:ete" data-fgid-d3bl208=":r77:"
                style="border-color: rgba(0, 200, 255, 0.1); background: var(--card);">
                <div data-fg-d3bl209="0.8:1.32440:/src/app/App.tsx:662:17:26688:428:e:div:ete"
                    data-fgid-d3bl209=":r78:">
                    <div class="flex gap-0.5 mb-6"
                        data-fg-d3bl210="0.8:1.32440:/src/app/App.tsx:663:19:26712:236:e:div:x"
                        data-fgid-d3bl210=":r79:"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-star w-4 h-4 fill-current"
                            data-fg-d3bl212="0.8:1.32440:node_modules/lucide-react:665:23:26821:78:e:Star::::::hX0"
                            data-fgid-d3bl212=":r7a:" style="color: rgb(0, 200, 255);">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="lucide lucide-star w-4 h-4 fill-current"
                            data-fg-d3bl212="0.8:1.32440:node_modules/lucide-react:665:23:26821:78:e:Star::::::hX0"
                            data-fgid-d3bl212=":r7b:" style="color: rgb(0, 200, 255);">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="lucide lucide-star w-4 h-4 fill-current"
                            data-fg-d3bl212="0.8:1.32440:node_modules/lucide-react:665:23:26821:78:e:Star::::::hX0"
                            data-fgid-d3bl212=":r7c:" style="color: rgb(0, 200, 255);">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="lucide lucide-star w-4 h-4 fill-current"
                            data-fg-d3bl212="0.8:1.32440:node_modules/lucide-react:665:23:26821:78:e:Star::::::hX0"
                            data-fgid-d3bl212=":r7d:" style="color: rgb(0, 200, 255);">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="lucide lucide-star w-4 h-4 fill-current"
                            data-fg-d3bl212="0.8:1.32440:node_modules/lucide-react:665:23:26821:78:e:Star::::::hX0"
                            data-fgid-d3bl212=":r7e:" style="color: rgb(0, 200, 255);">
                            <path
                                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z">
                            </path>
                        </svg></div>
                    <p class="text-sm leading-relaxed mb-8"
                        data-fg-d3bl213="0.8:1.32440:/src/app/App.tsx:668:19:26967:126:e:p:txt"
                        data-fgid-d3bl213=":r7f:" style="color: var(--muted-foreground);"><?= $feedback; ?></p>
                </div>
                <div class="flex items-center gap-3"
                    data-fg-d3bl217="0.8:1.32440:/src/app/App.tsx:672:17:27133:885:e:div:ete" data-fgid-d3bl217=":r7g:">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden"
                        data-fg-d3bl218="0.8:1.32440:/src/app/App.tsx:673:19:27193:475:e:div:x"
                        data-fgid-d3bl218=":r7h:"
                        style="background: rgba(0, 200, 255, 0.1); color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace; border: 1px solid rgba(0, 200, 255, 0.2);">
                        <img class="w-full h-full object-cover"
                            src="<?= $icon; ?>" alt="<?= $name; ?>">
                    </div>
                    <div data-fg-d3bl220="0.8:1.32440:/src/app/App.tsx:684:19:27687:308:e:div:ete"
                        data-fgid-d3bl220=":r7i:">
                        <div class="text-sm font-semibold"
                            data-fg-d3bl221="0.8:1.32440:/src/app/App.tsx:685:21:27713:126:e:div:x"
                            data-fgid-d3bl221=":r7j:" style="color: var(--foreground);"><?= $name; ?></div>
                        <div class="text-xs" data-fg-d3bl223="0.8:1.32440:/src/app/App.tsx:688:21:27860:110:e:div:x"
                            data-fgid-d3bl223=":r7k:" style="color: var(--muted-foreground);"><?= $role; ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>