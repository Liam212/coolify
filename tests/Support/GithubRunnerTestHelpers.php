<?php

use App\Enums\GithubRunnerStatus;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;

function runnerTestGithubApp(Team $team, int $appId, string $secret): GithubApp
{
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $team->id]);

    return GithubApp::create([
        'name' => "runner-app-{$appId}",
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => $appId,
        'installation_id' => $appId + 1,
        'webhook_secret' => $secret,
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'runner_group_id' => 7,
    ]);
}

function runnerTestServer(Team $team, string $role = 'build'): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::query()->where('team_id', $team->id)->first()?->id
            ?? PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => $role,
        'is_build_server' => $role === 'build',
        'force_disabled' => false,
    ]);

    return $server->fresh();
}

function runnerTestConfig(Server $server, GithubApp $githubApp, array $attributes = []): GithubRunnerConfig
{
    return GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['coolify'],
        'max_runners' => 2,
        'docker_mode' => 'dind',
        ...$attributes,
    ]);
}

function runnerTestExecution(GithubApp $githubApp, array $attributes = []): GithubRunnerExecution
{
    static $jobId = 1000;

    return GithubRunnerExecution::create([
        'github_app_id' => $githubApp->id,
        'trigger_workflow_job_id' => ++$jobId,
        'labels' => ['self-hosted', 'coolify'],
        'status' => GithubRunnerStatus::Queued,
        'queued_at' => now(),
        ...$attributes,
    ]);
}
