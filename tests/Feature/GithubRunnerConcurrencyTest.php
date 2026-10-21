<?php

use App\Enums\GithubRunnerStatus;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

require_once __DIR__.'/../Support/GithubRunnerTestHelpers.php';

it('serializes simultaneous cross-assignment without losing either runner', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires disposable PostgreSQL to exercise real row locks.');
    }
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $githubApp = runnerTestGithubApp($team, 1111, 'synthetic-runner-secret');
    $server = runnerTestServer($team);
    runnerTestConfig($server, $githubApp);
    $first = runnerTestExecution($githubApp, [
        'trigger_workflow_job_id' => 5001, 'status' => GithubRunnerStatus::Idle,
        'server_id' => $server->id, 'runner_name' => 'coolify-runner-first',
    ]);
    $second = runnerTestExecution($githubApp, [
        'trigger_workflow_job_id' => 6001, 'status' => GithubRunnerStatus::Idle,
        'server_id' => $server->id, 'runner_name' => 'coolify-runner-second',
    ]);
    $directory = sys_get_temp_dir().'/runner-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::disconnect();
    $children = [];
    $signals = [];

    try {
        foreach ([[$first->id, 6001], [$second->id, 5001]] as [$executionId, $jobId]) {
            [$parentSignal, $childSignal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork the row-lock regression test.');
            }
            if ($pid === 0) {
                fclose($parentSignal);
                stream_set_timeout($childSignal, 10);
                try {
                    if (fread($childSignal, 1) !== 's') {
                        throw new RuntimeException('Concurrency test start signal timed out.');
                    }
                    DB::purge();
                    DB::statement("SET lock_timeout = '3s'");
                    DB::statement("SET statement_timeout = '5s'");
                    DB::listen(function ($query) {
                        if (str_contains($query->sql, 'for update')) {
                            usleep(200_000);
                        }
                    });
                    GithubRunnerExecution::findOrFail($executionId)->assignJob($jobId, ['workflow_job_id' => $jobId]);
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/'.$executionId, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
            }
            fclose($childSignal);
            $children[$pid] = $executionId;
            $signals[] = $parentSignal;
        }
        foreach ($signals as $signal) {
            fwrite($signal, 's');
            fclose($signal);
        }
        $errors = [];
        foreach ($children as $pid => $executionId) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = is_file($directory.'/'.$executionId) ? file_get_contents($directory.'/'.$executionId) : 'A concurrency test process failed.';
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }
        DB::purge();

        expect($first->fresh()->status)->toBe(GithubRunnerStatus::Running)
            ->and($first->fresh()->workflow_job_id)->toBe(6001)
            ->and($second->fresh()->status)->toBe(GithubRunnerStatus::Running)
            ->and($second->fresh()->workflow_job_id)->toBe(5001)
            ->and(GithubRunnerExecution::count())->toBe(2);
    } finally {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::purge();
    }
});
