<?php

namespace App\Contracts\Infrastructure;

use App\Models\Server;

interface ServerExecutorInterface
{
    /** @return array{success: bool, message: string, system: array<string, mixed>} */
    public function test(Server $server): array;

    /** Wait until SSH accepts connections (for busy or warming hosts). */
    public function ensureReady(Server $server): void;

    public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string;
    public function upload(Server $server, string $localPath, string $remotePath): void;
    public function download(Server $server, string $remotePath, string $localPath): void;
}
