<?php

namespace App\Creative;

use RuntimeException;

final class CreativePathResolver
{
    public function __construct(private readonly CreativePhaseCatalog $phases) {}

    public function root(): string
    {
        return rtrim((string) config('creative.root'), '/');
    }

    public function projectPath(string $project): string
    {
        $this->assertIdentifier($project);

        return $this->root().'/'.$project;
    }

    public function projectDocumentPath(string $project): string
    {
        return $this->projectPath($project).'/project.md';
    }

    public function runsPath(string $project): string
    {
        return $this->projectPath($project).'/runs';
    }

    public function runPath(string $project, string $run): string
    {
        $this->assertIdentifier($run);

        return $this->runsPath($project).'/'.$run;
    }

    public function runDocumentPath(string $project, string $run): string
    {
        return $this->runPath($project, $run).'/run.md';
    }

    public function outputsPath(string $project, string $run, string $phase): string
    {
        return $this->runPath($project, $run).'/'.$this->phases->directory($phase).'/outputs';
    }

    public function selectionsPath(string $project, string $run, string $phase): string
    {
        $this->phases->assertValid($phase);

        return $this->runPath($project, $run).'/selections/'.$phase;
    }

    public function currentFeedbackPath(string $project, string $run, string $phase): string
    {
        return $this->runPath($project, $run).'/feedback/current/phase-'.$phase.'.md';
    }

    public function cycleFeedbackPath(string $project, string $run): string
    {
        return $this->runPath($project, $run).'/feedback/current/cycle.md';
    }

    public function feedbackHistoryPath(string $project, string $run): string
    {
        return $this->runPath($project, $run).'/feedback/history';
    }

    public function feedbackApplicationsPath(string $project, string $run): string
    {
        return $this->runPath($project, $run).'/feedback/applications';
    }

    public function ideaSourceDecisionsPath(string $project, string $run): string
    {
        return $this->runPath($project, $run).'/decisions/ideas-source';
    }

    public function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*--[a-z0-9]+$/', $identifier) !== 1) {
            throw new RuntimeException('Invalid creative workspace identifier.');
        }
    }
}
