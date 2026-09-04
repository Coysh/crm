<?php

declare(strict_types=1);

use CoyshCRM\Services\CampaignDispatcher;
use CoyshCRM\Services\EmailRenderer;
use CoyshCRM\Services\EmailTransport;
use CoyshCRM\Services\EmailWebhookService;
use CoyshCRM\Services\Secrets;
use CoyshCRM\Services\SegmentEvaluator;
use PHPUnit\Framework\TestCase;

final class FakeEmailTransport implements EmailTransport
{
    public array $messages = [];
    public function send(array $message): array { $this->messages[] = $message; return ['id'=>'fake-'.$message['recipient_id'],'message'=>'Queued']; }
    public function verify(): bool { return true; }
    public function configureWebhooks(string $url): void {}
}

final class EmailMarketingTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY); CREATE TABLE clients(id INTEGER PRIMARY KEY,name TEXT,status TEXT,contact_name TEXT,contact_email TEXT,client_type TEXT); CREATE TABLE servers(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE domains(id INTEGER PRIMARY KEY,client_id INTEGER,registrar TEXT); CREATE TABLE client_sites(id INTEGER PRIMARY KEY,client_id INTEGER,status TEXT,website_stack TEXT,css_framework TEXT,smtp_service TEXT,server_id INTEGER); CREATE TABLE cloudflare_zones(id INTEGER PRIMARY KEY,domain_id INTEGER); CREATE TABLE agreements(id INTEGER PRIMARY KEY,client_id INTEGER,agreement_type TEXT,status TEXT,covers_hosting INTEGER,covers_support INTEGER,covers_maintenance INTEGER);");
        $this->db->exec((string)file_get_contents(BASE_PATH.'/migrations/034_email_marketing.sql'));
        $this->db->exec("INSERT INTO clients(id,name,status,contact_name,contact_email,client_type) VALUES(1,'Acme','active','Alice','alice@example.com','managed'); INSERT INTO client_sites(id,client_id,status,website_stack) VALUES(1,1,'active','WordPress'); INSERT INTO marketing_contacts(name,email,email_norm,company_name,status,eligibility_basis,eligibility_at) VALUES('Alice','alice@example.com','alice@example.com','Acme','active','consent',datetime('now')); INSERT INTO marketing_contact_clients(contact_id,client_id,is_primary) VALUES(last_insert_rowid(),1,1);");
    }

    public function testDynamicSegmentAndEligibility(): void
    {
        $service = new SegmentEvaluator($this->db);
        self::assertCount(1, $service->contacts(1));
        self::assertCount(1, $service->audience(1)['included']);
        $contactId=(int)$this->db->query("SELECT id FROM marketing_contacts WHERE email_norm='alice@example.com'")->fetchColumn();
        $this->db->prepare("INSERT INTO marketing_segment_members(segment_id,contact_id,action) VALUES(?,?,'exclude')")->execute([1,$contactId]);
        self::assertCount(0, $service->contacts(1));
        $this->db->exec("INSERT INTO marketing_contacts(name,email,email_norm,status,eligibility_basis) VALUES('Bob','bob@example.com','bob@example.com','active','consent')");
        $bob=(int)$this->db->lastInsertId();$this->db->prepare("INSERT INTO marketing_segment_members(segment_id,contact_id,action) VALUES(?,?,'include')")->execute([1,$bob]);
        self::assertSame(['bob@example.com'],array_column($service->contacts(1),'email'));
        $this->db->exec("INSERT INTO marketing_segments(name,segment_type,rules_json) VALUES('Manual','manual','[]')");$manual=(int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO marketing_segment_members(segment_id,contact_id,action) VALUES(?,?,'include')")->execute([$manual,$contactId]);
        self::assertSame(['alice@example.com'],array_column($service->contacts($manual),'email'));
    }

    public function testRendererRemovesUnsafeMarkupAndAddsFooter(): void
    {
        $content=['theme'=>[],'blocks'=>[['type'=>'text','html'=>'<p onclick="bad()">Hello<script><a href="javascript:bad()">nested</a></script><a href="javascript:bad()">link</a></p>']]];
        $rendered=(new EmailRenderer($this->db))->render($content,['name'=>'A & B','company_name'=>'Acme'],'https://crm.test/unsubscribe');
        self::assertStringNotContainsString('onclick', $rendered['html']);
        self::assertStringNotContainsString('javascript:', $rendered['html']);
        self::assertStringContainsString('Unsubscribe from marketing emails', $rendered['html']);
    }

    public function testCampaignSnapshotAndWorker(): void
    {
        $this->db->exec("INSERT INTO email_marketing_config(id,region,api_key,webhook_signing_key,sending_domain,from_name,from_email,reply_to,business_name,business_address) VALUES(1,'eu','key','sign','mg.example.com','Coysh','hello@example.com','reply@example.com','Coysh Digital','London')");
        $content=json_encode(EmailRenderer::blankContent());
        $stmt=$this->db->prepare("INSERT INTO email_campaigns(name,segment_id,status,subject,content_json) VALUES('Test',1,'draft','Hello {{name}}',?)");$stmt->execute([$content]);$campaignId=(int)$this->db->lastInsertId();
        (new CampaignDispatcher($this->db))->schedule($campaignId,date('Y-m-d H:i:s',time()-60));
        self::assertSame(1,(int)$this->db->query("SELECT total_recipients FROM email_campaigns WHERE id=$campaignId")->fetchColumn());
        $fake=new FakeEmailTransport();$result=(new CampaignDispatcher($this->db,$fake))->run();
        self::assertSame(1,$result['sent']);self::assertCount(1,$fake->messages);self::assertSame('Hello Alice',$fake->messages[0]['subject']);
        self::assertSame('completed',$this->db->query("SELECT status FROM email_campaigns WHERE id=$campaignId")->fetchColumn());
    }

    public function testSuppressionAddedAfterSchedulingWinsAtSendTime(): void
    {
        $this->db->exec("INSERT INTO email_marketing_config(id,region,api_key,sending_domain,from_name,from_email,business_name,business_address) VALUES(1,'eu','key','mg.example.com','Coysh','hello@example.com','Coysh Digital','London')");
        $stmt=$this->db->prepare("INSERT INTO email_campaigns(name,segment_id,status,subject,content_json) VALUES('Late suppression',1,'draft','Hi',?)");$stmt->execute([json_encode(EmailRenderer::blankContent())]);$campaignId=(int)$this->db->lastInsertId();
        (new CampaignDispatcher($this->db))->schedule($campaignId,date('Y-m-d H:i:s',time()-60));
        $contact=$this->db->query("SELECT * FROM marketing_contacts WHERE email_norm='alice@example.com'")->fetch();
        $this->db->prepare("INSERT INTO marketing_suppressions(email_norm,reason,source,contact_id) VALUES(?,'unsubscribe','test',?)")->execute([$contact['email_norm'],$contact['id']]);
        $fake=new FakeEmailTransport();(new CampaignDispatcher($this->db,$fake))->run();
        self::assertCount(0,$fake->messages);self::assertSame('suppressed',$this->db->query("SELECT status FROM email_campaign_recipients WHERE campaign_id=$campaignId")->fetchColumn());
    }

    public function testSignedComplaintWebhookSuppressesContactAndIsIdempotent(): void
    {
        $this->db->exec("INSERT INTO email_marketing_config(id,webhook_signing_key) VALUES(1,'".str_replace("'","''",Secrets::encrypt('signing-secret'))."')");
        $contactId=(int)$this->db->query("SELECT id FROM marketing_contacts WHERE email_norm='alice@example.com'")->fetchColumn();
        $this->db->prepare("INSERT INTO email_campaigns(id,name,status,subject,content_json) VALUES(7,'Sent','completed','Hi','{}')")->execute();
        $this->db->prepare("INSERT INTO email_campaign_recipients(id,campaign_id,contact_id,email,email_norm,status,unsubscribe_token) VALUES(9,7,?,'alice@example.com','alice@example.com','delivered',?)")->execute([$contactId,Secrets::encrypt('token')]);
        $timestamp=time();$token='webhook-token';$payload=['signature'=>['timestamp'=>(string)$timestamp,'token'=>$token,'signature'=>hash_hmac('sha256',(string)$timestamp.$token,'signing-secret')],'event-data'=>['id'=>'evt-1','event'=>'complained','timestamp'=>$timestamp,'recipient'=>'alice@example.com','user-variables'=>['crm_recipient_id'=>'9']]];
        $service=new EmailWebhookService($this->db);$service->handle($payload);$service->handle($payload);
        self::assertSame('complaint',$this->db->query("SELECT reason FROM marketing_suppressions WHERE email_norm='alice@example.com'")->fetchColumn());
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM email_events WHERE provider_event_id='evt-1'")->fetchColumn());
    }

    public function testOutOfOrderAcceptedEventDoesNotDowngradeDelivered(): void
    {
        $this->db->exec("INSERT INTO email_marketing_config(id,webhook_signing_key) VALUES(1,'".str_replace("'","''",Secrets::encrypt('secret'))."')");
        $contactId=(int)$this->db->query("SELECT id FROM marketing_contacts WHERE email_norm='alice@example.com'")->fetchColumn();
        $this->db->exec("INSERT INTO email_campaigns(id,name,status,subject,content_json) VALUES(1,'Sent','completed','Hi','{}')");
        $this->db->prepare("INSERT INTO email_campaign_recipients(id,campaign_id,contact_id,email,email_norm,status,unsubscribe_token) VALUES(2,1,?,'alice@example.com','alice@example.com','delivered',?)")->execute([$contactId,Secrets::encrypt('token')]);
        $timestamp=time();$token='late-accepted';$payload=['signature'=>['timestamp'=>(string)$timestamp,'token'=>$token,'signature'=>hash_hmac('sha256',(string)$timestamp.$token,'secret')],'event-data'=>['id'=>'evt-late','event'=>'accepted','timestamp'=>$timestamp,'user-variables'=>['crm_recipient_id'=>'2']]];
        (new EmailWebhookService($this->db))->handle($payload);
        self::assertSame('delivered',$this->db->query('SELECT status FROM email_campaign_recipients WHERE id=2')->fetchColumn());
    }

    public function testUnsubscribeLinkValidationIsReadOnlyUntilPost(): void
    {
        $contactId=(int)$this->db->query("SELECT id FROM marketing_contacts WHERE email_norm='alice@example.com'")->fetchColumn();
        $this->db->exec("INSERT INTO email_campaigns(id,name,status,subject,content_json) VALUES(1,'Sent','completed','Hi','{}')");
        $this->db->prepare("INSERT INTO email_campaign_recipients(id,campaign_id,contact_id,email,email_norm,status,unsubscribe_token) VALUES(2,1,?,'alice@example.com','alice@example.com','delivered',?)")->execute([$contactId,Secrets::encrypt('opaque-token')]);
        $service=new EmailWebhookService($this->db);
        self::assertTrue($service->validUnsubscribe(2,'opaque-token'));
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM marketing_suppressions')->fetchColumn());
        self::assertTrue($service->unsubscribe(2,'opaque-token'));
        self::assertSame('unsubscribe',$this->db->query("SELECT reason FROM marketing_suppressions WHERE email_norm='alice@example.com'")->fetchColumn());
    }
}
