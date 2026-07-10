<?php

declare(strict_types=1);

$hero = is_array($cfg['hero'] ?? null) ? $cfg['hero'] : [];
$sections = is_array($cfg['sections'] ?? null) ? $cfg['sections'] : [];
?>
<section class="relative overflow-hidden pt-32 pb-18" style="border-bottom: 1px solid rgba(0, 200, 255, 0.08);">
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-32 left-0 h-96 w-96 rounded-full opacity-20"
            style="background: radial-gradient(circle, rgba(0, 200, 255, 0.22) 0%, transparent 70%); filter: blur(90px);"></div>
        <div class="absolute right-0 top-16 h-80 w-80 rounded-full opacity-10"
            style="background: radial-gradient(circle, rgba(0, 102, 255, 0.22) 0%, transparent 70%); filter: blur(100px);"></div>
        <div class="absolute inset-0 opacity-[0.04]"
            style="background-image: linear-gradient(rgb(0, 200, 255) 1px, transparent 1px), linear-gradient(90deg, rgb(0, 200, 255) 1px, transparent 1px); background-size: 60px 60px;"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6">
        <div class="max-w-3xl">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded text-xs mb-8 border"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(0, 200, 255); border-color: rgba(0, 200, 255, 0.25); background: rgba(0, 200, 255, 0.06);">
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                // <?= htmlspecialchars((string) ($hero['tag'] ?? 'Rechtliches'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <h1 class="text-5xl lg:text-7xl font-bold leading-[1.05] tracking-tight mb-6"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(232, 237, 245);">
                <?= htmlspecialchars((string) ($hero['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <?php if ((string) ($hero['intro'] ?? '') !== ''): ?>
                <p class="text-lg leading-relaxed max-w-2xl"
                    style="color: rgb(90, 116, 148);">
                    <?= htmlspecialchars((string) $hero['intro'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="py-24" style="border-top: 1px solid rgba(0, 200, 255, 0.04);">
    <div class="max-w-7xl mx-auto px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <?php foreach ($sections as $section): ?>
                <?php
                $title = (string) ($section['title'] ?? '');
                $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
                ?>
                <article class="h-full rounded-2xl border p-8 lg:p-10"
                    style="border-color: rgba(0, 200, 255, 0.1); background: rgb(12, 21, 32); box-shadow: 0 0 0 1px rgba(0, 200, 255, 0.03) inset;">
                    <?php if ($title !== ''): ?>
                        <div class="text-xs uppercase tracking-[0.28em] mb-4"
                            style="color: rgb(0, 200, 255); font-family: &quot;JetBrains Mono&quot;, monospace;">
                            // legal section
                        </div>
                        <h2 class="text-2xl lg:text-3xl font-bold mb-6"
                            style="font-family: &quot;JetBrains Mono&quot;, monospace; color: rgb(232, 237, 245);">
                            <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                    <?php endif; ?>

                    <div class="space-y-5">
                        <?php foreach ($blocks as $block): ?>
                            <?php
                            $type = (string) ($block['type'] ?? 'text');
                            if ($type === 'list') {
                                $items = is_array($block['items'] ?? null) ? $block['items'] : [];
                                if ($items === []) {
                                    continue;
                                }
                                ?>
                                <ul class="space-y-3">
                                    <?php foreach ($items as $item): ?>
                                        <li class="rounded-xl border px-4 py-3 text-sm leading-relaxed"
                                            style="border-color: rgba(90, 116, 148, 0.18); background: rgba(90, 116, 148, 0.06); color: rgb(138, 157, 181);">
                                            <?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php
                                continue;
                            }

                            $text = (string) ($block['text'] ?? '');
                            if ($text === '') {
                                continue;
                            }
                            ?>
                            <p class="text-sm leading-7"
                                style="color: rgb(138, 157, 181);"><?= nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
