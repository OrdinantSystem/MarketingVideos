<?php

namespace App\Creative\Generation;

use App\Creative\ShotPlanResolver;
use Illuminate\Support\Str;
use RuntimeException;

final class SimulationPhaseGenerator implements PhaseGenerator
{
    /** @var list<string> */
    private const COLORS = ['#d97851', '#9b8572', '#557c70', '#c59562', '#24433f'];

    public function __construct(private readonly ShotPlanResolver $shotPlans) {}

    public function driver(): string
    {
        return 'simulation';
    }

    public function configured(): bool
    {
        return true;
    }

    public function supports(string $phase): bool
    {
        return in_array($phase, ['ideas', 'concepts', 'brief-script', 'images', 'scenes', 'sequence'], true);
    }

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     drafts: list<array{
     *         artifact_type: string,
     *         slot: string,
     *         title: string,
     *         summary: string,
     *         body: string,
     *         metadata: array<string, mixed>
     *     }>
     * }
     */
    public function generate(PhaseGenerationRequest $request): array
    {
        if (! $this->supports($request->phase)) {
            throw new RuntimeException("Simulation does not support the {$request->phase} phase.");
        }

        $drafts = match ($request->phase) {
            'ideas' => $this->textDrafts($this->ideaOptions()),
            'concepts' => $this->textDrafts($this->conceptOptions()),
            'brief-script' => $this->briefScriptDrafts(),
            'images' => $this->imageDrafts($request),
            'scenes' => $this->sceneDrafts($request),
            'sequence' => $this->sequenceDrafts($request),
            default => throw new RuntimeException('Unknown creative phase.'),
        };
        $retryEvidence = $this->retryEvidence($request);
        $drafts = array_map(
            function (array $draft) use ($request, $retryEvidence): array {
                $draft['metadata'] = array_merge($draft['metadata'], [
                    'retry_context_applied' => $retryEvidence['applied'],
                    'retry_context_sha256' => $retryEvidence['applied']
                        ? hash('sha256', $request->feedback)
                        : null,
                    'retry_prior_evaluation_id' => $retryEvidence['prior_evaluation_id'],
                    'retry_diagnosis' => $retryEvidence['diagnosis'],
                    'retry_diagnosis_sha256' => $retryEvidence['diagnosis_sha256'],
                    'retry_feedback_sha256' => $retryEvidence['feedback_sha256'],
                    'retry_refinement_action' => $retryEvidence['refinement_action'],
                    'retry_refinement_sha256' => $retryEvidence['refinement_sha256'],
                ]);
                if ($retryEvidence['applied']) {
                    $draft['body'] .= $this->refinementBody($retryEvidence);
                }

                return $draft;
            },
            $drafts,
        );

        return [
            'metadata' => [
                'generator' => 'simulation',
                'prompt_version' => $request->phase.'-simulation-v3',
                'usage' => [],
                'cost_usd' => 0.0,
                'status' => in_array($request->phase, ['images', 'scenes'], true) ? 'simulated' : 'ready',
                'simulation' => true,
            ],
            'drafts' => $drafts,
        ];
    }

    /**
     * @return array{
     *     applied: bool,
     *     prior_evaluation_id: string|null,
     *     diagnosis: string|null,
     *     diagnosis_sha256: string|null,
     *     feedback_excerpt: string|null,
     *     feedback_sha256: string|null,
     *     refinement_action: string|null,
     *     refinement_sha256: string|null
     * }
     */
    private function retryEvidence(PhaseGenerationRequest $request): array
    {
        $empty = [
            'applied' => false,
            'prior_evaluation_id' => null,
            'diagnosis' => null,
            'diagnosis_sha256' => null,
            'feedback_excerpt' => null,
            'feedback_sha256' => null,
            'refinement_action' => null,
            'refinement_sha256' => null,
        ];
        $marker = '[automatic-retry-context]';
        $markerPosition = strrpos($request->feedback, $marker);
        if ($markerPosition === false) {
            return $empty;
        }

        $context = trim(substr($request->feedback, $markerPosition));
        $matched = preg_match(
            '/\A\[automatic-retry-context\]\RPrior evaluation:\s*([^\r\n]+)\RDiagnosis:\s*(.+)\z/su',
            $context,
            $matches,
        );
        if ($matched !== 1) {
            return $empty;
        }

        $priorEvaluationId = trim($matches[1]);
        $diagnosis = trim($matches[2]);
        if ($priorEvaluationId === '' || $diagnosis === '') {
            return $empty;
        }

        $feedback = trim(substr($request->feedback, 0, $markerPosition));
        $refinementAction = $this->refinementAction(
            $request->phase,
            $diagnosis."\n".$feedback,
        );
        $refinementSha256 = hash('sha256', json_encode([
            'phase' => $request->phase,
            'prior_evaluation_id' => $priorEvaluationId,
            'diagnosis' => $diagnosis,
            'feedback' => $feedback,
            'refinement_action' => $refinementAction,
        ], JSON_THROW_ON_ERROR));

        return [
            'applied' => true,
            'prior_evaluation_id' => $priorEvaluationId === 'none' ? null : $priorEvaluationId,
            'diagnosis' => $diagnosis,
            'diagnosis_sha256' => hash('sha256', $diagnosis),
            'feedback_excerpt' => $feedback === '' ? null : Str::limit($this->oneLine($feedback), 320),
            'feedback_sha256' => $feedback === '' ? null : hash('sha256', $feedback),
            'refinement_action' => $refinementAction,
            'refinement_sha256' => $refinementSha256,
        ];
    }

    /**
     * @param array{
     *     applied: bool,
     *     prior_evaluation_id: string|null,
     *     diagnosis: string|null,
     *     diagnosis_sha256: string|null,
     *     feedback_excerpt: string|null,
     *     feedback_sha256: string|null,
     *     refinement_action: string|null,
     *     refinement_sha256: string|null
     * } $evidence
     */
    private function refinementBody(array $evidence): string
    {
        $evaluation = $evidence['prior_evaluation_id'] ?? 'none';
        $feedback = $evidence['feedback_excerpt'] ?? 'No separate human feedback was present.';

        return "\n\n## Automatic refinement\n\n"
            ."This is deterministic structural retry evidence, not a creative-quality judgment.\n\n"
            .'- Phase-specific action: '.$this->inline((string) $evidence['refinement_action'])."\n"
            .'- Prior evaluation: `'.$this->inline($evaluation)."`\n"
            .'- Prior structural diagnosis: '.$this->inline((string) $evidence['diagnosis'])."\n"
            .'- Applied feedback snapshot: '.$this->inline($feedback)."\n"
            .'- Diagnosis SHA-256: `'.(string) $evidence['diagnosis_sha256']."`\n"
            .'- Feedback SHA-256: `'.($evidence['feedback_sha256'] ?? 'none')."`\n"
            .'- Refinement SHA-256: `'.(string) $evidence['refinement_sha256'].'`';
    }

    private function refinementAction(string $phase, string $context): string
    {
        $signals = Str::lower(Str::ascii($context));
        $actions = match ($phase) {
            'ideas' => [
                ['signals' => ['emotional', 'tension'], 'action' => 'Clarify the recognizable emotional tension and the change the product enables.'],
                ['signals' => ['product', 'mechanism'], 'action' => 'Make the product connection explicit without inventing provider-generated claims.'],
                ['signals' => ['test', 'hypothesis'], 'action' => 'State the structural hypothesis the idea is intended to test.'],
            ],
            'concepts' => [
                ['signals' => ['product', 'mechanism'], 'action' => 'Make the product mechanism explicit in the concept beat.'],
                ['signals' => ['hook', 'opening'], 'action' => 'Clarify the opening hook before the emotional turn.'],
                ['signals' => ['risk', 'constraint'], 'action' => 'Expose the operational risk or creative constraint in the concept.'],
            ],
            'brief-script' => [
                ['signals' => ['shot', 'timing', 'duration'], 'action' => 'Recheck the canonical shot plan and its contiguous timing.'],
                ['signals' => ['call to action', 'cta'], 'action' => 'Make the call to action structurally explicit in the timed script.'],
                ['signals' => ['audience', 'purpose'], 'action' => 'Clarify the brief purpose and intended audience before the timed beats.'],
            ],
            'images' => [
                ['signals' => ['shot', 'snapshot'], 'action' => 'Reassert the exact selected-shot snapshot in the keyframe direction.'],
                ['signals' => ['variant', 'prompt'], 'action' => 'Differentiate the keyframe variant and make its generation direction explicit.'],
                ['signals' => ['visual', 'composition'], 'action' => 'Clarify the planned visual and mobile composition structurally.'],
            ],
            'scenes' => [
                ['signals' => ['motion', 'movement'], 'action' => 'Clarify one bounded motion instruction for the selected keyframe.'],
                ['signals' => ['duration', 'timing'], 'action' => 'Reassert the exact shot duration and timing window.'],
                ['signals' => ['source', 'lineage'], 'action' => 'Reassert the selected keyframe and shot snapshot lineage.'],
            ],
            'sequence' => [
                ['signals' => ['scene', 'order', 'manifest'], 'action' => 'Reassert the exact selected-scene order and manifest.'],
                ['signals' => ['caption', 'editor'], 'action' => 'Clarify the caption and editor-handoff instructions.'],
                ['signals' => ['timing', 'duration'], 'action' => 'Recheck exact scene timing against the canonical shot plan.'],
            ],
            default => [],
        };

        foreach ($actions as $candidate) {
            if (collect($candidate['signals'])->contains(
                fn (string $signal): bool => str_contains($signals, $signal),
            )) {
                return $candidate['action'];
            }
        }

        if ($actions === []) {
            return 'Retain the diagnosis as inspectable structural evidence.';
        }

        $index = hexdec(substr(hash('sha256', $context), 0, 8)) % count($actions);

        return $actions[$index]['action'];
    }

    private function oneLine(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }

    private function inline(string $value): string
    {
        return str_replace('`', "'", $this->oneLine($value));
    }

    /**
     * @param  list<array{title: string, summary: string, body: string}>  $options
     * @return list<array{artifact_type: string, slot: string, title: string, summary: string, body: string, metadata: array<string, mixed>}>
     */
    private function textDrafts(array $options): array
    {
        return array_map(static fn (array $option): array => [
            'artifact_type' => 'markdown',
            'slot' => 'primary',
            'title' => $option['title'],
            'summary' => $option['summary'],
            'body' => $option['body'],
            'metadata' => [],
        ], $options);
    }

    /**
     * @return list<array{artifact_type: string, slot: string, title: string, summary: string, body: string, metadata: array<string, mixed>}>
     */
    private function briefScriptDrafts(): array
    {
        return array_map(function (array $option): array {
            $resolution = $this->shotPlans->fromTimedMarkdown($option['body']);
            if (! $resolution['valid'] || ! is_array($resolution['shot_plan'])) {
                throw new RuntimeException('Simulation script fixture has an invalid shot plan: '.implode(' ', $resolution['errors']));
            }

            return [
                'artifact_type' => 'markdown',
                'slot' => 'primary',
                'title' => $option['title'],
                'summary' => $option['summary'],
                'body' => $option['body'],
                'metadata' => ['shot_plan' => $resolution['shot_plan']],
            ];
        }, $this->scriptOptions());
    }

    /**
     * @return list<array{artifact_type: string, slot: string, title: string, summary: string, body: string, metadata: array<string, mixed>}>
     */
    private function imageDrafts(PhaseGenerationRequest $request): array
    {
        $script = $this->onlyInput($request->inputs, 'Images require exactly one selected brief/script.');
        $plan = $this->requiredShotPlan($script);
        $this->assertRequiredShotSlots($request->requiredSlots, $plan);
        $drafts = [];

        foreach ($plan['shots'] as $index => $shot) {
            foreach (['A', 'B'] as $variant) {
                $slot = (string) $shot['id'];
                $purpose = (string) $shot['purpose'];
                $visual = (string) $shot['visual'];
                $title = Str::headline($slot)." · {$purpose} · {$variant}";
                $drafts[] = [
                    'artifact_type' => 'image_sidecar',
                    'slot' => $slot,
                    'title' => $title,
                    'summary' => $visual,
                    'body' => "# {$title}\n\n## Visual\n\n{$visual}\n\n## Direction\n\nPortrait 9:16, emotionally readable, natural texture, and a simple composition.\n\n## Generation prompt\n\nCreate a realistic advertisement keyframe for this selected-script beat: {$visual} Avoid generated product text.\n\n## Asset status\n\nSimulation sidecar only. No PNG was created.",
                    'metadata' => [
                        'variant' => $variant,
                        'asset_status' => 'absent_simulation',
                        'preview_color' => $variant === 'A'
                            ? self::COLORS[$index % count(self::COLORS)]
                            : '#b6a38f',
                        'shot' => $shot,
                    ],
                ];
            }
        }

        return $drafts;
    }

    /**
     * @return list<array{artifact_type: string, slot: string, title: string, summary: string, body: string, metadata: array<string, mixed>}>
     */
    private function sceneDrafts(PhaseGenerationRequest $request): array
    {
        $inputsBySlot = [];
        foreach ($request->inputs as $input) {
            $slot = is_string($input['slot'] ?? null) ? $input['slot'] : '';
            if ($slot === '' || isset($inputsBySlot[$slot])) {
                throw new RuntimeException('Scenes require exactly one selected image for every shot slot.');
            }

            $inputsBySlot[$slot] = $input;
        }

        $drafts = [];
        foreach ($request->requiredSlots as $index => $slot) {
            $source = $inputsBySlot[$slot] ?? null;
            if (! is_array($source)) {
                throw new RuntimeException("Selected keyframe {$slot} is missing.");
            }

            $shot = $this->shotSnapshot($source, $slot);
            $sourceId = is_string($source['id'] ?? null) ? $source['id'] : '';
            if ($sourceId === '') {
                throw new RuntimeException("Selected keyframe {$slot} has no artifact ID.");
            }

            $duration = (float) $shot['end_seconds'] - (float) $shot['start_seconds'];
            $title = Str::headline($slot).' · '.$this->formatSeconds($duration).'-second clip';
            $summary = 'Motion study derived from '.(string) ($source['title'] ?? $slot).'.';
            $drafts[] = [
                'artifact_type' => 'video_sidecar',
                'slot' => $slot,
                'title' => $title,
                'summary' => $summary,
                'body' => "# {$title}\n\n## Motion\n\nAnimate the selected keyframe with one clear action, subtle camera movement, and stable visual identity. Preserve the planned {$shot['purpose']} beat: {$shot['visual']}\n\n## Duration\n\n{$this->formatSeconds((float) $shot['start_seconds'])}–{$this->formatSeconds((float) $shot['end_seconds'])} seconds ({$this->formatSeconds($duration)} seconds).\n\n## Asset status\n\nSimulation sidecar only. No MP4 was created.",
                'metadata' => [
                    'asset_status' => 'absent_simulation',
                    'preview_color' => self::COLORS[$index % count(self::COLORS)],
                    'source_artifact_ids' => [$sourceId],
                    'shot' => $shot,
                ],
            ];
        }

        if (count($inputsBySlot) !== count($request->requiredSlots)) {
            throw new RuntimeException('Scenes received an image slot that is not required by the selected script.');
        }

        return $drafts;
    }

    /**
     * @return list<array{artifact_type: string, slot: string, title: string, summary: string, body: string, metadata: array<string, mixed>}>
     */
    private function sequenceDrafts(PhaseGenerationRequest $request): array
    {
        if ($request->inputs === []) {
            throw new RuntimeException('Sequence generation requires selected scenes.');
        }

        $scenes = array_map(function (array $scene): array {
            $slot = is_string($scene['slot'] ?? null) ? $scene['slot'] : '';

            return $scene + ['_shot' => $this->shotSnapshot($scene, $slot)];
        }, $request->inputs);
        usort($scenes, static fn (array $left, array $right): int => $left['_shot']['order'] <=> $right['_shot']['order']);

        $plan = [
            'version' => 1,
            'total_duration_seconds' => (float) $scenes[array_key_last($scenes)]['_shot']['end_seconds'],
            'shots' => array_map(static fn (array $scene): array => $scene['_shot'], $scenes),
        ];
        $validatedPlan = $this->shotPlans->validate($plan);
        if (! $validatedPlan['valid'] || ! is_array($validatedPlan['shot_plan'])) {
            throw new RuntimeException('Selected scenes do not form a valid sequence shot plan: '.implode(' ', $validatedPlan['errors']));
        }

        $sceneIds = [];
        foreach ($scenes as $scene) {
            $sceneId = is_string($scene['id'] ?? null) ? $scene['id'] : '';
            if ($sceneId === '' || in_array($sceneId, $sceneIds, true)) {
                throw new RuntimeException('Every selected sequence scene must have one unique artifact ID.');
            }
            $sceneIds[] = $sceneId;
        }

        $sceneOrder = collect($scenes)->map(function (array $scene): string {
            $shot = $scene['_shot'];

            return sprintf(
                '%d. `%s` — %s — `%s`',
                $shot['order'],
                $shot['id'],
                $shot['purpose'],
                $scene['id'],
            );
        })->implode("\n");
        $exactTiming = collect($scenes)->map(function (array $scene): string {
            $shot = $scene['_shot'];

            return "- {$this->formatSeconds((float) $shot['start_seconds'])}–{$this->formatSeconds((float) $shot['end_seconds'])}s · `{$shot['id']}` · {$shot['purpose']}";
        })->implode("\n");
        $totalDuration = (float) $validatedPlan['shot_plan']['total_duration_seconds'];

        $options = [
            ['title' => 'Direct story cut', 'summary' => 'Clean chronological assembly with restrained captions.', 'notes' => 'Use direct cuts and let the selected script timing carry the story.'],
            ['title' => 'Caption-led cut', 'summary' => 'On-screen copy carries the story without sound.', 'notes' => 'Prioritize silent comprehension and keep every caption inside the mobile safe zone.'],
            ['title' => 'Sound-led cut', 'summary' => 'Sound accents reinforce the same approved scene order.', 'notes' => 'Use restrained sound cues without changing the selected shot order or timing.'],
        ];

        return array_map(fn (array $option): array => [
            'artifact_type' => 'markdown',
            'slot' => 'primary',
            'title' => $option['title'],
            'summary' => $option['summary'],
            'body' => $this->sequenceBody($sceneOrder, $exactTiming, $totalDuration, $option['notes']),
            'metadata' => [
                'shot_plan' => $validatedPlan['shot_plan'],
                'scene_artifact_ids' => $sceneIds,
            ],
        ], $options);
    }

    private function sequenceBody(string $sceneOrder, string $exactTiming, float $totalDuration, string $notes): string
    {
        return "# Sequence plan\n\n## Scene order\n\n{$sceneOrder}\n\n## Exact timing\n\n{$exactTiming}\n\n## Captions\n\nUse concise safe-zone captions that preserve silent comprehension.\n\n## Voice timing\n\nKeep any voice line within its approved shot timing without covering the CTA.\n\n## Sound\n\nUse restrained transitions and one warm payoff cue.\n\n## Transitions\n\nPrefer direct cuts; use no transition that obscures product UI.\n\n## Aspect ratio\n\n9:16 portrait.\n\n## Total duration\n\n{$this->formatSeconds($totalDuration)} seconds.\n\n## Editor notes\n\n{$notes}";
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, mixed>
     */
    private function onlyInput(array $inputs, string $error): array
    {
        if (count($inputs) !== 1) {
            throw new RuntimeException($error);
        }

        return $inputs[0];
    }

    /**
     * @param  array<string, mixed>  $script
     * @return array{version: int, total_duration_seconds: float, shots: list<array<string, mixed>>}
     */
    private function requiredShotPlan(array $script): array
    {
        $resolution = $this->shotPlans->resolve($script);
        if (! $resolution['valid'] || ! is_array($resolution['shot_plan'])) {
            throw new RuntimeException('Selected brief/script has no valid shot plan: '.implode(' ', $resolution['errors']));
        }

        /** @var array{version: int, total_duration_seconds: float, shots: list<array<string, mixed>>} $plan */
        $plan = $resolution['shot_plan'];

        return $plan;
    }

    /**
     * @param  list<string>  $requiredSlots
     * @param  array{shots: list<array<string, mixed>>}  $plan
     */
    private function assertRequiredShotSlots(array $requiredSlots, array $plan): void
    {
        $planSlots = array_map(static fn (array $shot): string => (string) $shot['id'], $plan['shots']);
        if ($requiredSlots !== $planSlots) {
            throw new RuntimeException('Image requirements do not match the selected script shot plan.');
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function shotSnapshot(array $artifact, string $expectedSlot): array
    {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        $shot = $metadata['shot'] ?? null;
        if (! is_array($shot) || ($shot['id'] ?? null) !== $expectedSlot) {
            throw new RuntimeException("Selected artifact {$expectedSlot} has no matching shot metadata.");
        }

        foreach (['id', 'order', 'start_seconds', 'end_seconds', 'purpose', 'visual'] as $field) {
            if (! array_key_exists($field, $shot)) {
                throw new RuntimeException("Selected artifact {$expectedSlot} has incomplete shot metadata.");
            }
        }

        return $shot;
    }

    private function formatSeconds(float $seconds): string
    {
        $formatted = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.0';
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function ideaOptions(): array
    {
        return [
            ['title' => 'Same opener, again', 'summary' => 'Repetition becomes the recognizable hook.', 'body' => "# Same opener, again\n\n## Idea\n\nThree conversations begin with the same generic message.\n\n## Recognizable moment\n\nThe same opener appears several times in a row.\n\n## Emotional tension\n\nRepetition makes every conversation feel interchangeable.\n\n## Product connection\n\nA voice prompt creates the first moment that feels human.\n\n## What this tests\n\nWhether an immediately familiar frustration improves attention."],
            ['title' => 'Type. Delete. Repeat.', 'summary' => 'First-message anxiety becomes playful momentum.', 'body' => "# Type. Delete. Repeat.\n\n## Idea\n\nA user repeatedly types and deletes an opener until a conversation card gives them something real to say.\n\n## Recognizable moment\n\nA half-written first message disappears again and again.\n\n## Emotional tension\n\nThe desire to connect is blocked by overthinking.\n\n## Product connection\n\nA specific prompt removes the blank-page problem.\n\n## What this tests\n\nWhether visible typing friction makes the benefit instantly legible."],
            ['title' => 'More than a photo', 'summary' => 'A human voice reveals what a profile grid cannot.', 'body' => "# More than a photo\n\n## Idea\n\nA polished profile feels distant until a funny voice answer reveals the person behind it.\n\n## Recognizable moment\n\nProfiles blur together during a quick swipe.\n\n## Emotional tension\n\nVisual polish does not create a sense of personality.\n\n## Product connection\n\nA voice prompt makes one person feel specific and memorable.\n\n## What this tests\n\nWhether hearing personality can be the advertisement's hook."],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function conceptOptions(): array
    {
        return [
            ['title' => 'The fourth “Hey”', 'summary' => 'Generic repetition turns into real curiosity.', 'body' => "# The fourth “Hey”\n\n## Hook\n\nThree identical messages arrive in rapid succession.\n\n## Emotional turn\n\nSameness becomes curiosity.\n\n## Product mechanism\n\nA playful voice prompt creates a distinctive opening.\n\n## Creative hypothesis\n\nA highly recognizable micro-moment will improve immediate attention.\n\n## Risks or constraints\n\nThe repeated messages must remain readable without making the product feel cynical."],
            ['title' => 'The deleted opener', 'summary' => 'Awkward typing gives way to an easy prompt.', 'body' => "# The deleted opener\n\n## Hook\n\nA thumb types and erases the same sentence three times.\n\n## Emotional turn\n\nFriction becomes playfulness.\n\n## Product mechanism\n\nConversation cards make the first message easier.\n\n## Creative hypothesis\n\nVisible first-message tension will create recognition and relief.\n\n## Risks or constraints\n\nTyping must read quickly on a small screen and avoid shaming the user."],
            ['title' => 'Hear the difference', 'summary' => 'A voice answer creates instant personality.', 'body' => "# Hear the difference\n\n## Hook\n\nA silent profile montage suddenly stops on one expressive voice answer.\n\n## Emotional turn\n\nDistance becomes recognition.\n\n## Product mechanism\n\nVoice prompts reveal personality before a match.\n\n## Creative hypothesis\n\nThe product demonstration itself can function as the emotional hook.\n\n## Risks or constraints\n\nThe story also needs captions so it remains clear when viewed without sound."],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function scriptOptions(): array
    {
        return [
            ['title' => 'Start with something real', 'summary' => 'A clear five-shot story built around repeated openers.', 'body' => "# Start with something real\n\n## Purpose\n\nShow that voice prompts help conversations begin with personality instead of repetition.\n\n## Audience\n\nThoughtful urban daters aged 25–38 who are tired of generic small talk.\n\n## Story\n\nMara sees the same opener three times, discovers a funny voice prompt, and responds with genuine curiosity.\n\n## Tone and constraints\n\nWarm, specific, and understandable without sound; total duration cannot exceed 15 seconds.\n\n## 15-second script\n\n**0.0–1.5s — Hook**  \nThree “Hey :)” messages stack. Caption: *When every chat starts the same…*\n\n**1.5–4.0s — Friction**  \nMara exhales and closes the chat.\n\n**4.0–8.0s — Product**  \nA voice prompt appears and a playful answer begins.\n\n**8.0–12.0s — Payoff**  \nMara smiles and sends a real response.\n\n**12.0–15.0s — CTA**  \nLogo and line: *Start with something real.*\n\n## Shot intentions\n\nFive stable shots move from repetition to product proof, reaction, and a clean end card.\n\n## Call to action\n\nStart with something real."],
            ['title' => 'Delete the small talk', 'summary' => 'A dynamic four-shot story resolving typing tension through a conversation card.', 'body' => "# Delete the small talk\n\n## Purpose\n\nMake the first-message problem visible and resolve it simply.\n\n## Audience\n\nDaters who want to connect but overthink how to begin.\n\n## Story\n\nA typed opener is deleted three times before the app supplies a specific playful prompt.\n\n## Tone and constraints\n\nPlayful rather than anxious, readable on mobile, and no longer than 15 seconds.\n\n## 15-second script\n\n**0.0–3.0s — Hook** Type. Delete. Type. Delete.\n\n**3.0–7.0s — Product** A conversation card appears.\n\n**7.0–12.0s — Payoff** The recipient laughs and replies.\n\n**12.0–15.0s — CTA** *Skip the small talk.*\n\n## Shot intentions\n\nUse close, legible inserts for typing and the prompt, then open into the human reaction.\n\n## Call to action\n\nSkip the small talk."],
            ['title' => 'A profile you can hear', 'summary' => 'A dynamic four-shot story in which the product demonstration becomes the emotional hook.', 'body' => "# A profile you can hear\n\n## Purpose\n\nDemonstrate personality before conversation.\n\n## Audience\n\nPeople who find photo-first profiles emotionally flat.\n\n## Story\n\nA fast profile grid stops when one funny voice answer changes the viewer’s expression.\n\n## Tone and constraints\n\nHuman, restrained, captioned for silent viewing, and limited to 15 seconds.\n\n## 15-second script\n\n**0.0–2.0s — Hook** Profiles move past quickly.\n\n**2.0–7.0s — Product** One expressive voice answer plays.\n\n**7.0–12.0s — Payoff** A genuine smile leads to a meaningful reply.\n\n**12.0–15.0s — CTA** *More than a photo.*\n\n## Shot intentions\n\nContrast a cool profile montage with one warm product-and-reaction sequence.\n\n## Call to action\n\nStart with something real."],
        ];
    }
}
