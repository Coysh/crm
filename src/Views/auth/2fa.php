<h1 class="text-base font-semibold text-slate-900 mb-1">Two-factor authentication</h1>
<p class="text-sm text-slate-500 mb-4">Enter the 6-digit code from your authenticator app.</p>

<form method="POST" action="/login/2fa" class="space-y-4">
    <?= csrfField() ?>
    <div>
        <label for="code" class="block text-sm font-medium text-slate-700 mb-1">Authentication code</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
               autofocus required maxlength="6"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm tracking-widest font-mono text-center focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>
    <button type="submit"
            class="w-full bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium rounded px-3 py-2 transition-colors">
        Verify
    </button>
</form>

<form method="POST" action="/logout" class="mt-3">
    <?= csrfField() ?>
    <button type="submit" class="w-full text-xs text-slate-400 hover:text-slate-600">Cancel and sign out</button>
</form>
