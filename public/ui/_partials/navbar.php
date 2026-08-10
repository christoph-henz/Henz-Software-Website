<?php

declare(strict_types=1);
$footerConfig = require __DIR__ . '/../_config/footer.php';
$brand = htmlspecialchars((string) ($footerConfig['brand'] ?? 'Henz Software Solutions'), ENT_QUOTES, 'UTF-8');
$note = htmlspecialchars((string) ($footerConfig['note'] ?? 'Henz Software'), ENT_QUOTES, 'UTF-8');

$navItems = require __DIR__ . '/../_config/navigation.php';
$essentialConsentGranted = in_array(
    strtolower(trim((string) ($_COOKIE['hs_essential_cookies'] ?? ''))),
    ['accepted', '1', 'true', 'yes'],
    true
);

$legalNavItems = [
    ['label' => 'Impressum', 'href' => '/impressum'],
    ['label' => 'Datenschutz', 'href' => '/datenschutz'],
    ['label' => 'AGB', 'href' => '/agb'],
];

$visibleNavItems = $essentialConsentGranted ? $navItems : $legalNavItems;
$gitCloneStyle = 'color: var(--primary); border-color: color-mix(in srgb, var(--primary) 35%, transparent); background: transparent; font-family: &quot;JetBrains Mono&quot;, monospace;' . ($essentialConsentGranted ? '' : ' display: none;');
$talkStyle = 'background: var(--primary); color: var(--primary-foreground); font-family: &quot;JetBrains Mono&quot;, monospace;' . ($essentialConsentGranted ? '' : ' display: none;');

$renderNavItems = static function (array $items): string {
    $html = '';

    foreach ($items as $item) {
        $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars((string) ($item['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $children = $item['children'] ?? [];

        if (is_array($children) && $children !== []) {
            $html .= '<li class="has-submenu relative md:group">';
            $html .= '<button class="gb-nav-link gb-submenu-toggle inline-flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm font-normal transition-colors duration-200 focus-visible:outline-none md:w-auto md:justify-start md:px-0 md:py-0" type="button" aria-expanded="false">';
            $html .= '<span>' . $label . '</span>';
            $html .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="gb-caret h-4 w-4 transition-transform duration-200" data-caret="true" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>';
            $html .= '</button>';
            $html .= '<ul class="gb-submenu gb-submenu-panel hidden space-y-1 pl-3 md:absolute md:left-0 md:top-full md:z-30 md:mt-3 md:min-w-56 md:space-y-0 md:rounded-xl md:p-2 md:pl-2 md:backdrop-blur-md md:shadow-2xl" role="menu">';

            foreach ($children as $child) {
                $childLabel = htmlspecialchars((string) ($child['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $childHref = htmlspecialchars((string) ($child['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
                $html .= '<li role="none"><a href="' . $childHref . '" class="gb-submenu-link block rounded-lg px-3 py-2 text-sm transition-colors duration-200 focus-visible:outline-none" role="menuitem">' . $childLabel . '</a></li>';
            }

            $html .= '</ul>';
            $html .= '</li>';
            continue;
        }

        $html .= '<li><a href="' . $href . '" class="gb-nav-link block rounded-lg px-3 py-2 text-sm font-normal transition-colors duration-200 focus-visible:outline-none md:px-0 md:py-0">' . $label . '</a></li>';
    }

    return $html;
};
?>
<header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300"
    data-fg-d3bl12="0.8:1.32440:/src/app/App.tsx:169:7:5654:3772:e:header:etx" data-fgid-d3bl12=":r1:"
    style="background: color-mix(in srgb, var(--background) 92%, transparent); backdrop-filter: blur(16px); border-bottom: 1px solid color-mix(in srgb, var(--primary) 10%, transparent);">
    <div class="relative max-w-7xl mx-auto px-6 h-16 flex items-center justify-between"
        data-fg-d3bl13="0.8:1.32440:/src/app/App.tsx:177:9:6014:2734:e:div:etetete" data-fgid-d3bl13=":r2:">
        <a href="/" class="flex items-center gap-2"
            data-fg-d3bl14="0.8:1.32440:/src/app/App.tsx:178:11:6104:397:e:div:ete" data-fgid-d3bl14=":r3:"><svg
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-terminal w-5 h-5"
                data-fg-d3bl15="0.8:1.32440:node_modules/lucide-react:179:13:6158:61:e:Terminal::::::DcQ8"
                data-fgid-d3bl15=":r4:" style="color: rgb(0, 200, 255);">
                <polyline points="4 17 10 11 4 5"></polyline>
                <line x1="12" x2="20" y1="19" y2="19"></line>
            </svg><span class="text-lg font-bold tracking-tight"
                data-fg-d3bl16="0.8:1.32440:/src/app/App.tsx:180:13:6232:252:e:span:te" data-fgid-d3bl16=":r5:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: var(--foreground);"><?= $note; ?><span
                    data-fg-d3bl18="0.8:1.32440:/src/app/App.tsx:184:23:6419:45:e:span:t" data-fgid-d3bl18=":r6:"
                    style="color: rgb(0, 200, 255);">.de</span></span></a>
        <nav class="gb-nav gb-nav-surface absolute left-4 right-4 top-[4.5rem] hidden rounded-2xl p-4 backdrop-blur-md shadow-2xl md:static md:block md:rounded-none md:border-0 md:bg-transparent md:p-0 md:shadow-none"
            data-fg-d3bl20="0.8:1.32440:/src/app/App.tsx:188:11:6513:559:e:nav:x" data-fgid-d3bl20=":r7:">
            <ul class="flex flex-col gap-2 md:flex-row md:items-center md:gap-8" role="menubar">
                <?= $renderNavItems($visibleNavItems); ?>
            </ul>
        </nav>
        <div class="hidden md:flex items-center gap-3"
            data-fg-d3bl24="0.8:1.32440:/src/app/App.tsx:203:11:7084:1386:e:div:ete" data-fgid-d3bl24=":rd:"><button
                class="px-5 py-2 text-sm font-medium rounded transition-all duration-200 border"
                data-fg-d3bl25="0.8:1.32440:/src/app/App.tsx:204:13:7148:685:e:button:t" data-fgid-d3bl25=":re:"
                style="<?= htmlspecialchars($gitCloneStyle, ENT_QUOTES, 'UTF-8'); ?>"
                onclick="window.location.href='https://github.com/christoph-henz/Henz-Software-Website'">git
                clone project</button><button
                class="px-5 py-2 text-sm font-semibold rounded transition-all duration-200"
                data-fg-d3bl27="0.8:1.32440:/src/app/App.tsx:221:13:7846:607:e:button:s" data-fgid-d3bl27=":rf:"
                style="<?= htmlspecialchars($talkStyle, ENT_QUOTES, 'UTF-8'); ?>"
                onclick="window.location.href='/kontakt'">//
                let's talk</button></div><button class="gb-nav-toggle md:hidden p-2"
            data-fg-d3bl29="0.8:1.32440:/src/app/App.tsx:239:11:8482:251:e:button:x" data-fgid-d3bl29=":rg:"
            type="button" aria-expanded="false" aria-label="Navigation umschalten"
            style="color: var(--foreground);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" class="lucide lucide-menu w-5 h-5"
                data-fg-d3bl32="0.8:1.32440:node_modules/lucide-react:244:53:8684:28:e:Menu::::::D5X5"
                data-fgid-d3bl32=":rh:">
                <line x1="4" x2="20" y1="12" y2="12"></line>
                <line x1="4" x2="20" y1="6" y2="6"></line>
                <line x1="4" x2="20" y1="18" y2="18"></line>
            </svg></button>
    </div>
</header>