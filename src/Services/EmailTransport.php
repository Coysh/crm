<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

interface EmailTransport
{
    /** @return array{id:string,message:string} */
    public function send(array $message): array;
    public function verify(): bool;
    public function configureWebhooks(string $url): void;
}
