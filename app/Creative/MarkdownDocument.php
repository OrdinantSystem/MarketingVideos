<?php

namespace App\Creative;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

final class MarkdownDocument
{
    /** @return array{metadata: array<string, mixed>, body: string, sections: list<array{title: string, content: string}>} */
    public static function read(string $path): array
    {
        $contents = File::get($path);
        $metadata = [];
        $body = $contents;

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents, $matches) === 1) {
            $parsed = Yaml::parse($matches[1]);
            $metadata = is_array($parsed) ? $parsed : [];
            $body = trim($matches[2]);
        }

        return [
            'metadata' => $metadata,
            'body' => $body,
            'sections' => self::sections($body),
        ];
    }

    /** @param array<string, mixed> $metadata */
    public static function write(string $path, array $metadata, string $body): void
    {
        File::ensureDirectoryExists(dirname($path));

        $yaml = Yaml::dump($metadata, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        File::put($path, "---\n{$yaml}---\n\n".trim($body)."\n");
    }

    /** @return list<array{title: string, content: string}> */
    private static function sections(string $body): array
    {
        $sections = [];
        $title = 'Document';
        $content = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $matches) === 1) {
                if (trim(implode("\n", $content)) !== '') {
                    $sections[] = ['title' => $title, 'content' => trim(implode("\n", $content))];
                }

                $title = trim($matches[1]);
                $content = [];

                continue;
            }

            $content[] = $line;
        }

        if (trim(implode("\n", $content)) !== '') {
            $sections[] = ['title' => $title, 'content' => trim(implode("\n", $content))];
        }

        return $sections;
    }
}
