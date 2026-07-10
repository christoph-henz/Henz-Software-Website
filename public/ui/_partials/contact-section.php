<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/contact.php');
$slug = (string) ($cfg['slug'] ?? 'Neues Projekt?');
$header1 = (string) ($cfg['header1'] ?? 'Trusted by');
$header2 = (string) ($cfg['header2'] ?? 'engineering teams');
$subtitle = (string) ($cfg['subtitle'] ?? 'We take on a limited number of projects each quarter. Tell us what you\'re building.');
$cta1 = (array) ($cfg['cta1'] ?? ['text' => 'Start a conversation', 'url' => '/kontakt']);
$cta2 = (array) ($cfg['cta2'] ?? ['text' => 'Browse case studies', 'url' => '/projekte']);
?>
<section class="relative py-32 overflow-hidden"
    data-fg-d3bl226="0.8:1.32440:/src/app/App.tsx:700:7:28130:2876:e:section:ete" data-fgid-d3bl226=":r8h:"
    style="border-top: 1px solid rgba(0, 200, 255, 0.08);">
    <div class="absolute inset-0" data-fg-d3bl227="0.8:1.32440:/src/app/App.tsx:701:9:28247:190:e:div"
        data-fgid-d3bl227=":r8i:" style="background: radial-gradient(rgba(0, 200, 255, 0.06) 0%, transparent 70%);">
    </div>
    <div class="relative z-10 max-w-4xl mx-auto px-6 text-center"
        data-fg-d3bl228="0.8:1.32440:/src/app/App.tsx:707:9:28446:2543:e:div:etetete" data-fgid-d3bl228=":r8j:">
        <div class="text-xs uppercase tracking-widest mb-6"
            data-fg-d3bl229="0.8:1.32440:/src/app/App.tsx:708:11:28523:208:e:div:t" data-fgid-d3bl229=":r8k:"
            style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">//
            <?=$slug ?></div>
        <h2 class="text-5xl lg:text-6xl font-bold mb-6 leading-tight"
            data-fg-d3bl231="0.8:1.32440:/src/app/App.tsx:714:11:28742:305:e:h2:tete" data-fgid-d3bl231=":r8l:"
            style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(232, 237, 245);">
            <?=$header1 ?><br data-fg-d3bl233="0.8:1.32440:/src/app/App.tsx:719:13:28954:6:e:br"
                data-fgid-d3bl233=":r8m:"><span data-fg-d3bl234="0.8:1.32440:/src/app/App.tsx:720:13:28973:58:e:span:t"
                data-fgid-d3bl234=":r8n:" style="color: rgb(0, 200, 255);"><?=$header2 ?></span>
        </h2>
        <p class="text-lg mb-12 max-w-xl mx-auto" data-fg-d3bl236="0.8:1.32440:/src/app/App.tsx:722:11:29058:186:e:p:t"
            data-fgid-d3bl236=":r8o:" style="color: rgb(90, 116, 148);"><?=$subtitle ?></p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center"
            data-fg-d3bl238="0.8:1.32440:/src/app/App.tsx:725:11:29255:1719:e:div:ete" data-fgid-d3bl238=":r8p:"><button
                class="group flex items-center justify-center gap-2 px-8 py-4 rounded font-bold text-sm transition-all duration-200"
                data-fg-d3bl239="0.8:1.32440:/src/app/App.tsx:726:13:29332:760:e:button:te" data-fgid-d3bl239=":r8q:"
                style="background: rgb(0, 200, 255); color: rgb(6, 10, 15); font-family: &quot;JetBrains Mono&quot;, monospace;" onclick="window.location.href='<?=$cta1['url'] ?>'"><?=$cta1['text'] ?><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-arrow-right w-4 h-4 transition-transform duration-200 group-hover:translate-x-1"
                    data-fg-d3bl241="0.8:1.32440:node_modules/lucide-react:741:15:29976:94:e:ArrowRight::::::s5N"
                    data-fgid-d3bl241=":r8r:">
                    <path d="M5 12h14"></path>
                    <path d="m12 5 7 7-7 7"></path>
                </svg></button><button
                class="flex items-center justify-center gap-2 px-8 py-4 rounded font-medium text-sm border transition-all duration-200"
                data-fg-d3bl242="0.8:1.32440:/src/app/App.tsx:743:13:30105:852:e:button:t" data-fgid-d3bl242=":r8s:"
                style="color: rgb(232, 237, 245); border-color: rgba(232, 237, 245, 0.15); font-family: &quot;JetBrains Mono&quot;, monospace;" onclick="window.location.href='<?=$cta2['url'] ?>'"><?=$cta2['text'] ?></button></div>
    </div>
</section>