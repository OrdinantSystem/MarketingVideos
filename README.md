# VideoAuto creative workspace

VideoAuto is a local Laravel + React workspace for turning a broad advertisement idea into an inspectable 15-second production chain.

The interface follows six phases: optional ideas → concept → brief & script → images → scenes → sequence. Creative content is stored as files, not database rows. Every generated output and selection receives a timestamped, unique filename and previous versions remain available.

## Run locally

From the project root:

```bash
source "$HOME/.zshrc"
source "$HOME/.nvm/nvm.sh"
nvm use 22
composer run dev
```

Then open [http://localhost:8000](http://localhost:8000). Stop all development processes with `Ctrl+C`.

If Composer is still not visible in a new terminal, run this once before the commands above:

```bash
export PATH="$HOME/.config/herd-lite/bin:$PATH"
hash -r
```

## Filesystem contract

```text
creative-projects/
└── project-name--unique-id/
    ├── project.md
    └── runs/date-time--unique-id/
        ├── 00-ideas/outputs/
        ├── 01-concepts/outputs/
        ├── 02-brief-script/outputs/
        ├── 03-images/outputs/
        ├── 04-scenes/outputs/
        ├── 05-sequence/outputs/
        ├── selections/{phase}/
        └── feedback/
            ├── current/
            └── history/
```

Markdown front matter records IDs, phase, slot, timestamps, source paths, and asset references. Future PNG/JPG/MP4 files will live beside Markdown sidecars so both the interface and outside agents can inspect the same history.

## Current prototype boundary

The complete manual interface and file lifecycle work locally. “Generate” creates representative Markdown outputs without calling paid APIs; image/video cards are sidecar simulations. AI providers, actual media binaries, and deterministic FFmpeg assembly are the next integration layer.

Use the circular-arrow button beside the run selector to begin a new iteration inside the same project. Earlier runs are preserved.

## Verify

```bash
php artisan test
npm run types:check
npm run lint:check
npm run build
```
