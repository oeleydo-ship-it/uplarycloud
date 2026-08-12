<?php

namespace App\Services\Deployments;

use Illuminate\Validation\ValidationException;

class BuildCommandValidator
{
    public function validate(?string $command, string $field): void
    {
        if (!$command) return;
        if (preg_match('/[\r\n\x00]|&&|\|\||[;`]|\$\(|>\s*\//', $command)) throw ValidationException::withMessages([$field=>'Shell chaining, substitution, redirects, and multiline commands are not allowed.']);
        if (!preg_match('/^(npm|pnpm|yarn|bun|composer|php|node|npx)\s+[a-zA-Z0-9@:_\.\/=-]+(?:\s+[a-zA-Z0-9@:_\.\/=-]+)*$/', trim($command))) throw ValidationException::withMessages([$field=>'Use a supported package or framework command.']);
    }
    public function repository(string $url): void
    {
        if (!preg_match('#^(https://(github\.com|gitlab\.com|bitbucket\.org)/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?|git@(github\.com|gitlab\.com|bitbucket\.org):[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?)$#', $url)) throw ValidationException::withMessages(['repository_url'=>'Use a valid GitHub, GitLab, or Bitbucket repository URL.']);
    }
}
