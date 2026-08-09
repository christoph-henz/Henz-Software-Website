<?php

declare(strict_types=1);
$cfg = require base_path('public/ui/_config/hero.php');
$status = htmlspecialchars((string) ($cfg['status'] ?? ''), ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars((string) ($cfg['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$subtitle = htmlspecialchars((string) ($cfg['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
$cta1 = $cfg['cta1'] ?? [];
$cta2 = $cfg['cta2'] ?? [];

?>
<section class="relative min-h-screen flex flex-col justify-center pt-16"
    data-fg-d3bl41="0.8:1.32440:/src/app/App.tsx:269:7:9453:7103:e:section:etetxte" data-fgid-d3bl41=":ri:">
    
    
    <div class="absolute inset-0 overflow-hidden pointer-events-none"
        data-fg-d3bl0="0.8:1.32440:/src/app/App.tsx:101:5:3413:1153:e:div:etetetxte:1" data-fgid-d3bl0=":rk:"
        data-fg-callsite-d3bl42="">
        <div class="absolute -top-40 -left-40 w-[700px] h-[700px] rounded-full opacity-20"
            data-fg-d3bl1="0.8:1.32440:/src/app/App.tsx:102:7:3490:241:e:div" data-fgid-d3bl1=":rl:"
            style="background: radial-gradient(circle, rgb(0, 200, 255) 0%, transparent 70%); filter: blur(80px);">
        </div>
        <div class="absolute top-60 right-0 w-[500px] h-[500px] rounded-full opacity-10"
            data-fg-d3bl2="0.8:1.32440:/src/app/App.tsx:109:7:3738:240:e:div" data-fgid-d3bl2=":rm:"
            style="background: radial-gradient(circle, rgb(0, 102, 255) 0%, transparent 70%); filter: blur(100px);">
        </div>
        <div class="absolute bottom-0 left-1/3 w-[400px] h-[400px] rounded-full opacity-10"
            data-fg-d3bl3="0.8:1.32440:/src/app/App.tsx:116:7:3985:242:e:div" data-fgid-d3bl3=":rn:"
            style="background: radial-gradient(circle, rgb(0, 200, 255) 0%, transparent 70%); filter: blur(90px);">
        </div>
        <div class="absolute inset-0 opacity-[0.04]" data-fg-d3bl5="0.8:1.32440:/src/app/App.tsx:124:7:4261:294:e:div"
            data-fgid-d3bl5=":ro:"
            style="background-image: linear-gradient(rgb(0, 200, 255) 1px, transparent 1px), linear-gradient(90deg, rgb(0, 200, 255) 1px, transparent 1px); background-size: 60px 60px;">
        </div>
    </div>


    <div class="relative z-10 max-w-7xl mx-auto px-6 py-24 grid lg:grid-cols-2 gap-16 items-center"
        data-fg-d3bl43="0.8:1.32440:/src/app/App.tsx:271:9:9567:6166:e:div:etxte" data-fgid-d3bl43=":rp:">
        <div data-fg-d3bl44="0.8:1.32440:/src/app/App.tsx:272:11:9678:2870:e:div:etetete" data-fgid-d3bl44=":rq:">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded text-xs mb-8 border"
                data-fg-d3bl45="0.8:1.32440:/src/app/App.tsx:273:13:9696:508:e:div:et" data-fgid-d3bl45=":rr:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(0, 200, 255); border-color: rgba(0, 200, 255, 0.25); background: rgba(0, 200, 255, 0.06);">
                <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"
                    data-fg-d3bl46="0.8:1.32440:/src/app/App.tsx:282:15:10064:72:e:span"
                    data-fgid-d3bl46=":rs:"></span><?= $status; ?>
            </div>
            <h1 class="text-5xl lg:text-7xl font-bold leading-[1.05] tracking-tight mb-6"
                data-fg-d3bl48="0.8:1.32440:/src/app/App.tsx:286:13:10218:377:e:h1:tetete" data-fgid-d3bl48=":rt:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: var(--foreground);">
                <?= $title; ?>
            </h1>
            <p class="text-lg leading-relaxed mb-10 max-w-lg"
                data-fg-d3bl55="0.8:1.32440:/src/app/App.tsx:297:13:10609:263:e:p:t" data-fgid-d3bl55=":r11:"
                style="color: var(--muted-foreground);"><?= $subtitle; ?></p>
            <div class="flex flex-wrap gap-4" data-fg-d3bl57="0.8:1.32440:/src/app/App.tsx:302:13:10886:1645:e:div:ete"
                data-fgid-d3bl57=":r12:"><button
                    class="group flex items-center gap-2 px-7 py-3.5 rounded font-semibold text-sm transition-all duration-200"
                    data-fg-d3bl58="0.8:1.32440:/src/app/App.tsx:303:15:10939:707:e:button:te" data-fgid-d3bl58=":r13:"
                    style="background: var(--primary); color: var(--primary-foreground); font-family: &quot;JetBrains Mono&quot;, monospace;" onclick="location.href='<?= $cta1['href'] ?? '#'; ?>'"><?= $cta1['label'] ?? ''; ?><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-arrow-right w-4 h-4 transition-transform duration-200 group-hover:translate-x-1"
                        data-fg-d3bl60="0.8:1.32440:node_modules/lucide-react:314:17:11528:94:e:ArrowRight::::::s5N"
                        data-fgid-d3bl60=":r14:">
                        <path d="M5 12h14"></path>
                        <path d="m12 5 7 7-7 7"></path>
                    </svg></button>
                    <button class="flex items-center gap-2 px-7 py-3.5 rounded font-medium text-sm border transition-all duration-200"
                    data-fg-d3bl61="0.8:1.32440:/src/app/App.tsx:316:15:11661:851:e:button:t" data-fgid-d3bl61=":r15:"
                    style="color: var(--foreground); border-color: var(--border); background: transparent;" onclick="location.href='<?= $cta2['href'] ?? '#'; ?>'"><?= $cta2['label'] ?? ''; ?></button></div>
        </div>