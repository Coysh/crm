<?php
/**
 * Post-consent hand-back page. A direct 302 off the form POST gets blocked by
 * the consent page's CSP form-action 'self' (browsers check the redirect chain
 * of a form submission), so we land here and navigate via script instead —
 * script/link navigation is not subject to form-action.
 * Expects: $redirectUrl (already validated against the client's registered URIs).
 */
?>
<div class="space-y-4 text-center">
    <h1 class="text-base font-semibold text-slate-800"><?= !empty($denied) ? 'Access denied' : 'Access approved' ?></h1>
    <p class="text-sm text-slate-500">Returning you to the connecting app…</p>
    <p>
        <a href="<?= e($redirectUrl) ?>" class="text-sm text-accent-600 hover:underline">Continue</a>
        <span class="text-xs text-slate-400 block mt-1">if you're not redirected automatically</span>
    </p>
    <script>window.location.replace(<?= json_encode($redirectUrl) ?>);</script>
</div>
