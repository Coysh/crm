<h1 class="text-base font-semibold text-slate-900 mb-1">Create your account</h1>
<p class="text-sm text-slate-500 mb-4">First-time setup. Choose credentials and enrol two-factor authentication.</p>

<form method="POST" action="/setup" class="space-y-4">
    <?= csrfField() ?>
    <div>
        <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required
               value="<?= e($username ?? '') ?>"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>
    <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="12"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        <p class="text-xs text-slate-400 mt-1">At least 12 characters.</p>
    </div>
    <div>
        <label for="password_confirm" class="block text-sm font-medium text-slate-700 mb-1">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>

    <div class="border-t border-slate-200 pt-4">
        <p class="text-sm font-medium text-slate-700 mb-2">Two-factor authentication</p>
        <p class="text-xs text-slate-500 mb-3">Scan this with Google Authenticator, 1Password, Authy, etc.</p>
        <div class="flex justify-center mb-3">
            <div id="qr" class="p-2 bg-white border border-slate-200 rounded"></div>
        </div>
        <p class="text-xs text-slate-500 text-center mb-3">
            Or enter this key manually:<br>
            <code class="text-slate-700 break-all"><?= e($secret) ?></code>
        </p>
        <label for="code" class="block text-sm font-medium text-slate-700 mb-1">Enter the 6-digit code to confirm</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
               required maxlength="6"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm tracking-widest font-mono text-center focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>

    <button type="submit"
            class="w-full bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium rounded px-3 py-2 transition-colors">
        Create account
    </button>
</form>

<script src="/js/qrcode.min.js"></script>
<script>
(function () {
    var uri = <?= json_encode($otpauthUri, JSON_UNESCAPED_SLASHES) ?>;
    new QRCode(document.getElementById('qr'), { text: uri, width: 180, height: 180 });
})();
</script>
