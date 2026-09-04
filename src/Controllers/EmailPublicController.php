<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\EmailWebhookService;
use PDO;

final class EmailPublicController
{
    public function __construct(private PDO $db) {}

    public function webhook(): void
    {
        $payload=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($payload)){http_response_code(400);echo 'Invalid JSON';return;}
        try{(new EmailWebhookService($this->db))->handle($payload);http_response_code(200);echo 'OK';}
        catch(\Throwable $e){http_response_code(406);echo 'Rejected';}
    }

    public function unsubscribePage(int $recipientId,string $token): void
    {
        $valid=(new EmailWebhookService($this->db))->validUnsubscribe($recipientId,$token);
        http_response_code($valid?200:404);
        render('email.unsubscribe',compact('valid','recipientId','token'),$valid?'Unsubscribe':'Invalid link','layouts/auth');
    }

    public function unsubscribe(int $recipientId,string $token): void
    {
        $success=(new EmailWebhookService($this->db))->unsubscribe($recipientId,$token);
        http_response_code($success?200:404);
        render('email.unsubscribe',['valid'=>$success,'success'=>$success],$success?'Unsubscribed':'Invalid link','layouts/auth');
    }

    public function asset(string $token): void
    {
        $stmt=$this->db->prepare('SELECT * FROM email_assets WHERE public_token=?');$stmt->execute([$token]);$asset=$stmt->fetch();
        if(!$asset||!is_file($asset['file_path'])){http_response_code(404);return;}
        header('Content-Type: '.$asset['mime_type']);header('Content-Length: '.filesize($asset['file_path']));header('Cache-Control: public, max-age=31536000, immutable');readfile($asset['file_path']);exit;
    }
}
