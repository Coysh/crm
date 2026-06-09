<h1 class="text-base font-semibold text-slate-900 mb-4">Sign in</h1>

<form method="POST" action="/login" class="space-y-4">
    <?= csrfField() ?>
    <div>
        <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" autofocus required
               value="<?= e($username ?? '') ?>"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>
    <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
    </div>
    <button type="submit"
            class="w-full bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium rounded px-3 py-2 transition-colors">
        Continue
    </button>
</form>
