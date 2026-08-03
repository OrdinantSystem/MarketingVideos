<?php

namespace App\Creative;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class CreativeLockManager
{
    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public function exclusive(string $key, callable $callback): mixed
    {
        $directory = storage_path('framework/creative-locks');
        File::ensureDirectoryExists($directory);
        $handle = fopen($directory.'/'.hash('sha256', $key).'.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a creative workspace lock.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire a creative workspace lock.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
