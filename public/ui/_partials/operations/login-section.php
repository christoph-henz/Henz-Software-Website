<?php
$cfg = require base_path('public/ui/_config/operations/login.php');
$slug = htmlspecialchars($cfg['slug'] ?? 'henz-software');
$pageTitle = htmlspecialchars($cfg['page_title'] ?? 'LOGIN');
$welcome = htmlspecialchars($cfg['welcome'] ?? 'Willkommen');
$subtitle = htmlspecialchars($cfg['subtitle'] ?? '');
$field1 = $cfg['field1'] ?? [];
$field2 = $cfg['field2'] ?? [];
$forgetPassword = htmlspecialchars($cfg['forget_password'] ?? 'vergessen?');
$loginButton = htmlspecialchars($cfg['login_button'] ?? 'Anmelden');
$ssoButton = htmlspecialchars($cfg['sso_button'] ?? 'Mit SSO anmelden');

?>
<div class="min-h-screen flex items-center justify-center relative overflow-hidden"
    style="min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Inter',sans-serif; background:#060a0f;">
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[600px] rounded-full" style="
                opacity:.15;
                background:radial-gradient(ellipse,#00c8ff 0%,transparent 65%); filter:blur(100px);">
        </div>
        <div class="absolute bottom-0 right-0 w-[400px] h-[400px] rounded-full" style="
                opacity:.08;
                background:radial-gradient(circle,#0044ff 0%,transparent 70%);
                filter:blur(80px);">
        </div>
        <div class="absolute inset-0" style="
                opacity:.035;
                background-image:
                    linear-gradient(rgba(0,200,255,1) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0,200,255,1) 1px, transparent 1px);
                background-size:60px 60px;">
        </div>
    </div>
    <div class="relative z-10 mx-auto px-6 max-w-[460px] w-full">
        <div class="flex items-center justify-between mb-12">
            <div class="flex items-center gap-2">
                <!-- Terminal Icon -->
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#00c8ff" stroke-width="2">
                    <polyline points="4 17 10 11 4 5"></polyline>
                    <line x1="12" y1="19" x2="20" y2="19"></line>
                </svg>
                <span class="text-base font-bold" style="font-family:'JetBrains Mono', monospace;color:#e8edf5;">
                    <?= $slug; ?><span style="color:#00c8ff;">.com</span>
                </span>
            </div>
        </div>
        <div class="rounded-2xl p-8 border" style="
                background:rgba(12,21,32,.85);
                backdrop-filter:blur(24px);
                border-color:rgba(0,200,255,.15);
                box-shadow:0 0 60px rgba(0,200,255,.05),0 32px 64px rgba(0,0,0,.5);">
            <div class="mb-8">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded text-xs mb-5 border" style="
                        font-family:'JetBrains Mono', monospace;
                        color:#00c8ff;
                        border-color:rgba(0,200,255,.2);
                        background:rgba(0,200,255,.05);"><?= $pageTitle; ?>
                </div>
                <h1 class="text-3xl font-bold mb-2" style="font-family:'JetBrains Mono', monospace;color:#e8edf5;">
                    <?= $welcome; ?>
                </h1>
                <p class="text-sm" style="color:#5a7494;"><?= $subtitle; ?>
                </p>
            </div>
            <form method="post" action="/login" class="flex flex-col gap-5">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo) ?>">
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs uppercase tracking-widest"
                        style="color:#5a7494;font-family:'JetBrains Mono', monospace;">
                        <?= htmlspecialchars($field1['name'] ?? 'E-Mail'); ?>
                    </label>
                    <div class="flex items-center gap-3 px-4 py-3.5 rounded-lg border focus-within:ring-2 focus-within:ring-cyan-500/30"
                        style="
                            background:rgba(6,10,15,.6);
                            border-color:rgba(0,200,255,.12);">
                        <span style="color:#5a7494;"><?= htmlspecialchars($field1['icon'] ?? ''); ?></span>
                        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>"
                            placeholder="<?= htmlspecialchars($field1['placeholder'] ?? 'support@henz-software.com'); ?>"
                            autocomplete="email"
                            class="bg-transparent outline-none w-full text-sm placeholder:text-[#2d4258]"
                            style="color:#e8edf5; font-family:'JetBrains Mono', monospace;">
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs uppercase tracking-widest"
                            style="color:#5a7494;font-family:'JetBrains Mono', monospace;">
                            <?= htmlspecialchars($field2['name'] ?? 'Passwort'); ?>
                        </label>
                        <a href="/passwort-vergessen" class="text-xs transition-colors duration-200 hover:text-cyan-400"
                            style="color:#5a7494;font-family:'JetBrains Mono', monospace;">
                            <?= htmlspecialchars($forgetPassword ?? 'Vergessen?'); ?>
                        </a>
                    </div>
                    <div class="flex items-center gap-3 px-4 py-3.5 rounded-lg border focus-within:ring-2 focus-within:ring-cyan-500/30"
                        style="background:rgba(6,10,15,.6); border-color:rgba(0,200,255,.12);">
                        <span style="color:#5a7494;"><?= htmlspecialchars($field2['icon'] ?? ''); ?></span>
                        <input id="password" name="password" type="password"
                            placeholder="<?= htmlspecialchars($field2['placeholder'] ?? '••••••••••••'); ?>"
                            autocomplete="current-password"
                            class="bg-transparent outline-none w-full text-sm flex-1 placeholder:text-[#2d4258]"
                            style="color:#e8edf5; font-family:'JetBrains Mono', monospace;">
                        <button id="togglePassword" type="button"
                            class="text-[#5a7494] hover:text-cyan-400 transition-colors">👁
                        </button>
                    </div>
                </div>

                <?php if (!empty($errorMessage)): ?>

                    <div class="flex items-center gap-2.5 px-4 py-3 rounded-lg border text-sm"
                        style="color:#ff6b7a; border-color:rgba(255,71,87,.25); background:rgba(255,71,87,.06);">
                        <span>⚠</span>
                        <span style="font-family:'JetBrains Mono', monospace;font-size:12px;">
                            <?= htmlspecialchars($errorMessage) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <button id="loginButton" type="submit"
                    class="relative w-full py-3.5 rounded-lg font-bold text-sm transition-all duration-200 mt-1 overflow-hidden"
                    style="background:#00c8ff; color:#060a0f; font-family:'JetBrains Mono', monospace;">
                    <span class="button-content flex items-center justify-center gap-2">
                        <?= htmlspecialchars($loginButton ?? 'Anmelden'); ?>
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M5 12h14M13 5l7 7-7 7" stroke-width="2" />
                        </svg>
                    </span>
                </button>

                <div class="flex items-center gap-3 my-7">
                    <div class="flex-1 h-px" style="background:rgba(0,200,255,.08);"></div>
                    <span class="text-xs" style="color:#2d4258;font-family:'JetBrains Mono', monospace;">oder</span>
                    <div class="flex-1 h-px" style="background:rgba(0,200,255,.08);"></div>
                </div>

                <button type="button" class="w-full h-14 rounded-lg border transition-all duration-200
           flex items-center justify-center gap-3
           hover:text-cyan-400 hover:border-cyan-400/30" style="
        color:#8a9db5;
        border-color:rgba(0,200,255,.12);
        background:rgba(0,200,255,.03);
        font-family:'JetBrains Mono', monospace;
    ">

                    <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3 c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                    </svg>
                    <span><?= htmlspecialchars($ssoButton ?? 'SSO'); ?></span>

                </button>

                <p class="text-center text-xs mt-8" style="color:#2d4258; font-family:'JetBrains Mono', monospace;">
                    Nur für autorisierte Mitarbeiter · <?= htmlspecialchars($slug ?? 'henz-software'); ?>.com © <?= date('Y'); ?>
                </p>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('togglePassword').addEventListener('click', function () {
        const input = document.getElementById('password');

        if (input.type === 'password') {
            input.type = 'text';
            this.textContent = '🙈';
        } else {
            input.type = 'password';
            this.textContent = '👁';
        }
    });
    document.getElementById('loginForm').addEventListener('submit', function () {

        const button = document.getElementById('loginButton');

        button.disabled = true;

        button.style.background = "rgba(0,200,255,0.5)";
        button.style.cursor = "not-allowed";

        button.innerHTML = `
        <span class="flex items-center justify-center gap-2">
            <span class="spinner"></span>
            Authentifizierung…
        </span>
    `;
    });
</script>