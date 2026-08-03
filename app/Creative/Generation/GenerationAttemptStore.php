<?php

namespace App\Creative\Generation;

use App\Creative\CreativePathResolver;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class GenerationAttemptStore
{
    public function __construct(private readonly CreativePathResolver $paths) {}

    /** @param array<string, mixed> $metadata */
    public function begin(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $metadata,
    ): void {
        MarkdownDocument::write(
            $this->requestPath($project, $run, $phase, $attemptId),
            array_merge($metadata, [
                'schema_version' => 1,
                'id' => $attemptId,
                'type' => 'generation_attempt_request',
                'attempt_id' => $attemptId,
                'phase' => $phase,
                'started_at' => $this->timestamp(),
            ]),
            "# Generation attempt request\n\nThis file reserves one immutable generation attempt before any candidate artifact is written.",
            false,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function finish(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $status,
        array $metadata,
        string $message,
    ): void {
        if (! in_array($status, ['committed', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new RuntimeException('Generation attempt result has an invalid status.');
        }

        MarkdownDocument::write(
            $this->resultPath($project, $run, $phase, $attemptId),
            array_merge($metadata, [
                'schema_version' => 1,
                'id' => $attemptId.'--result',
                'type' => 'generation_attempt_result',
                'attempt_id' => $attemptId,
                'phase' => $phase,
                'status' => $status,
                'finished_at' => $this->timestamp(),
            ]),
            '# Generation attempt '.ucfirst($status)."\n\n{$message}",
            false,
        );
    }

    /** @return array{request: array<string, mixed>, result: array<string, mixed>|null} */
    public function attempt(string $project, string $run, string $phase, string $attemptId): array
    {
        $request = $this->readMetadata($this->requestPath($project, $run, $phase, $attemptId));
        $resultPath = $this->resultPath($project, $run, $phase, $attemptId);

        return [
            'request' => $request,
            'result' => File::isFile($resultPath) ? $this->readMetadata($resultPath) : null,
        ];
    }

    /** @return list<array{attempt_id: string, request: array<string, mixed>|null, result: array<string, mixed>|null, path: string, parse_errors: list<string>}> */
    public function attempts(string $project, string $run, string $phase): array
    {
        $directory = $this->attemptsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::directories($directory))->map(function (string $path): array {
            $errors = [];
            $request = null;
            $result = null;

            try {
                $request = File::isFile($path.'/request.md') ? $this->readMetadata($path.'/request.md') : null;
            } catch (Throwable $exception) {
                $errors[] = 'Request: '.$exception->getMessage();
            }

            try {
                $result = File::isFile($path.'/result.md') ? $this->readMetadata($path.'/result.md') : null;
            } catch (Throwable $exception) {
                $errors[] = 'Result: '.$exception->getMessage();
            }

            return [
                'attempt_id' => basename($path),
                'request' => $request,
                'result' => $result,
                'path' => $path,
                'parse_errors' => $errors,
            ];
        })->sortBy(fn (array $attempt): string => $attempt['attempt_id'])->all());
    }

    public function requestExists(string $project, string $run, string $phase, string $attemptId): bool
    {
        return File::isFile($this->requestPath($project, $run, $phase, $attemptId));
    }

    public function resultExists(string $project, string $run, string $phase, string $attemptId): bool
    {
        return File::isFile($this->resultPath($project, $run, $phase, $attemptId));
    }

    private function attemptsPath(string $project, string $run, string $phase): string
    {
        return dirname($this->paths->outputsPath($project, $run, $phase)).'/attempts';
    }

    private function requestPath(string $project, string $run, string $phase, string $attemptId): string
    {
        return $this->attemptsPath($project, $run, $phase).'/'.$attemptId.'/request.md';
    }

    private function resultPath(string $project, string $run, string $phase, string $attemptId): string
    {
        return $this->attemptsPath($project, $run, $phase).'/'.$attemptId.'/result.md';
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path): array
    {
        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException('Generation attempt document is malformed: '.basename(dirname($path)).'/'.basename($path), previous: $exception);
        }
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
