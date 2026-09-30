<?php
use CoyshCRM\Services\SegmentEvaluator;
$isDynamic=$segment['segment_type']==='dynamic';
$reasons=[];foreach($members as $m)if($m['reason']!==null)$reasons[$m['reason']]=($reasons[$m['reason']]??0)+1;
$blocked=array_sum($reasons);$sendable=count($members)-$blocked;
$ruleText=[];
foreach($rules as $rule){
    if(!is_array($rule))continue;
    $operator=(string)($rule['operator']??'equals');
    $text=(SegmentEvaluator::FIELDS[$rule['field']??'']??(string)($rule['field']??'')).' '.(SegmentEvaluator::OPERATORS[$operator]??$operator);
    if(!in_array($operator,['is_empty','is_not_empty','is_true','is_false'],true))$text.=' "'.($rule['value']??'').'"';
    $ruleText[]=$text;
}
?>
<div class="space-y-5"><?php include __DIR__.'/_nav.php'; ?>
<div class="flex flex-wrap gap-3 items-start justify-between">
    <div><h1 class="text-xl font-semibold"><?= e($segment['name']) ?></h1><p class="text-sm text-slate-500 mt-1"><span class="text-xs uppercase text-slate-400 mr-2"><?= $isDynamic?'Dynamic':'Manual' ?></span><?= e($segment['description']??'') ?></p></div>
    <div class="flex gap-2"><a href="/email/segments" class="px-3 py-2 border rounded text-sm">All segments</a><a href="/email/segments/<?= (int)$segment['id'] ?>/edit" class="px-3 py-2 bg-accent-600 text-white rounded text-sm">Edit segment</a></div>
</div>
<?php if($error): ?><div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 text-sm">This segment could not be evaluated: <?= e($error) ?></div><?php endif ?>
<div class="grid sm:grid-cols-3 gap-4">
    <div class="bg-white border rounded-lg p-4"><p class="text-xs uppercase text-slate-400">In segment</p><p class="text-2xl font-semibold mt-1"><?= count($members) ?></p></div>
    <div class="bg-white border rounded-lg p-4"><p class="text-xs uppercase text-slate-400">Will receive</p><p class="text-2xl font-semibold mt-1 text-green-700"><?= $sendable ?></p></div>
    <div class="bg-white border rounded-lg p-4"><p class="text-xs uppercase text-slate-400">Can't be sent to</p><p class="text-2xl font-semibold mt-1"><?= $blocked ?></p><?php if($reasons): ?><div class="flex flex-wrap gap-2 mt-2"><?php foreach($reasons as $reason=>$count): ?><span class="text-xs bg-amber-50 text-amber-800 rounded px-2 py-1"><?= (int)$count ?> <?= e($reason) ?></span><?php endforeach ?></div><?php endif ?></div>
</div>
<?php if($isDynamic): ?><div class="bg-white border rounded-lg p-4 text-sm"><h2 class="font-medium mb-1">Rules</h2><?php if($ruleText): ?><p class="text-slate-600"><?= e(implode($segment['match_type']==='any'?' OR ':' AND ',$ruleText)) ?></p><?php else: ?><p class="text-slate-400">No rules — every active contact matches.</p><?php endif ?></div><?php endif ?>
<div class="bg-white border rounded-lg overflow-hidden">
    <div class="p-4 flex flex-wrap gap-2 items-center justify-between border-b"><h2 class="font-medium">People in this segment</h2><div class="flex flex-wrap gap-2 items-center"><span id="member-count" class="text-xs text-slate-400"></span><input id="member-filter" type="search" placeholder="Search name, email, company…" class="border rounded px-3 py-2 text-sm w-64 max-w-full"><select id="member-status" class="border rounded px-3 py-2 text-sm"><option value="">Everyone</option><option value="1">Will receive</option><option value="0">Can't be sent to</option></select></div></div>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="text-left px-4 py-2">Contact</th><th class="text-left px-4 py-2">Company / clients</th><th class="text-left px-4 py-2">Eligibility</th><th class="text-left px-4 py-2">Sending</th><?php if($isDynamic): ?><th class="text-left px-4 py-2">Added by</th><?php endif ?></tr></thead><tbody class="divide-y">
    <?php if(!$members): ?><tr><td colspan="5" class="px-4 py-6 text-slate-400">Nobody is in this segment yet. <a href="/email/segments/<?= (int)$segment['id'] ?>/edit" class="text-accent-600">Add people</a></td></tr><?php endif ?>
    <?php foreach($members as $c): $clients=$clientNames[(int)$c['id']]??''; ?>
    <tr data-member-row data-sendable="<?= $c['reason']===null?'1':'0' ?>" data-search="<?= e(strtolower(($c['name']??'').' '.$c['email'].' '.($c['company_name']??'').' '.$clients)) ?>">
        <td class="px-4 py-3"><a href="/email/contacts/<?= (int)$c['id'] ?>/edit" class="font-medium text-accent-600"><?= e($c['name']?:$c['email']) ?></a><div class="text-xs text-slate-400"><?= e($c['email']) ?></div></td>
        <td class="px-4 py-3 text-slate-600"><?= e($c['company_name']?:$clients?:'—') ?><?php if($c['company_name']&&$clients&&$clients!==$c['company_name']): ?><div class="text-xs text-slate-400"><?= e($clients) ?></div><?php endif ?></td>
        <td class="px-4 py-3"><?php include __DIR__.'/_eligibility.php'; ?></td>
        <td class="px-4 py-3"><?php if($c['reason']===null): ?><span class="text-green-700">Will receive</span><?php else: ?><span class="text-xs bg-amber-50 text-amber-800 rounded px-2 py-1"><?= e($c['reason']) ?></span><?php endif ?></td>
        <?php if($isDynamic): ?><td class="px-4 py-3 text-slate-500"><?= $c['source']==='rule'?'Rule':'Added manually' ?></td><?php endif ?>
    </tr>
    <?php endforeach ?>
    <tr id="member-none" style="display:none"><td colspan="5" class="px-4 py-6 text-slate-400">No one matches that filter.</td></tr>
    </tbody></table></div>
</div>
<?php if($removed): ?><div class="bg-white border rounded-lg p-4"><h2 class="font-medium mb-1">Held out of this segment</h2><p class="text-xs text-slate-400 mb-3">These contacts match the rules but have been excluded by hand.</p><ul class="text-sm text-slate-500 space-y-1"><?php foreach($removed as $c): ?><li><?= e($c['name']?:'Unnamed') ?> <span class="text-slate-400">— <?= e($c['email']) ?></span></li><?php endforeach ?></ul></div><?php endif ?>
</div>
<script>
(function(){
const rows=[...document.querySelectorAll('[data-member-row]')],search=document.getElementById('member-filter'),status=document.getElementById('member-status'),count=document.getElementById('member-count'),none=document.getElementById('member-none');
function apply(){const term=search.value.trim().toLowerCase();let shown=0;rows.forEach(row=>{const ok=(row.dataset.search||'').includes(term)&&(status.value===''||row.dataset.sendable===status.value);row.style.display=ok?'':'none';if(ok)shown++});count.textContent=shown===rows.length?'':`Showing ${shown} of ${rows.length}`;none.style.display=rows.length&&!shown?'':'none'}
search.oninput=apply;status.onchange=apply;apply();
})();
</script>
