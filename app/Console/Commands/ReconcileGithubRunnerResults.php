<?php

namespace App\Console\Commands;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubApp;
use App\Models\GithubRunnerExecution;
use App\Services\GithubRunner\GithubRunnerApi;
use App\Services\GithubRunner\GithubRunnerStatusSync;
use Illuminate\Console\Command;

class ReconcileGithubRunnerResults extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'github-runners:reconcile-results {githubAppId} {--apply : Apply verified results; otherwise only emit the evidence receipt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify and repair up to 100 inferred runner failures from the last 24 hours using GitHub results';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $app = GithubApp::findOrFail($this->argument('githubAppId'));
        $executions = GithubRunnerExecution::query()->where('github_app_id', $app->id)
            ->where('status', GithubRunnerStatus::Failed)
            ->where('error_message', 'The runner container stopped before GitHub reported a job result.')
            ->where('created_at', '>=', now()->subDay())->whereNotNull('repository_full_name')
            ->whereNotNull('workflow_job_id')->orderBy('id')->limit(101)->get();
        if ($executions->count() > 100) {
            $this->error('More than 100 rows need review. No changes applied.');

            return self::FAILURE;
        }
        $verified = [];
        try {
            // Snapshot every original mapping before any cross-assigned runner can replace it.
            foreach ($executions as $execution) {
                $verified[] = [$execution, (new GithubRunnerApi($app))->workflowJob($execution->repository_full_name, $execution->workflow_job_id)];
            }
        } catch (\Throwable $e) {
            $this->error('GitHub could not verify every selected job. No changes applied.');

            return self::FAILURE;
        }
        foreach ($verified as [$execution, $job]) {
            $this->line(json_encode([
                'phase' => 'verified', 'execution_id' => $execution->id,
                'original' => $execution->only(['workflow_job_id', 'repository_full_name', 'runner_name', 'status', 'error_message']),
                'github' => array_intersect_key($job, array_flip(['id', 'status', 'conclusion', 'runner_name'])),
            ], JSON_THROW_ON_ERROR));
        }
        if ($this->option('apply')) {
            foreach ($verified as [$execution, $job]) {
                GithubRunnerStatusSync::completeActualRunner($execution, $job);
            }
            foreach ($executions as $execution) {
                $this->line(json_encode(['phase' => 'result', 'execution_id' => $execution->id,
                    'current' => $execution->fresh()->only(['workflow_job_id', 'repository_full_name', 'runner_name', 'status', 'conclusion', 'error_message'])], JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }
}
