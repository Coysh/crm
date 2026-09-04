<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\CampaignDispatcher;
use CoyshCRM\Services\EmailRenderer;
use CoyshCRM\Services\MailgunTransport;
use CoyshCRM\Services\Secrets;
use CoyshCRM\Services\SegmentEvaluator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class EmailController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $stats = [
            'contacts' => (int)$this->db->query("SELECT COUNT(*) FROM marketing_contacts WHERE status='active'")->fetchColumn(),
            'eligible' => (int)$this->db->query("SELECT COUNT(*) FROM marketing_contacts mc WHERE status='active' AND eligibility_basis<>'unknown' AND unsubscribed_at IS NULL AND NOT EXISTS (SELECT 1 FROM marketing_suppressions ms WHERE ms.email_norm=mc.email_norm AND ms.cleared_at IS NULL)")->fetchColumn(),
            'segments' => (int)$this->db->query('SELECT COUNT(*) FROM marketing_segments')->fetchColumn(),
            'campaigns' => (int)$this->db->query('SELECT COUNT(*) FROM email_campaigns')->fetchColumn(),
        ];
        $campaigns = $this->db->query("SELECT c.*, s.name segment_name,
            (SELECT COUNT(*) FROM email_campaign_recipients r WHERE r.campaign_id=c.id AND r.status='delivered') delivered
            FROM email_campaigns c LEFT JOIN marketing_segments s ON s.id=c.segment_id ORDER BY c.id DESC LIMIT 12")->fetchAll();
        render('email.index', compact('stats', 'campaigns'), 'Email Marketing');
    }

    public function contacts(): void
    {
        $status = $_GET['status'] ?? 'all';
        $search = trim($_GET['search'] ?? '');
        $sql = "SELECT mc.*, group_concat(c.name, ', ') client_names,
                (SELECT reason FROM marketing_suppressions ms WHERE ms.email_norm=mc.email_norm AND ms.cleared_at IS NULL) suppression_reason
                FROM marketing_contacts mc
                LEFT JOIN marketing_contact_clients mcc ON mcc.contact_id=mc.id
                LEFT JOIN clients c ON c.id=mcc.client_id WHERE 1=1";
        $params = [];
        if ($status === 'eligible') $sql .= " AND mc.status='active' AND mc.eligibility_basis<>'unknown' AND mc.unsubscribed_at IS NULL AND NOT EXISTS (SELECT 1 FROM marketing_suppressions ms WHERE ms.email_norm=mc.email_norm AND ms.cleared_at IS NULL)";
        elseif ($status === 'unknown') $sql .= " AND mc.eligibility_basis='unknown'";
        elseif ($status === 'suppressed') $sql .= " AND (mc.unsubscribed_at IS NOT NULL OR EXISTS (SELECT 1 FROM marketing_suppressions ms WHERE ms.email_norm=mc.email_norm AND ms.cleared_at IS NULL))";
        elseif ($status === 'archived') $sql .= " AND mc.status='archived'";
        if ($search !== '') { $sql .= " AND (mc.name LIKE ? OR mc.email LIKE ? OR mc.company_name LIKE ?)"; $like = "%$search%"; $params = [$like, $like, $like]; }
        $sql .= ' GROUP BY mc.id ORDER BY lower(mc.email)';
        $stmt = $this->db->prepare($sql); $stmt->execute($params); $contacts = $stmt->fetchAll();
        render('email.contacts', compact('contacts', 'status', 'search'), 'Email Contacts');
    }

    public function contactForm(?int $id = null): void
    {
        $contact = $id ? $this->row('marketing_contacts', $id) : ['status'=>'active','eligibility_basis'=>'unknown'];
        if ($id && !$contact) { $this->notFound(); return; }
        $clients = $this->db->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
        $linked = $id ? $this->column('SELECT client_id FROM marketing_contact_clients WHERE contact_id=?', [$id]) : [];
        $history = $id ? $this->all('SELECT * FROM marketing_consent_events WHERE contact_id=? ORDER BY occurred_at DESC', [$id]) : [];
        render('email.contact_form', compact('contact','clients','linked','history'), $id ? 'Edit Contact' : 'Add Contact');
    }

    public function saveContact(?int $id = null): void
    {
        $this->csrf('/email/contacts');
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a valid email address.'); redirect($id ? "/email/contacts/$id/edit" : '/email/contacts/create'); }
        $basis = in_array($_POST['eligibility_basis'] ?? '', ['unknown','consent','soft_opt_in','corporate_b2b'], true) ? $_POST['eligibility_basis'] : 'unknown';
        $data = [trim($_POST['name'] ?? ''), $email, strtolower($email), trim($_POST['company_name'] ?? ''), ($_POST['status'] ?? '') === 'archived' ? 'archived' : 'active', $basis,
            $basis === 'unknown' ? null : ($_POST['eligibility_at'] ?: date('Y-m-d H:i:s')), trim($_POST['eligibility_source'] ?? ''), trim($_POST['eligibility_notes'] ?? '')];
        try {
            $this->db->beginTransaction();
            if ($id) {
                $this->db->prepare('UPDATE marketing_contacts SET name=?,email=?,email_norm=?,company_name=?,status=?,eligibility_basis=?,eligibility_at=?,eligibility_source=?,eligibility_notes=?,updated_at=datetime(\'now\') WHERE id=?')->execute([...$data,$id]);
            } else {
                $this->db->prepare('INSERT INTO marketing_contacts (name,email,email_norm,company_name,status,eligibility_basis,eligibility_at,eligibility_source,eligibility_notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
                $id = (int)$this->db->lastInsertId();
            }
            $this->db->prepare('DELETE FROM marketing_contact_clients WHERE contact_id=?')->execute([$id]);
            $link = $this->db->prepare('INSERT INTO marketing_contact_clients (contact_id,client_id,is_primary) VALUES (?,?,0)');
            foreach (array_unique(array_map('intval',(array)($_POST['client_ids'] ?? []))) as $clientId) if ($clientId) $link->execute([$id,$clientId]);
            $oldBasis = $_POST['original_basis'] ?? null;
            if ($basis !== $oldBasis) {
                $this->db->prepare('INSERT INTO marketing_consent_events (contact_id,event_type,basis,source,notes,user_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$id, $basis === 'unknown' ? 'eligibility_removed' : 'eligibility_recorded', $basis, trim($_POST['eligibility_source'] ?? ''), trim($_POST['eligibility_notes'] ?? ''), currentUser()['id'] ?? null]);
            }
            $this->db->commit();
            flash('success', 'Marketing contact saved.');
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); flash('error', str_contains($e->getMessage(),'UNIQUE') ? 'That email address already exists.' : $e->getMessage()); }
        redirect("/email/contacts/$id/edit");
    }

    public function suppressContact(int $id): void
    {
        $this->csrf("/email/contacts/$id/edit");
        $contact = $this->row('marketing_contacts',$id); if (!$contact) { $this->notFound(); return; }
        $reason = in_array($_POST['reason'] ?? '', ['manual','unsubscribe'], true) ? $_POST['reason'] : 'manual';
        $this->db->prepare("INSERT INTO marketing_suppressions (email_norm,reason,source,contact_id) VALUES (?,?,'crm',?) ON CONFLICT(email_norm) DO UPDATE SET reason=excluded.reason,source='crm',contact_id=excluded.contact_id,created_at=datetime('now'),cleared_at=NULL")
            ->execute([$contact['email_norm'],$reason,$id]);
        if ($reason === 'unsubscribe') $this->db->prepare("UPDATE marketing_contacts SET unsubscribed_at=datetime('now') WHERE id=?")->execute([$id]);
        $this->db->prepare("INSERT INTO marketing_consent_events(contact_id,event_type,basis,source,notes,user_id) VALUES (?,'suppressed',?,'crm',?,?)")
            ->execute([$id,$contact['eligibility_basis'],$reason,currentUser()['id']??null]);
        flash('success','Contact suppressed.'); redirect("/email/contacts/$id/edit");
    }

    public function bulkEligibility(): void
    {
        $this->csrf('/email/contacts');$ids=array_values(array_filter(array_unique(array_map('intval',(array)($_POST['contact_ids']??[])))));$basis=$_POST['basis']??'unknown';
        if(!$ids||!in_array($basis,['unknown','consent','soft_opt_in','corporate_b2b'],true)){flash('error','Choose contacts and a valid eligibility basis.');redirect('/email/contacts');}
        $source=trim($_POST['source']??'');$notes=trim($_POST['notes']??'');if($basis!=='unknown'&&$source===''){flash('error','An eligibility source is required for bulk review.');redirect('/email/contacts');}
        $update=$this->db->prepare('UPDATE marketing_contacts SET eligibility_basis=?,eligibility_at=?,eligibility_source=?,eligibility_notes=?,updated_at=datetime(\'now\') WHERE id=?');
        $event=$this->db->prepare('INSERT INTO marketing_consent_events(contact_id,event_type,basis,source,notes,user_id) VALUES (?,?,?,?,?,?)');
        $reviewed=0;
        try {
            $this->db->beginTransaction();
            foreach($ids as $id){
                $update->execute([$basis,$basis==='unknown'?null:date('Y-m-d H:i:s'),$source,$notes,$id]);
                if($update->rowCount()===0)continue;
                $event->execute([$id,$basis==='unknown'?'eligibility_removed':'eligibility_recorded',$basis,$source,$notes,currentUser()['id']??null]);
                $reviewed++;
            }
            $this->db->commit();
            flash('success',$reviewed.' contact(s) reviewed. Existing suppressions were preserved.');
        } catch (\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            flash('error','The bulk review could not be saved: '.$e->getMessage());
        }
        redirect('/email/contacts');
    }

    public function clearSuppression(int $id): void
    {
        $this->csrf("/email/contacts/$id/edit");
        $contact = $this->row('marketing_contacts',$id); if (!$contact) { $this->notFound(); return; }
        if (($contact['eligibility_basis'] ?? 'unknown') === 'unknown') { flash('error','Record a valid eligibility basis before clearing suppression.'); redirect("/email/contacts/$id/edit"); }
        $this->db->prepare('UPDATE marketing_suppressions SET cleared_at=datetime(\'now\'),cleared_by=? WHERE email_norm=? AND cleared_at IS NULL')->execute([currentUser()['id'] ?? null,$contact['email_norm']]);
        $this->db->prepare('UPDATE marketing_contacts SET unsubscribed_at=NULL,updated_at=datetime(\'now\') WHERE id=?')->execute([$id]);
        $this->db->prepare("INSERT INTO marketing_consent_events (contact_id,event_type,basis,source,notes,user_id) VALUES (?,'resubscribed',?,'crm',?,?)")
            ->execute([$id,$contact['eligibility_basis'],trim($_POST['notes'] ?? ''),currentUser()['id'] ?? null]);
        flash('success','Suppression cleared and resubscription recorded.'); redirect("/email/contacts/$id/edit");
    }

    public function csvPreview(): void
    {
        $this->csrf('/email/contacts');
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2_000_000) { flash('error','Choose a CSV file up to 2 MB.'); redirect('/email/contacts'); }
        $fh = fopen($file['tmp_name'],'r'); $header = array_map(fn($v)=>strtolower(trim((string)$v)), fgetcsv($fh) ?: []); $rows=[];
        while (($values=fgetcsv($fh))!==false && count($rows)<5000) { $row=[]; foreach($header as $i=>$key) $row[$key]=trim((string)($values[$i]??'')); $row['_valid']=filter_var($row['email']??'',FILTER_VALIDATE_EMAIL)!==false; $rows[]=$row; }
        fclose($fh); $_SESSION['email_csv_rows']=$rows;
        render('email.csv_preview',compact('rows'),'Preview Contact Import');
    }

    public function csvCommit(): void
    {
        $this->csrf('/email/contacts'); $rows=$_SESSION['email_csv_rows']??[]; unset($_SESSION['email_csv_rows']); $added=$updated=$skipped=0;
        foreach($rows as $row) {
            if (empty($row['_valid'])) { $skipped++; continue; }
            $email=trim($row['email']); $norm=strtolower($email); $basis=in_array($row['basis']??'', ['consent','soft_opt_in','corporate_b2b'],true)?$row['basis']:'unknown';
            $existing=$this->one('SELECT * FROM marketing_contacts WHERE email_norm=?',[$norm]);
            if ($existing) {
                $this->db->prepare('UPDATE marketing_contacts SET name=CASE WHEN ?<>\'\' THEN ? ELSE name END,company_name=CASE WHEN ?<>\'\' THEN ? ELSE company_name END,updated_at=datetime(\'now\') WHERE id=?')
                    ->execute([$row['name']??'',$row['name']??'',$row['company']??'',$row['company']??'',$existing['id']]); $id=(int)$existing['id']; $updated++;
                if ($existing['eligibility_basis']==='unknown' && $basis!=='unknown') {
                    $basisAt=($row['basis_date']??'')?:date('Y-m-d H:i:s');$basisSource=($row['basis_source']??'')?:'CSV import';
                    $this->db->prepare('UPDATE marketing_contacts SET eligibility_basis=?,eligibility_at=?,eligibility_source=?,updated_at=datetime(\'now\') WHERE id=?')->execute([$basis,$basisAt,$basisSource,$id]);
                    $this->db->prepare("INSERT INTO marketing_consent_events(contact_id,event_type,basis,source,notes,user_id) VALUES (?,'eligibility_recorded',?,?,?,?)")->execute([$id,$basis,$basisSource,'CSV import',currentUser()['id']??null]);
                }
            } else {
                $basisAt=$basis==='unknown'?null:(($row['basis_date']??'')?:date('Y-m-d H:i:s'));
                $basisSource=$basis==='unknown'?'':(($row['basis_source']??'')?:'CSV import');
                $this->db->prepare('INSERT INTO marketing_contacts (name,email,email_norm,company_name,eligibility_basis,eligibility_at,eligibility_source) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$row['name']??'',$email,$norm,$row['company']??'',$basis,$basisAt,$basisSource]); $id=(int)$this->db->lastInsertId(); $added++;
                if($basis!=='unknown')$this->db->prepare("INSERT INTO marketing_consent_events(contact_id,event_type,basis,source,notes,user_id) VALUES (?,'eligibility_recorded',?,?,?,?)")->execute([$id,$basis,$basisSource,'CSV import',currentUser()['id']??null]);
            }
            $clientId=(int)($row['client_id']??0); if (!$clientId && !empty($row['client'])) { $matches=$this->column('SELECT id FROM clients WHERE lower(name)=lower(?)',[$row['client']]); if(count($matches)===1)$clientId=(int)$matches[0]; }
            if($clientId)$this->db->prepare('INSERT OR IGNORE INTO marketing_contact_clients (contact_id,client_id) VALUES (?,?)')->execute([$id,$clientId]);
        }
        flash('success',"Imported $added new and updated $updated contacts; skipped $skipped invalid rows."); redirect('/email/contacts');
    }

    public function csvExport(): void
    {
        $rows=$this->all("SELECT mc.*, group_concat(c.name, '|') clients,(SELECT reason FROM marketing_suppressions ms WHERE ms.email_norm=mc.email_norm AND ms.cleared_at IS NULL) suppression FROM marketing_contacts mc LEFT JOIN marketing_contact_clients mcc ON mcc.contact_id=mc.id LEFT JOIN clients c ON c.id=mcc.client_id GROUP BY mc.id ORDER BY mc.email");
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="marketing-contacts.csv"'); $out=fopen('php://output','w'); fputcsv($out,['email','name','company','status','basis','basis_date','basis_source','clients','suppression']); foreach($rows as $r)fputcsv($out,[$r['email'],$r['name'],$r['company_name'],$r['status'],$r['eligibility_basis'],$r['eligibility_at'],$r['eligibility_source'],$r['clients'],$r['suppression']]); fclose($out); exit;
    }

    public function segments(): void
    {
        $segments=$this->db->query("SELECT s.*,(SELECT COUNT(*) FROM marketing_segment_members m WHERE m.segment_id=s.id AND m.action='include') manual_count FROM marketing_segments s ORDER BY lower(name)")->fetchAll();
        foreach($segments as &$segment){try{$segment['evaluated_count']=count((new SegmentEvaluator($this->db))->contacts((int)$segment['id']));}catch(\Throwable){$segment['evaluated_count']=0;}} unset($segment);
        render('email.segments',compact('segments'),'Email Segments');
    }

    public function segmentForm(?int $id=null): void
    {
        $segment=$id?$this->row('marketing_segments',$id):['segment_type'=>'manual','match_type'=>'all','rules_json'=>'[]']; if($id&&!$segment){$this->notFound();return;}
        $contacts=$this->db->query('SELECT id,name,email FROM marketing_contacts ORDER BY lower(email)')->fetchAll();
        $members=$id?$this->all('SELECT contact_id,action FROM marketing_segment_members WHERE segment_id=?',[$id]):[];
        render('email.segment_form',compact('segment','contacts','members'),$id?'Edit Segment':'Add Segment');
    }

    public function saveSegment(?int $id=null): void
    {
        $this->csrf('/email/segments'); $name=trim($_POST['name']??''); if(!$name){flash('error','Segment name is required.');redirect($id?"/email/segments/$id/edit":'/email/segments/create');}
        $type=($_POST['segment_type']??'')==='dynamic'?'dynamic':'manual'; $match=($_POST['match_type']??'')==='any'?'any':'all'; $rules=json_decode($_POST['rules_json']??'[]',true); if(!is_array($rules))$rules=[];
        $wasNew=$id===null;
        try { $this->db->beginTransaction(); foreach($rules as $rule) { /* validation occurs through evaluation after save */ if(!isset($rule['field'],$rule['operator']))throw new RuntimeException('Invalid segment rule.'); }
            if($id)$this->db->prepare('UPDATE marketing_segments SET name=?,description=?,segment_type=?,match_type=?,rules_json=?,updated_at=datetime(\'now\') WHERE id=?')->execute([$name,trim($_POST['description']??''),$type,$match,json_encode($rules),$id]);
            else{$this->db->prepare('INSERT INTO marketing_segments (name,description,segment_type,match_type,rules_json) VALUES (?,?,?,?,?)')->execute([$name,trim($_POST['description']??''),$type,$match,json_encode($rules)]);$id=(int)$this->db->lastInsertId();}
            $this->db->prepare('DELETE FROM marketing_segment_members WHERE segment_id=?')->execute([$id]);
            $insert=$this->db->prepare('INSERT INTO marketing_segment_members (segment_id,contact_id,action) VALUES (?,?,?)');
            $excludeIds=$type==='dynamic'?array_values(array_filter(array_unique(array_map('intval',(array)($_POST['exclude_ids']??[]))))):[];
            $includeIds=array_values(array_filter(array_unique(array_map('intval',(array)($_POST['include_ids']??[])))));
            $includeIds=array_values(array_diff($includeIds,$excludeIds));
            foreach($includeIds as $contactId)$insert->execute([$id,$contactId,'include']);
            foreach($excludeIds as $contactId)$insert->execute([$id,$contactId,'exclude']);
            (new SegmentEvaluator($this->db))->contacts($id); $this->db->commit(); flash('success','Segment saved.');
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();flash('error',$e->getMessage());if($wasNew)$id=null;}
        redirect($id?"/email/segments/$id/edit":'/email/segments/create');
    }

    public function templates(): void { $templates=$this->db->query('SELECT * FROM email_templates ORDER BY lower(name)')->fetchAll(); render('email.templates',compact('templates'),'Email Templates'); }

    public function templateForm(?int $id=null): void
    {
        $template=$id?$this->row('email_templates',$id):['status'=>'active','subject'=>'','preheader'=>'','content_json'=>json_encode($this->blankContent())]; if($id&&!$template){$this->notFound();return;}
        $assets=$this->db->query('SELECT * FROM email_assets ORDER BY id DESC')->fetchAll(); $emailBrand=$this->config(); $includeEmailBuilder=true;
        render('email.template_form',compact('template','assets','emailBrand','includeEmailBuilder'),$id?'Edit Template':'Add Template');
    }

    public function saveTemplate(?int $id=null): void
    {
        $this->csrf('/email/templates'); $name=trim($_POST['name']??''); $content=$this->validContent($_POST['content_json']??''); if(!$name){flash('error','Template name is required.');redirect($id?"/email/templates/$id/edit":'/email/templates/create');}
        $data=[$name,trim($_POST['description']??''),trim($_POST['subject']??''),trim($_POST['preheader']??''),$content,($_POST['status']??'')==='archived'?'archived':'active'];
        if($id)$this->db->prepare('UPDATE email_templates SET name=?,description=?,subject=?,preheader=?,content_json=?,status=?,updated_at=datetime(\'now\') WHERE id=?')->execute([...$data,$id]);
        else{$this->db->prepare('INSERT INTO email_templates (name,description,subject,preheader,content_json,status) VALUES (?,?,?,?,?,?)')->execute($data);$id=(int)$this->db->lastInsertId();}
        flash('success','Template saved.');redirect("/email/templates/$id/edit");
    }

    public function campaigns(): void { $campaigns=$this->db->query('SELECT ec.*,ms.name segment_name FROM email_campaigns ec LEFT JOIN marketing_segments ms ON ms.id=ec.segment_id ORDER BY ec.id DESC')->fetchAll(); render('email.campaigns',compact('campaigns'),'Email Campaigns'); }

    public function campaignForm(?int $id=null): void
    {
        $defaults=$this->config();
        $campaign=$id?$this->row('email_campaigns',$id):['status'=>'draft','subject'=>'','preheader'=>'','content_json'=>json_encode($this->blankContent()),'tracking_opens'=>(int)($defaults['tracking_opens']??1),'tracking_clicks'=>(int)($defaults['tracking_clicks']??1)]; if($id&&!$campaign){$this->notFound();return;}
        if($id&&$campaign['status']!=='draft'){redirect("/email/campaigns/$id");}
        if(!$id&&!empty($_GET['template'])){$t=$this->row('email_templates',(int)$_GET['template']);if($t)$campaign=array_merge($campaign,['template_id'=>$t['id'],'subject'=>$t['subject'],'preheader'=>$t['preheader'],'content_json'=>$t['content_json']]);}
        $segments=$this->db->query('SELECT id,name FROM marketing_segments ORDER BY name')->fetchAll(); $templates=$this->db->query("SELECT id,name FROM email_templates WHERE status='active' ORDER BY name")->fetchAll(); $assets=$this->db->query('SELECT * FROM email_assets ORDER BY id DESC')->fetchAll(); $emailBrand=$this->config(); $includeEmailBuilder=true;
        $audiencePreview=null;if(!empty($campaign['segment_id']))try{$audiencePreview=(new SegmentEvaluator($this->db))->audience((int)$campaign['segment_id']);}catch(\Throwable){}
        render('email.campaign_form',compact('campaign','segments','templates','assets','emailBrand','includeEmailBuilder','audiencePreview'),$id?'Edit Campaign':'Add Campaign');
    }

    public function saveCampaign(?int $id=null): void
    {
        $this->csrf('/email/campaigns'); if($id){$old=$this->row('email_campaigns',$id);if(!$old||$old['status']!=='draft'){flash('error','Only draft campaigns can be edited.');redirect("/email/campaigns/$id");}}
        $name=trim($_POST['name']??'');if(!$name){flash('error','Campaign name is required.');redirect($id?"/email/campaigns/$id/edit":'/email/campaigns/create');}
        $data=[$name,($_POST['segment_id']??'')!==''?(int)$_POST['segment_id']:null,($_POST['template_id']??'')!==''?(int)$_POST['template_id']:null,trim($_POST['subject']??''),trim($_POST['preheader']??''),$this->validContent($_POST['content_json']??''),isset($_POST['tracking_opens'])?1:0,isset($_POST['tracking_clicks'])?1:0];
        if($id)$this->db->prepare('UPDATE email_campaigns SET name=?,segment_id=?,template_id=?,subject=?,preheader=?,content_json=?,tracking_opens=?,tracking_clicks=?,updated_at=datetime(\'now\') WHERE id=?')->execute([...$data,$id]);
        else{$this->db->prepare('INSERT INTO email_campaigns (name,segment_id,template_id,subject,preheader,content_json,tracking_opens,tracking_clicks) VALUES (?,?,?,?,?,?,?,?)')->execute($data);$id=(int)$this->db->lastInsertId();}
        flash('success','Campaign saved.');redirect("/email/campaigns/$id/edit");
    }

    public function campaignShow(int $id): void
    {
        $campaign=$this->one('SELECT ec.*,ms.name segment_name FROM email_campaigns ec LEFT JOIN marketing_segments ms ON ms.id=ec.segment_id WHERE ec.id=?',[$id]);if(!$campaign){$this->notFound();return;}
        $counts=$this->all('SELECT status,COUNT(*) count FROM email_campaign_recipients WHERE campaign_id=? GROUP BY status',[$id]);
        $events=$this->all('SELECT event_type,COUNT(*) total,COUNT(DISTINCT recipient_id) unique_total,SUM(is_bot) bot_total FROM email_events WHERE campaign_id=? GROUP BY event_type',[$id]);
        $links=$this->all("SELECT url,COUNT(DISTINCT recipient_id) clicks FROM email_events WHERE campaign_id=? AND event_type='clicked' AND is_bot=0 AND url IS NOT NULL GROUP BY url ORDER BY clicks DESC",[$id]);
        $recipients=$this->all('SELECT * FROM email_campaign_recipients WHERE campaign_id=? ORDER BY id LIMIT 500',[$id]);
        render('email.campaign_show',compact('campaign','counts','events','links','recipients'),'Campaign: '.$campaign['name']);
    }

    public function schedule(int $id): void
    {
        $this->csrf("/email/campaigns/$id/edit"); $local=trim($_POST['scheduled_at']??'');
        try{$at=$local!==''?new DateTimeImmutable($local,new DateTimeZone('Europe/London')):new DateTimeImmutable('now',new DateTimeZone('Europe/London'));$utc=$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$aud=(new CampaignDispatcher($this->db))->schedule($id,$utc);flash('success','Campaign scheduled for '.count($aud['included']).' recipient(s); '.count($aud['excluded']).' excluded.');}
        catch(\Throwable $e){flash('error',$e->getMessage());redirect("/email/campaigns/$id/edit");} redirect("/email/campaigns/$id");
    }
    public function campaignAction(int $id,string $action): void
    {
        $this->csrf("/email/campaigns/$id");$d=new CampaignDispatcher($this->db);try{match($action){'draft'=>$d->returnToDraft($id),'pause'=>$d->pause($id),'resume'=>$d->resume($id),'cancel'=>$d->cancel($id),default=>throw new RuntimeException('Invalid action.')};flash('success','Campaign updated.');}catch(\Throwable $e){flash('error',$e->getMessage());}redirect("/email/campaigns/$id");
    }

    public function duplicateCampaign(int $id): void
    {
        $this->csrf("/email/campaigns/$id");$c=$this->row('email_campaigns',$id);if(!$c){$this->notFound();return;}$this->db->prepare("INSERT INTO email_campaigns (name,segment_id,template_id,status,subject,preheader,content_json,tracking_opens,tracking_clicks) VALUES (?,?,?,'draft',?,?,?,?,?)")->execute(['Copy of '.$c['name'],$c['segment_id'],$c['template_id'],$c['subject'],$c['preheader'],$c['content_json'],$c['tracking_opens'],$c['tracking_clicks']]);$new=(int)$this->db->lastInsertId();flash('success','Campaign duplicated.');redirect("/email/campaigns/$new/edit");
    }

    public function campaignToTemplate(int $id): void
    {
        $this->csrf("/email/campaigns/$id");$c=$this->row('email_campaigns',$id);if(!$c){$this->notFound();return;}
        $this->db->prepare("INSERT INTO email_templates(name,description,subject,preheader,content_json,status) VALUES (?, ?, ?, ?, ?, 'active')")
            ->execute([$c['name'].' template','Created from campaign '.$c['name'],$c['subject'],$c['preheader'],$c['content_json']]);
        $templateId=(int)$this->db->lastInsertId();flash('success','Template created from campaign.');redirect("/email/templates/$templateId/edit");
    }

    public function retryRecipient(int $campaignId, int $recipientId): void
    {
        $this->csrf("/email/campaigns/$campaignId");
        $stmt=$this->db->prepare("UPDATE email_campaign_recipients SET status='pending',next_attempt_at=NULL,last_error=NULL,processing_started_at=NULL WHERE id=? AND campaign_id=? AND status='unknown'");
        $stmt->execute([$recipientId,$campaignId]);
        if($stmt->rowCount()){
            $this->db->prepare("UPDATE email_campaigns SET status='sending',completed_at=NULL,updated_at=datetime('now') WHERE id=?")->execute([$campaignId]);
            flash('success','Ambiguous delivery queued for an explicit retry. A duplicate is possible.');
        }
        redirect("/email/campaigns/$campaignId");
    }

    public function testCampaign(int $id): void
    {
        $this->csrf("/email/campaigns/$id/edit");$email=trim($_POST['test_email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Enter a valid test address.');redirect("/email/campaigns/$id/edit");}$c=$this->row('email_campaigns',$id);$cfg=$this->config();try{$content=json_decode($c['content_json'],true)?:EmailRenderer::blankContent();$content['preheader']=$c['preheader'];$rendered=(new EmailRenderer($this->db))->render($content,['name'=>'Test recipient','company_name'=>'Test company'],appUrl().'/email');(new MailgunTransport($this->db))->send(['from'=>$cfg['from_name'].' <'.$cfg['from_email'].'>','to'=>$email,'reply_to'=>$cfg['reply_to']??null,'subject'=>'[TEST] '.$c['subject'],'html'=>$rendered['html'],'text'=>$rendered['text'],'tracking_opens'=>0,'tracking_clicks'=>0,'campaign_id'=>$id,'recipient_id'=>0,'unsubscribe_url'=>appUrl().'/email']);flash('success','Test email sent.');}catch(\Throwable $e){flash('error',$e->getMessage());}redirect("/email/campaigns/$id/edit");
    }

    public function settings(): void
    {
        $config=Secrets::decryptRow($this->config(),['api_key','webhook_signing_key'])??[];
        if(empty($config['brand_colour'])||$config['brand_colour']==='#4f46e5')$config['brand_colour']='#a1c63e';
        if(empty($config['logo_url']))$config['logo_url']='/coysh-digital-email-logo.png';
        if(empty($config['master_html']))$config['master_html']=EmailRenderer::defaultMasterHtml();
        $assets=$this->db->query('SELECT * FROM email_assets ORDER BY id DESC')->fetchAll();
        render('email.settings',compact('config','assets'),'Email Settings');
    }
    public function saveSettings(): void
    {
        $this->csrf('/settings/email');
        foreach(['from_email','reply_to'] as $field)if(trim($_POST[$field]??'')!==''&&!filter_var(trim($_POST[$field]),FILTER_VALIDATE_EMAIL)){flash('error',ucfirst(str_replace('_',' ',$field)).' is not a valid email address.');redirect('/settings/email');}
        $privacy=trim($_POST['privacy_url']??'');if($privacy!==''&&(!filter_var($privacy,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($privacy,PHP_URL_SCHEME)),['http','https'],true))){flash('error','Privacy URL must be an HTTP or HTTPS URL.');redirect('/settings/email');}
        $domain=trim($_POST['sending_domain']??'');if($domain!==''&&!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',$domain)){flash('error','Enter a valid Mailgun sending domain.');redirect('/settings/email');}
        $logo=trim($_POST['logo_url']??'');
        if($logo!==''&&!str_starts_with($logo,'/')&&(!filter_var($logo,FILTER_VALIDATE_URL)||strtolower((string)parse_url($logo,PHP_URL_SCHEME))!=='https')){flash('error','Logo URL must be root-relative or HTTPS.');redirect('/settings/email');}
        $master=trim($_POST['master_html']??'');
        if($master==='')$master=EmailRenderer::defaultMasterHtml();
        if($masterError=EmailRenderer::masterHtmlError($master)){flash('error',$masterError);redirect('/settings/email');}
        $old=$this->config();$api=trim($_POST['api_key']??'');$sign=trim($_POST['webhook_signing_key']??'');if($api==='')$api=$old['api_key']??'';else$api=Secrets::encrypt($api);if($sign==='')$sign=$old['webhook_signing_key']??'';else$sign=Secrets::encrypt($sign);
        $this->db->prepare("INSERT INTO email_marketing_config (id,region,api_key,webhook_signing_key,sending_domain,from_name,from_email,reply_to,business_name,business_address,privacy_url,brand_colour,logo_url,master_html,tracking_opens,tracking_clicks,updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(id) DO UPDATE SET region=excluded.region,api_key=excluded.api_key,webhook_signing_key=excluded.webhook_signing_key,sending_domain=excluded.sending_domain,from_name=excluded.from_name,from_email=excluded.from_email,reply_to=excluded.reply_to,business_name=excluded.business_name,business_address=excluded.business_address,privacy_url=excluded.privacy_url,brand_colour=excluded.brand_colour,logo_url=excluded.logo_url,master_html=excluded.master_html,tracking_opens=excluded.tracking_opens,tracking_clicks=excluded.tracking_clicks,updated_at=excluded.updated_at")
            ->execute([($_POST['region']??'')==='us'?'us':'eu',$api,$sign,$domain,trim($_POST['from_name']??''),trim($_POST['from_email']??''),trim($_POST['reply_to']??''),trim($_POST['business_name']??''),trim($_POST['business_address']??''),$privacy,preg_match('/^#[0-9a-f]{6}$/i',$_POST['brand_colour']??'')?$_POST['brand_colour']:'#a1c63e',$logo,$master,isset($_POST['tracking_opens'])?1:0,isset($_POST['tracking_clicks'])?1:0]);flash('success','Email settings saved.');redirect('/settings/email');
    }
    public function verifySettings(): void { $this->csrf('/settings/email');try{$ok=(new MailgunTransport($this->db))->verify();if($ok)$this->db->exec("UPDATE email_marketing_config SET last_verified_at=datetime('now') WHERE id=1");flash($ok?'success':'error',$ok?'Mailgun domain verified.':'Mailgun verification failed.');}catch(\Throwable $e){flash('error',$e->getMessage());}redirect('/settings/email'); }
    public function configureWebhooks(): void { $this->csrf('/settings/email');try{(new MailgunTransport($this->db))->configureWebhooks(appUrl().'/webhooks/mailgun');flash('success','Mailgun webhooks configured.');}catch(\Throwable $e){flash('error',$e->getMessage());}redirect('/settings/email'); }
    public function uploadAsset(): void
    {
        $this->csrf('/settings/email');$file=$_FILES['asset']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']>5_000_000){flash('error','Choose an image up to 5 MB.');redirect('/settings/email');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'][$mime]??null;if(!$ext){flash('error','Only JPEG, PNG, and GIF images are supported.');redirect('/settings/email');}$dir=DATA_PATH.'/email-assets';if(!is_dir($dir))mkdir($dir,0700,true);$token=bin2hex(random_bytes(18));$path="$dir/$token.$ext";if(!move_uploaded_file($file['tmp_name'],$path)){flash('error','Image upload failed.');redirect('/settings/email');}$this->db->prepare('INSERT INTO email_assets (public_token,original_name,file_path,mime_type,file_size,alt_text) VALUES (?,?,?,?,?,?)')->execute([$token,basename($file['name']),$path,$mime,filesize($path),trim($_POST['alt_text']??'')]);flash('success','Email image uploaded.');redirect('/settings/email');
    }

    private function validContent(string $json): string { $data=json_decode($json,true);if(!is_array($data)||!is_array($data['blocks']??null))return json_encode($this->blankContent());return json_encode($data,JSON_UNESCAPED_SLASHES); }
    private function blankContent(): array { $content=EmailRenderer::blankContent();$config=$this->config();$brand=strtolower((string)($config['brand_colour']??''));if($brand!=='#4f46e5'&&preg_match('/^#[0-9a-f]{6}$/i',$brand))$content['theme']['accent']=$brand;return $content; }
    private function csrf(string $back): void { if(!csrfCheck()){flash('error','Invalid form token — please try again.');redirect($back);} }
    private function config(): array { try{return $this->db->query('SELECT * FROM email_marketing_config WHERE id=1')->fetch()?:[];}catch(\Throwable){return [];} }
    private function row(string $table,int $id): ?array { $s=$this->db->prepare("SELECT * FROM $table WHERE id=?");$s->execute([$id]);return $s->fetch()?:null; }
    private function one(string $sql,array $params=[]): ?array { $s=$this->db->prepare($sql);$s->execute($params);return $s->fetch()?:null; }
    private function all(string $sql,array $params=[]): array { $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(); }
    private function column(string $sql,array $params=[]): array { $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_COLUMN); }
    private function notFound(): void { http_response_code(404);render('errors.404',[],'404 Not Found'); }
}
