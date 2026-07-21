<?php

namespace App\Creative;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class CreativeWorkspaceRepository
{
    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        File::ensureDirectoryExists($this->root());

        return collect(File::directories($this->root()))
            ->map(fn (string $directory): ?array => $this->projectSummary(basename($directory)))
            ->filter()
            ->sortByDesc('updated_at')
            ->values()
            ->all();
    }

    /** @return array{project: string, run: string, phase: string} */
    public function createProject(string $name, string $idea, string $startPhase): array
    {
        $project = (Str::slug($name) ?: 'creative-project').'--'.$this->shortId();
        $createdAt = now('UTC')->toIso8601String();
        $projectPath = $this->projectPath($project);

        MarkdownDocument::write("{$projectPath}/project.md", [
            'id' => $project,
            'type' => 'creative_project',
            'name' => $name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'start_phase' => $startPhase,
        ], "# {$name}\n\n## Broad idea\n\n{$idea}\n\n## Goal\n\nProduce a clear 15-second advertisement through a human-reviewed creative chain.");

        return $this->createRun($project);
    }

    /** @return array{project: string, run: string, phase: string} */
    public function createRun(string $project): array
    {
        $projectDocument = $this->readDocument("{$this->projectPath($project)}/project.md");
        $run = now('UTC')->format('Ymd-His').'--'.$this->shortId();
        $createdAt = now('UTC')->toIso8601String();
        $projectPath = $this->projectPath($project);
        $startPhase = (string) ($projectDocument['metadata']['start_phase'] ?? 'concepts');

        MarkdownDocument::write("{$projectPath}/runs/{$run}/run.md", [
            'id' => $run,
            'type' => 'creative_run',
            'project' => $project,
            'created_at' => $createdAt,
        ], "# Creative run\n\nThis run begins from the broad idea in `project.md`. Every generated option and selection remains immutable.");

        foreach (array_keys($this->phases()) as $phase) {
            File::ensureDirectoryExists("{$projectPath}/runs/{$run}/{$this->phaseDirectory($phase)}/outputs");
            $this->writeCurrentFeedback($project, $run, $phase, '');
        }

        $this->writeCycleFeedback($project, $run, '');

        return compact('project', 'run') + ['phase' => $startPhase];
    }

    /** @return array<string, mixed> */
    public function workspace(string $project, string $run, string $phase, ?string $artifactId = null): array
    {
        $this->assertPhase($phase);
        $projectDocument = $this->readDocument("{$this->projectPath($project)}/project.md");
        $runDocument = $this->readDocument("{$this->runPath($project, $run)}/run.md");
        $artifacts = $this->artifacts($project, $run, $phase);
        $selected = $this->selectedArtifacts($project, $run, $phase);
        $artifacts = array_map(fn (array $artifact): array => $artifact + [
            'selected' => collect($selected)->contains(fn (array $item): bool => $item['artifact_id'] === $artifact['id']),
        ], $artifacts);
        $activeArtifact = collect($artifacts)->firstWhere('id', $artifactId) ?? Arr::first($artifacts);
        $phaseItems = $this->phaseItems($project, $run, $phase);

        return [
            'projects' => $this->projects(),
            'project' => $projectDocument + ['id' => $project],
            'run' => $runDocument + ['id' => $run],
            'runs' => $this->runs($project),
            'phases' => $phaseItems,
            'phase' => collect($phaseItems)->firstWhere('key', $phase),
            'inputs' => $this->inputs($project, $run, $phase),
            'artifacts' => $artifacts,
            'activeArtifact' => $activeArtifact,
            'feedback' => $this->feedback($project, $run, $phase),
            'tree' => $this->tree($this->projectPath($project), $this->projectPath($project)),
        ];
    }

    /** @return array{project: string, run: string, phase: string} */
    public function firstWorkspace(): array
    {
        if ($this->projects() === []) {
            $this->createDemoProject();
        }

        $project = $this->projects()[0];

        return [
            'project' => $project['id'],
            'run' => $project['latest_run'],
            'phase' => $project['current_phase'],
        ];
    }

    public function simulate(string $project, string $run, string $phase): void
    {
        $this->assertPhase($phase);
        $inputs = $this->inputs($project, $run, $phase);

        if ($phase !== 'ideas' && $phase !== 'concepts' && $inputs === []) {
            throw new RuntimeException('Select the previous phase output before starting this phase.');
        }

        match ($phase) {
            'ideas' => $this->writeTextOptions($project, $run, $phase, $this->ideaOptions()),
            'concepts' => $this->writeTextOptions($project, $run, $phase, $this->conceptOptions()),
            'brief-script' => $this->writeTextOptions($project, $run, $phase, $this->scriptOptions()),
            'images' => $this->writeImageOptions($project, $run),
            'scenes' => $this->writeSceneOptions($project, $run),
            'sequence' => $this->writeTextOptions($project, $run, $phase, $this->sequenceOptions()),
            default => throw new RuntimeException('Unknown creative phase.'),
        };
    }

    /** @return array{next_phase: string|null, complete: bool} */
    public function select(string $project, string $run, string $phase, string $artifactId): array
    {
        $artifact = collect($this->artifacts($project, $run, $phase))->firstWhere('id', $artifactId);

        if (! $artifact) {
            throw new RuntimeException('The selected artifact does not exist.');
        }

        $slot = $artifact['slot'] ?? 'primary';
        $path = "{$this->runPath($project, $run)}/selections/{$phase}";
        $selectionId = $this->filename('selection', $artifact['title']);

        MarkdownDocument::write("{$path}/{$selectionId}.md", [
            'id' => $selectionId,
            'type' => 'selection',
            'phase' => $phase,
            'slot' => $slot,
            'artifact_id' => $artifactId,
            'artifact_path' => $artifact['path'],
            'created_at' => now('UTC')->toIso8601String(),
        ], "# Selected: {$artifact['title']}\n\nThis immutable record promotes the referenced artifact as the input for the next phase.");

        $complete = count($this->selectedArtifacts($project, $run, $phase)) >= count($this->phases()[$phase]['slots']);

        return [
            'next_phase' => $complete ? $this->nextPhase($phase) : null,
            'complete' => $complete,
        ];
    }

    public function appendFeedback(string $project, string $run, string $phase, string $scope, string $feedback): void
    {
        $this->assertPhase($phase);
        $entry = "\n## ".now('UTC')->format('Y-m-d H:i:s').' UTC · '.$this->shortId()."\n\n{$feedback}\n";

        if ($scope === 'cycle') {
            File::append($this->cycleFeedbackPath($project, $run), $entry);

            return;
        }

        File::append($this->currentFeedbackPath($project, $run, $phase), $entry);
    }

    public function archiveFeedback(string $project, string $run, string $phase, string $scope): void
    {
        $source = $scope === 'cycle'
            ? $this->cycleFeedbackPath($project, $run)
            : $this->currentFeedbackPath($project, $run, $phase);

        $document = MarkdownDocument::read($source);

        if (trim($document['body']) === '' || ! str_contains($document['body'], '## ')) {
            throw new RuntimeException('There is no pending feedback to archive.');
        }

        $historyName = $this->filename('feedback', $scope === 'cycle' ? 'whole-cycle' : $phase);
        MarkdownDocument::write("{$this->runPath($project, $run)}/feedback/history/{$historyName}.md", [
            'id' => $historyName,
            'type' => 'feedback_history',
            'scope' => $scope,
            'phase' => $phase,
            'archived_at' => now('UTC')->toIso8601String(),
        ], "# Applied feedback snapshot\n\n{$document['body']}\n\n## Implementation note\n\nArchived from the current feedback inbox. A future agent can add implementation references here.");

        if ($scope === 'cycle') {
            $this->writeCycleFeedback($project, $run, '');
        } else {
            $this->writeCurrentFeedback($project, $run, $phase, '');
        }
    }

    public function createDemoProject(): void
    {
        $created = $this->createProject(
            'Voice prompts campaign',
            'Create a warm dating-app advertisement showing how a meaningful voice prompt turns repetitive small talk into a real conversation.',
            'concepts',
        );

        $project = $created['project'];
        $run = $created['run'];
        $this->simulate($project, $run, 'ideas');
        $idea = $this->artifacts($project, $run, 'ideas')[0];
        $this->select($project, $run, 'ideas', $idea['id']);
        $this->simulate($project, $run, 'concepts');
        $concept = $this->artifacts($project, $run, 'concepts')[0];
        $this->select($project, $run, 'concepts', $concept['id']);
        $this->simulate($project, $run, 'brief-script');
        $script = $this->artifacts($project, $run, 'brief-script')[0];
        $this->select($project, $run, 'brief-script', $script['id']);
        $this->simulate($project, $run, 'images');
        $this->appendFeedback($project, $run, 'concepts', 'phase', 'The first concept is strongest. Keep the opening understandable without sound.');
    }

    /** @return list<array<string, mixed>> */
    private function artifacts(string $project, string $run, string $phase): array
    {
        $directory = "{$this->runPath($project, $run)}/{$this->phaseDirectory($phase)}/outputs";
        File::ensureDirectoryExists($directory);

        return collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file) use ($directory): array {
                $document = $this->readDocument($file->getPathname());

                return $document + [
                    'id' => $document['metadata']['id'] ?? $file->getBasename('.md'),
                    'title' => $document['metadata']['title'] ?? Str::headline($file->getBasename('.md')),
                    'summary' => $document['metadata']['summary'] ?? '',
                    'type' => $document['metadata']['artifact_type'] ?? 'markdown',
                    'slot' => $document['metadata']['slot'] ?? 'primary',
                    'variant' => $document['metadata']['variant'] ?? null,
                    'created_at' => $document['metadata']['created_at'] ?? date(DATE_ATOM, $file->getMTime()),
                    'path' => Str::after($file->getPathname(), $this->root().'/'),
                    'filename' => Str::after($file->getPathname(), $directory.'/'),
                    'preview_color' => $document['metadata']['preview_color'] ?? '#d9d5c8',
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function inputs(string $project, string $run, string $phase): array
    {
        if ($phase === 'ideas' || ($phase === 'concepts' && $this->selectedArtifacts($project, $run, 'ideas') === [])) {
            return [$this->readDocument("{$this->projectPath($project)}/project.md") + [
                'id' => $project,
                'title' => 'Broad project idea',
                'path' => "{$project}/project.md",
                'slot' => 'primary',
            ]];
        }

        $previous = $this->previousPhase($phase);

        if ($previous === null) {
            return [];
        }

        return collect($this->selectedArtifacts($project, $run, $previous))
            ->map(function (array $selection) use ($project, $run, $previous): ?array {
                $artifact = collect($this->artifacts($project, $run, $previous))->firstWhere('id', $selection['artifact_id']);

                return $artifact ? $artifact + ['selection' => $selection] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function selectedArtifacts(string $project, string $run, string $phase): array
    {
        $directory = "{$this->runPath($project, $run)}/selections/{$phase}";

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(fn (\SplFileInfo $file): array => MarkdownDocument::read($file->getPathname())['metadata'])
            ->sortByDesc('created_at')
            ->unique('slot')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function phaseItems(string $project, string $run, string $activePhase): array
    {
        return collect($this->phases())->map(function (array $definition, string $key) use ($project, $run, $activePhase): array {
            $selectedCount = count($this->selectedArtifacts($project, $run, $key));
            $requiredCount = count($definition['slots']);

            return $definition + [
                'key' => $key,
                'directory' => $this->phaseDirectory($key),
                'artifact_count' => count($this->artifacts($project, $run, $key)),
                'selected_count' => $selectedCount,
                'required_count' => $requiredCount,
                'complete' => $selectedCount >= $requiredCount,
                'active' => $key === $activePhase,
                'available' => $key === 'ideas' || $key === 'concepts' || $this->inputs($project, $run, $key) !== [],
            ];
        })->values()->all();
    }

    /** @return array{current: list<array{title: string, content: string}>, cycle: list<array{title: string, content: string}>, history_count: int} */
    private function feedback(string $project, string $run, string $phase): array
    {
        $history = "{$this->runPath($project, $run)}/feedback/history";

        return [
            'current' => MarkdownDocument::read($this->currentFeedbackPath($project, $run, $phase))['sections'],
            'cycle' => MarkdownDocument::read($this->cycleFeedbackPath($project, $run))['sections'],
            'history_count' => File::isDirectory($history) ? count(File::files($history)) : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function runs(string $project): array
    {
        $directory = "{$this->projectPath($project)}/runs";

        return collect(File::directories($directory))
            ->map(function (string $path): array {
                $document = $this->readDocument("{$path}/run.md");

                return [
                    'id' => basename($path),
                    'created_at' => $document['metadata']['created_at'] ?? '',
                    'label' => 'Run '.substr(basename($path), 0, 8),
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function projectSummary(string $project): ?array
    {
        try {
            $document = $this->readDocument("{$this->projectPath($project)}/project.md");
            $runs = $this->runs($project);
            $latestRun = $runs[0]['id'] ?? null;

            if (! $latestRun) {
                return null;
            }

            $phaseItems = $this->phaseItems($project, $latestRun, 'concepts');
            $startPhase = $document['metadata']['start_phase'] ?? 'concepts';
            $startIndex = array_search($startPhase, array_column($phaseItems, 'key'), true);
            $eligible = is_int($startIndex) ? array_slice($phaseItems, $startIndex) : $phaseItems;
            $current = collect($eligible)->first(fn (array $phase): bool => ! $phase['complete'] && $phase['available']) ?? end($phaseItems);

            return [
                'id' => $project,
                'name' => $document['metadata']['name'] ?? Str::headline($project),
                'updated_at' => $document['metadata']['updated_at'] ?? $document['metadata']['created_at'] ?? '',
                'latest_run' => $latestRun,
                'current_phase' => $current['key'] ?? 'concepts',
            ];
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @return list<array{name: string, path: string, type: string, children?: array}> */
    private function tree(string $directory, string $base, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        return collect(File::directories($directory))
            ->map(fn (string $path): array => [
                'name' => basename($path),
                'path' => Str::after($path, $base.'/'),
                'type' => 'directory',
                'children' => $this->tree($path, $base, $depth + 1),
            ])
            ->concat(collect(File::files($directory))->map(fn (\SplFileInfo $file): array => [
                'name' => $file->getFilename(),
                'path' => Str::after($file->getPathname(), $base.'/'),
                'type' => $file->getExtension() === 'md' ? 'markdown' : 'asset',
            ]))
            ->sortBy(fn (array $item): string => ($item['type'] === 'directory' ? '0' : '1').$item['name'])
            ->values()
            ->all();
    }

    /** @param list<array{title: string, summary: string, body: string}> $options */
    private function writeTextOptions(string $project, string $run, string $phase, array $options): void
    {
        foreach ($options as $option) {
            $id = $this->filename($phase, $option['title']);
            MarkdownDocument::write("{$this->runPath($project, $run)}/{$this->phaseDirectory($phase)}/outputs/{$id}.md", [
                'id' => $id,
                'artifact_type' => 'markdown',
                'phase' => $phase,
                'slot' => 'primary',
                'title' => $option['title'],
                'summary' => $option['summary'],
                'created_at' => now('UTC')->toIso8601String(),
                'version' => 1,
                'simulation' => true,
            ], $option['body']);
        }
    }

    private function writeImageOptions(string $project, string $run): void
    {
        $shots = [
            ['shot-01', 'Hook', 'Three identical “Hey :)” messages stack on Mara’s phone.', '#d97851'],
            ['shot-02', 'Friction', 'Mara pauses over another generic reply and closes the chat.', '#9b8572'],
            ['shot-03', 'Product', 'The approved voice-prompt interface appears clearly.', '#557c70'],
            ['shot-04', 'Payoff', 'A playful voice answer creates a genuine smile.', '#c59562'],
            ['shot-05', 'CTA', 'Warm brand end card: Start with something real.', '#24433f'],
        ];

        foreach ($shots as [$slot, $purpose, $description, $color]) {
            foreach (['A', 'B'] as $variant) {
                $title = Str::headline($slot)." · {$purpose} · {$variant}";
                $id = $this->filename('keyframe', "{$slot}-{$variant}");
                MarkdownDocument::write("{$this->runPath($project, $run)}/03-images/outputs/{$id}.md", [
                    'id' => $id,
                    'artifact_type' => 'image_sidecar',
                    'phase' => 'images',
                    'slot' => $slot,
                    'variant' => $variant,
                    'title' => $title,
                    'summary' => $description,
                    'asset_path' => "assets/{$id}.png",
                    'preview_color' => $variant === 'A' ? $color : '#b6a38f',
                    'created_at' => now('UTC')->toIso8601String(),
                    'simulation' => true,
                ], "# {$title}\n\n## Visual\n\n{$description}\n\n## Direction\n\nPortrait 9:16, emotionally readable, natural skin texture, simple composition.\n\n## Generation prompt\n\nA realistic advertisement keyframe with clear human emotion and no generated product text.\n\n## Asset status\n\nInterface prototype only. The future image provider will write the binary file referenced in the front matter.");
            }
        }
    }

    private function writeSceneOptions(string $project, string $run): void
    {
        foreach ($this->phases()['scenes']['slots'] as $index => $slot) {
            $title = Str::headline($slot).' · '.($index + 1).'-second clip';
            $id = $this->filename('scene', $slot);
            MarkdownDocument::write("{$this->runPath($project, $run)}/04-scenes/outputs/{$id}.md", [
                'id' => $id,
                'artifact_type' => 'video_sidecar',
                'phase' => 'scenes',
                'slot' => $slot,
                'title' => $title,
                'summary' => 'Short scene derived from the selected keyframe.',
                'asset_path' => "assets/{$id}.mp4",
                'preview_color' => ['#d97851', '#9b8572', '#557c70', '#c59562', '#24433f'][$index],
                'created_at' => now('UTC')->toIso8601String(),
                'simulation' => true,
            ], "# {$title}\n\n## Motion\n\nOne clear action, subtle camera movement and consistent character identity.\n\n## Duration\n\nBetween 1 and 3 seconds.\n\n## Asset status\n\nInterface prototype only. Seedance integration will write the referenced MP4 later.");
        }
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function ideaOptions(): array
    {
        return [
            ['title' => 'Same opener, again', 'summary' => 'Repetition becomes the recognizable hook.', 'body' => "# Same opener, again\n\n## Idea\n\nThree conversations begin with the same generic message. A voice prompt creates the first moment that feels human."],
            ['title' => 'Type. Delete. Repeat.', 'summary' => 'First-message anxiety becomes playful momentum.', 'body' => "# Type. Delete. Repeat.\n\n## Idea\n\nA user repeatedly types and deletes an opener until a conversation card gives them something real to say."],
            ['title' => 'More than a photo', 'summary' => 'A human voice reveals what a profile grid cannot.', 'body' => "# More than a photo\n\n## Idea\n\nA polished profile feels distant until a funny voice answer reveals the person behind it."],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function conceptOptions(): array
    {
        return [
            ['title' => 'The fourth “Hey”', 'summary' => 'Generic repetition turns into real curiosity.', 'body' => "# The fourth “Hey”\n\n## Hook\n\nThree identical messages arrive in rapid succession.\n\n## Emotional turn\n\nSameness → curiosity.\n\n## Product mechanism\n\nA playful voice prompt creates a distinctive opening.\n\n## What this tests\n\nWhether a highly recognizable micro-moment improves immediate attention."],
            ['title' => 'The deleted opener', 'summary' => 'Awkward typing gives way to an easy prompt.', 'body' => "# The deleted opener\n\n## Hook\n\nA thumb types and erases the same sentence three times.\n\n## Emotional turn\n\nFriction → playfulness.\n\n## Product mechanism\n\nConversation cards make the first message easier.\n\n## What this tests\n\nWhether visible first-message tension creates recognition."],
            ['title' => 'Hear the difference', 'summary' => 'A voice answer creates instant personality.', 'body' => "# Hear the difference\n\n## Hook\n\nA silent profile montage suddenly stops on one expressive voice answer.\n\n## Emotional turn\n\nDistance → recognition.\n\n## Product mechanism\n\nVoice prompts reveal personality before a match.\n\n## What this tests\n\nWhether the product demonstration can serve as the hook itself."],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function scriptOptions(): array
    {
        return [
            ['title' => 'Start with something real', 'summary' => 'A clear five-shot story built around repeated openers.', 'body' => "# Start with something real\n\n## Purpose\n\nShow that voice prompts help conversations begin with personality instead of repetition.\n\n## Audience\n\nThoughtful urban daters aged 25–38 who are tired of generic small talk.\n\n## Story\n\nMara sees the same opener three times, discovers a funny voice prompt, and responds with genuine curiosity.\n\n## 15-second script\n\n**0.0–1.5s — Hook**  \nThree “Hey :)” messages stack. Caption: *When every chat starts the same…*\n\n**1.5–4.0s — Friction**  \nMara exhales and closes the chat.\n\n**4.0–8.0s — Product**  \nA voice prompt appears: *My most irrational opinion is…* A playful answer begins.\n\n**8.0–12.0s — Payoff**  \nMara smiles and sends a real response.\n\n**12.0–15.0s — CTA**  \nLogo and line: *Start with something real.*"],
            ['title' => 'Delete the small talk', 'summary' => 'Typing tension resolves through a conversation card.', 'body' => "# Delete the small talk\n\n## Purpose\n\nMake the first-message problem visible and resolve it simply.\n\n## Audience\n\nDaters who want to connect but overthink how to begin.\n\n## Story\n\nA typed opener is deleted three times before the app supplies a specific playful prompt.\n\n## 15-second script\n\n**0.0–3.0s** Type. Delete. Type. Delete.\n\n**3.0–7.0s** A conversation card appears.\n\n**7.0–12.0s** The recipient laughs and replies.\n\n**12.0–15.0s** *Skip the small talk. Try Example Dating.*"],
            ['title' => 'A profile you can hear', 'summary' => 'The product demonstration becomes the emotional hook.', 'body' => "# A profile you can hear\n\n## Purpose\n\nDemonstrate personality before conversation.\n\n## Audience\n\nPeople who find photo-first profiles emotionally flat.\n\n## Story\n\nA fast profile grid stops when one funny voice answer changes the viewer’s expression.\n\n## 15-second script\n\n**0.0–2.0s** Profiles move past quickly.\n\n**2.0–7.0s** One voice answer plays.\n\n**7.0–12.0s** A genuine smile and meaningful reply.\n\n**12.0–15.0s** *More than a photo. Start with something real.*"],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function sequenceOptions(): array
    {
        return [
            ['title' => 'Direct story cut', 'summary' => 'Clean chronological assembly with restrained captions.', 'body' => "# Direct story cut\n\n## Order\n\n1. Hook notification stack\n2. Human reaction\n3. Product mechanism\n4. Emotional payoff\n5. Brand CTA\n\n## Editor notes\n\nHard cuts for the first four seconds, then slightly warmer pacing. Keep the product UI deterministic."],
            ['title' => 'Caption-led cut', 'summary' => 'On-screen copy carries the story without sound.', 'body' => "# Caption-led cut\n\n## Order\n\nUse the same five scenes with captions as the narrative spine.\n\n## Editor notes\n\nPrioritize silent comprehension, large safe-zone captions and one restrained sound cue per transition."],
            ['title' => 'Product-forward cut', 'summary' => 'Move the voice prompt earlier for a faster demonstration.', 'body' => "# Product-forward cut\n\n## Order\n\nHook → product insert → reaction → reply → CTA.\n\n## Editor notes\n\nReveal the mechanism by second four and preserve at least three seconds for the human payoff."],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function phases(): array
    {
        return config('creative.phases');
    }

    private function phaseDirectory(string $phase): string
    {
        return $this->phases()[$phase]['number'].'-'.$phase;
    }

    private function nextPhase(string $phase): ?string
    {
        $keys = array_keys($this->phases());
        $index = array_search($phase, $keys, true);

        return is_int($index) && isset($keys[$index + 1]) ? $keys[$index + 1] : null;
    }

    private function previousPhase(string $phase): ?string
    {
        $keys = array_keys($this->phases());
        $index = array_search($phase, $keys, true);

        return is_int($index) && $index > 0 ? $keys[$index - 1] : null;
    }

    /** @return array<string, mixed> */
    private function readDocument(string $path): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException('Creative document not found: '.basename($path));
        }

        return MarkdownDocument::read($path);
    }

    private function root(): string
    {
        return rtrim((string) config('creative.root'), '/');
    }

    private function projectPath(string $project): string
    {
        $this->assertIdentifier($project);

        return $this->root().'/'.$project;
    }

    private function runPath(string $project, string $run): string
    {
        $this->assertIdentifier($run);

        return $this->projectPath($project).'/runs/'.$run;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*--[a-z0-9]+$/', $identifier) !== 1) {
            throw new RuntimeException('Invalid creative workspace identifier.');
        }
    }

    private function assertPhase(string $phase): void
    {
        if (! array_key_exists($phase, $this->phases())) {
            throw new RuntimeException('Invalid creative phase.');
        }
    }

    private function shortId(): string
    {
        return Str::lower(Str::random(6));
    }

    private function filename(string $type, string $title): string
    {
        return now('UTC')->format('Ymd-His').'--'.Str::slug($type).'--'.$this->shortId().'--'.Str::limit(Str::slug($title), 42, '');
    }

    private function currentFeedbackPath(string $project, string $run, string $phase): string
    {
        return "{$this->runPath($project, $run)}/feedback/current/phase-{$phase}.md";
    }

    private function cycleFeedbackPath(string $project, string $run): string
    {
        return "{$this->runPath($project, $run)}/feedback/current/cycle.md";
    }

    private function writeCurrentFeedback(string $project, string $run, string $phase, string $body): void
    {
        MarkdownDocument::write($this->currentFeedbackPath($project, $run, $phase), [
            'type' => 'feedback_inbox',
            'scope' => 'phase',
            'phase' => $phase,
            'updated_at' => now('UTC')->toIso8601String(),
        ], "# Current feedback · {$phase}\n\n{$body}");
    }

    private function writeCycleFeedback(string $project, string $run, string $body): void
    {
        MarkdownDocument::write($this->cycleFeedbackPath($project, $run), [
            'type' => 'feedback_inbox',
            'scope' => 'cycle',
            'updated_at' => now('UTC')->toIso8601String(),
        ], "# Current feedback · whole cycle\n\n{$body}");
    }
}
