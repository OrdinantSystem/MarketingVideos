<?php

namespace App\Creative;

use RuntimeException;

final class CreativePhaseCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $phases = config('creative.phases');

        if (! is_array($phases)) {
            throw new RuntimeException('Creative phase configuration is invalid.');
        }

        return $phases;
    }

    /** @return array<string, mixed> */
    public function definition(string $phase): array
    {
        $this->assertValid($phase);

        return $this->all()[$phase];
    }

    public function directory(string $phase): string
    {
        $definition = $this->definition($phase);

        return $definition['number'].'-'.$phase;
    }

    public function next(string $phase): ?string
    {
        $this->assertValid($phase);
        $keys = array_keys($this->all());
        $index = array_search($phase, $keys, true);

        return is_int($index) && isset($keys[$index + 1]) ? $keys[$index + 1] : null;
    }

    public function previous(string $phase): ?string
    {
        $this->assertValid($phase);
        $keys = array_keys($this->all());
        $index = array_search($phase, $keys, true);

        return is_int($index) && $index > 0 ? $keys[$index - 1] : null;
    }

    public function assertValid(string $phase): void
    {
        if (! array_key_exists($phase, $this->all())) {
            throw new RuntimeException('Invalid creative phase.');
        }
    }
}
