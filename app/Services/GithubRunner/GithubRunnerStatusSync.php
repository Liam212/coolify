<?php

namespace App\Services\GithubRunner;

use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubRunnerExecution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GithubRunnerStatusSync
{
    /**
     * GitHub may have assigned this job to a different ephemeral runner while its webhook was delayed.
     */
    public static function queued(GithubRunnerExecution $execution): bool
    {
        if ($execution->status !== GithubRunnerStatus::Queued) {
            return false;
        }
        if (blank($execution->repository_full_name)) {
            return true;
        }

        try {
            $job = (new GithubRunnerApi($execution->githubApp))->workflowJob($execution->repository_full_name, $execution->trigger_workflow_job_id);
            if (in_array($job['status'], ['in_progress', 'completed'], true)) {
                if ($job['status'] === 'completed') {
                    self::completeActualRunner($execution, $job);
                }
                if ($job['status'] === 'in_progress' && filled($job['runner_name'] ?? null)) {
                    $runner = GithubRunnerExecution::query()->where('github_app_id', $execution->github_app_id)
                        ->where('runner_name', $job['runner_name'])
                        ->whereIn('status', [GithubRunnerStatus::Idle, GithubRunnerStatus::Provisioning])->first();
                    if ($runner) {
                        $replacement = $runner->assignJob($execution->trigger_workflow_job_id, [
                            ...$execution->only(['workflow_job_html_url', 'workflow_name', 'job_name', 'repository_full_name']),
                            'workflow_job_id' => $execution->trigger_workflow_job_id,
                        ]);
                        if ($replacement) {
                            ProvisionGithubRunnerJob::dispatch($replacement->id);
                        }
                    }
                }
                GithubRunnerExecution::finishUnassignedJob(
                    $execution->github_app_id, $execution->trigger_workflow_job_id,
                    $job['status'] === 'completed' ? $job['conclusion'] : null,
                    $job['status'] === 'in_progress' ? 'The job was assigned to another runner.' : null,
                );

                return false;
            }

            return $job['status'] === 'queued';
        } catch (\Throwable $e) {
            Log::warning('GitHub runner job status could not be verified; provisioning deferred.', ['execution' => $execution->uuid]);

            return false;
        } finally {
            GithubRunnerExecution::query()->whereKey($execution->id)->where('status', GithubRunnerStatus::Queued)->touch();
        }
    }

    /** @param array<string, mixed> $job */
    public static function completeActualRunner(GithubRunnerExecution $source, array $job): void
    {
        if ($job['status'] !== 'completed' || ! is_string($job['runner_name'] ?? null) || $job['runner_name'] === '') {
            return;
        }
        $runner = GithubRunnerExecution::query()->where('github_app_id', $source->github_app_id)
            ->where('runner_name', $job['runner_name'])
            ->whereIn('status', [GithubRunnerStatus::Failed, GithubRunnerStatus::Idle, GithubRunnerStatus::Provisioning, GithubRunnerStatus::Running])->first();
        if (! $runner) {
            return;
        }
        $changed = GithubRunnerExecution::query()->whereKey($runner->id)
            ->where('status', $runner->status)->where('workflow_job_id', $runner->workflow_job_id)
            ->update([
                ...$source->only(['repository_full_name', 'workflow_name', 'job_name', 'workflow_job_html_url']),
                'workflow_job_id' => $job['id'], 'status' => GithubRunnerStatus::Completed,
                'conclusion' => $job['conclusion'], 'error_message' => null,
                'completed_at' => $runner->completed_at ?? now(),
            ]);
        if ($changed) {
            CleanupGithubRunnerJob::dispatch($runner->id);
        }
    }

    /**
     * Do not infer a job failure or idle runner from container state before checking GitHub.
     */
    public static function beforeCleanup(GithubRunnerExecution $execution, bool $jobTimedOut): bool
    {
        try {
            $api = new GithubRunnerApi($execution->githubApp);
            $job = filled($execution->repository_full_name) && $execution->workflow_job_id
                ? $api->workflowJob($execution->repository_full_name, $execution->workflow_job_id) : null;
            $ownJob = $job && filled($execution->runner_name) && ($job['runner_name'] ?? null) === $execution->runner_name;
            $current = $execution->fresh();
            if (! $current || $current->status !== $execution->status || $current->workflow_job_id !== $execution->workflow_job_id) {
                return false;
            }
            if ($ownJob && $job['status'] === 'completed') {
                self::updateCurrent($execution, [
                    'status' => GithubRunnerStatus::Completed, 'conclusion' => $job['conclusion'],
                    'error_message' => null, 'completed_at' => $execution->completed_at ?? now(),
                ]);

                return true;
            }
            $runner = $api->runner($execution->runner_id);
            if (($ownJob && $job['status'] === 'in_progress') || ($runner['busy'] ?? false)) {
                if ($ownJob && $execution->status === GithubRunnerStatus::Idle) {
                    self::updateCurrent($execution, [
                        'status' => GithubRunnerStatus::Running,
                        'started_at' => $ownJob && filled($job['started_at'] ?? null)
                            ? Carbon::parse($job['started_at']) : ($execution->ready_at ?? now()),
                    ]);
                }

                return $jobTimedOut;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('GitHub runner state could not be verified.', ['execution' => $execution->uuid, 'job_timeout' => $jobTimedOut]);

            return $jobTimedOut;
        }
    }

    /** @param array<string, mixed> $attributes */
    private static function updateCurrent(GithubRunnerExecution $execution, array $attributes): void
    {
        GithubRunnerExecution::query()->whereKey($execution->id)
            ->where('status', $execution->status)->where('workflow_job_id', $execution->workflow_job_id)
            ->update($attributes);
        $execution->refresh();
    }
}
