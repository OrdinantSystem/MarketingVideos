<?php

namespace App\Console\Commands;

use App\Creative\CreativeIntegrityAuditor;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

final class CreativeIntegrityCommand extends Command
{
    protected $signature = 'creative:integrity
        {project? : Project directory identifier}
        {run? : Run directory identifier}
        {--json : Emit a machine-readable JSON report}
        {--strict : Treat warnings as a failing result}';

    protected $description = 'Audit creative project files without modifying them or contacting a provider';

    /** @throws JsonException */
    public function handle(CreativeIntegrityAuditor $auditor): int
    {
        $project = $this->argument('project');
        $run = $this->argument('run');

        try {
            $report = $auditor->audit(
                is_string($project) ? $project : null,
                is_string($run) ? $run : null,
            );
        } catch (InvalidArgumentException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'error' => $exception->getMessage(),
                    'summary' => ['total' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0],
                    'issues' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $rows = array_map(fn (array $issue): array => [
                strtoupper((string) $issue['severity']),
                $issue['code'],
                collect([$issue['project'], $issue['run'], $issue['phase']])->filter()->implode(' / ') ?: '-',
                $issue['path'] ?? '-',
                $issue['message'],
                $issue['remediation'],
            ], $report['issues']);

            if ($rows === []) {
                $this->info('No integrity issues found.');
            } else {
                $this->table(['Severity', 'Code', 'Scope', 'Path', 'Message', 'Remediation'], $rows);
            }

            $summary = $report['summary'];
            $this->line(sprintf(
                'Integrity audit: %d error(s), %d warning(s), %d info item(s).',
                $summary['errors'],
                $summary['warnings'],
                $summary['info'],
            ));
        }

        $hasFailure = $report['summary']['errors'] > 0
            || ($this->option('strict') && $report['summary']['warnings'] > 0);

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
