<div class="max-w-2xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">FreeAgent Settings</h1>

    <!-- Connection status banner -->
    <?php if ($connected): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm text-green-800">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <strong>Connected to FreeAgent.</strong>
                <?php if (!empty($faCfg['last_sync_at'])): ?>
                    Last sync: <?= formatDate($faCfg['last_sync_at']) ?>
                <?php else: ?>
                    No sync run yet.
                <?php endif ?>
            </div>
            <form method="POST" action="/settings/freeagent/disconnect">
                <button type="submit" onclick="return confirm('Disconnect FreeAgent? This will clear stored tokens.')"
                        class="text-sm text-red-600 hover:text-red-800 font-medium">Disconnect</button>
            </form>
        </div>
    <?php else: ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            Not connected. Enter your FreeAgent OAuth credentials below, then click Connect.
        </div>
    <?php endif ?>

    <!-- Step-by-step registration guide -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">How to register a FreeAgent OAuth app</h2>
        <ol class="text-sm text-slate-600 space-y-2 list-decimal list-inside">
            <li>Go to <strong>https://dev.freeagent.com/</strong> and sign in with your FreeAgent account.</li>
            <li>Click <strong>Create a new app</strong>.</li>
            <li>Set the OAuth Redirect URI to:<br>
                <code class="mt-1 inline-block bg-slate-100 text-slate-700 px-2 py-0.5 rounded font-mono text-xs">
                    <?= e($redirectUri ?? 'http://localhost:8080/settings/freeagent/callback') ?>
                </code>
            </li>
            <li>Copy the <strong>OAuth client identifier</strong> and <strong>OAuth client secret</strong> into the form below.</li>
            <li>Choose <strong>Sandbox</strong> mode to test with demo data, or leave unchecked for production.</li>
        </ol>
    </div>

    <!-- Credentials form -->
    <form method="POST" action="/settings/freeagent" class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">

        <div>
            <label for="client_id" class="block text-sm font-medium text-slate-700 mb-1">
                OAuth Client ID <span class="text-red-500">*</span>
            </label>
            <input type="text" id="client_id" name="client_id"
                   value="<?= e($faCfg['client_id'] ?? '') ?>"
                   placeholder="your-app-client-id"
                   class="w-full border <?= isset($errors['client_id']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['client_id'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['client_id']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="client_secret" class="block text-sm font-medium text-slate-700 mb-1">
                OAuth Client Secret <span class="text-red-500">*</span>
            </label>
            <input type="password" id="client_secret" name="client_secret"
                   value="<?= e($faCfg['client_secret'] ?? '') ?>"
                   placeholder="••••••••••••••••"
                   class="w-full border <?= isset($errors['client_secret']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['client_secret'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['client_secret']) ?></p>
            <?php endif ?>
        </div>

        <div class="flex items-center gap-3">
            <input type="checkbox" id="use_sandbox" name="use_sandbox" value="1"
                   <?= ($faCfg['use_sandbox'] ?? 0) ? 'checked' : '' ?>
                   class="rounded border-slate-300 text-accent-600">
            <label for="use_sandbox" class="text-sm text-slate-700">
                Use Sandbox API <span class="text-slate-400">(https://api.sandbox.freeagent.com)</span>
            </label>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                Save Credentials
            </button>
            <?php if ($faCfg && !empty($faCfg['client_id']) && !$connected): ?>
                <a href="/settings/freeagent/connect"
                   class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded hover:bg-green-700">
                    Connect to FreeAgent →
                </a>
            <?php elseif ($connected): ?>
                <a href="/settings/freeagent/connect"
                   class="px-4 py-2 border border-slate-300 text-slate-700 text-sm font-medium rounded hover:bg-slate-50">
                    Re-authorise
                </a>
            <?php endif ?>
        </div>

    </form>

    <!-- Navigation to sub-pages (only when connected) -->
    <?php if ($connected): ?>
        <div class="grid grid-cols-2 gap-4">
            <a href="/settings/freeagent/contacts"
               class="bg-white border border-slate-200 rounded-lg p-4 hover:border-accent-300 hover:shadow-sm transition-all">
                <p class="text-sm font-semibold text-slate-700">Contact Mapping</p>
                <p class="text-xs text-slate-400 mt-0.5">Link FreeAgent contacts to CRM clients</p>
            </a>
            <a href="/settings/freeagent/categories"
               class="bg-white border border-slate-200 rounded-lg p-4 hover:border-accent-300 hover:shadow-sm transition-all">
                <p class="text-sm font-semibold text-slate-700">Category Mapping</p>
                <p class="text-xs text-slate-400 mt-0.5">Map FreeAgent categories to CRM categories</p>
            </a>
        </div>
    <?php endif ?>

</div>
