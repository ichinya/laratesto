<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Parses and validates the machine JSON output of the pinned Rector process
 * (`--output-format=json`). Anything the report flow cannot trust — empty output,
 * malformed JSON, a missing totals block or a `totals.errors` that is not the
 * non-negative integer the pinned Rector schema guarantees — is a failure, never a
 * silently coerced or empty result. `file_diffs` is optional: the pinned Rector omits
 * it on runs with no changes, but when present it must be a list of well-formed
 * entries.
 */
final class RectorJsonResultParser
{
    /**
     * @return array{errors: int, fileDiffs: list<array{file: string, diff: string}>}
     * @throws \RuntimeException On any schema violation.
     */
    public function parse(string $stdout): array
    {
        $trimmed = \trim($stdout);

        if ($trimmed === '') {
            throw new \RuntimeException('Rector produced no machine JSON output.');
        }

        try {
            $payload = \json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Rector produced malformed machine JSON output.');
        }

        if (! \is_array($payload) || ! \is_array($payload['totals'] ?? null)) {
            throw new \RuntimeException('Rector machine JSON output is missing the required totals block.');
        }

        $errors = $payload['totals']['errors'] ?? null;

        if (! \is_int($errors) || $errors < 0) {
            throw new \RuntimeException('Rector machine JSON output totals.errors must be a non-negative integer.');
        }

        $fileDiffs = [];

        if (\array_key_exists('file_diffs', $payload)) {
            if (! \is_array($payload['file_diffs']) || ! \array_is_list($payload['file_diffs'])) {
                throw new \RuntimeException('Rector machine JSON output contains an invalid file_diffs container.');
            }

            foreach ($payload['file_diffs'] as $diff) {
                if (! \is_array($diff) || ! \is_string($diff['file'] ?? null) || ! \is_string($diff['diff'] ?? null)) {
                    throw new \RuntimeException('Rector machine JSON output contains an invalid file_diffs entry.');
                }

                $fileDiffs[] = ['file' => $diff['file'], 'diff' => $diff['diff']];
            }
        }

        return [
            'errors' => $errors,
            'fileDiffs' => $fileDiffs,
        ];
    }
}
