<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['extra']['skills']['source'] ?? null) !== 'skills'
    || !isset($manifest['extra']['laravel']['providers'])
    || !isset($manifest['suggest']['llm/skills'])
) {
    throw new RuntimeException('Missing skill donor metadata or Laravel registration.');
}

$skills = glob($root . '/skills/*/SKILL.md');
if (count($skills) !== 3) {
    throw new RuntimeException('Expected the three public Laravel skills.');
}
foreach ($skills as $file) {
    $contents = file_get_contents($file);
    if (!preg_match('/\A---\R(.*?)\R---\R/s', $contents, $match)) {
        throw new RuntimeException('Missing frontmatter: ' . $file);
    }
    // These published skills deliberately use two plain YAML scalars. Checking
    // that small schema avoids adding a YAML runtime dependency to the package.
    if (!preg_match('/\Aname: ([a-z0-9-]+)\Rdescription: ([^\r\n]+)\z/', $match[1], $fields)) {
        throw new RuntimeException('Expected plain name/description frontmatter: ' . $file);
    }
    [, $name, $description] = $fields;
    if (str_contains($description, ': ') || str_contains($description, ' #')
        || in_array($description[0], ['[', '{', '!', '&', '*', '|', '>', '\'', '"'], true)
    ) {
        throw new RuntimeException('Description must be a plain YAML scalar: ' . $file);
    }
    if (!is_string($name) || strlen($name) > 64 || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name)
        || $name !== basename(dirname($file)) || !is_string($description)
        || trim($description) === '' || mb_strlen($description) > 1024
    ) {
        throw new RuntimeException('Invalid skill metadata: ' . $file);
    }
    $directory = realpath(dirname($file));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $resource) {
        if (!$resource->isFile() || $resource->getExtension() !== 'md') {
            continue;
        }
        preg_match_all('/\[[^\]]*\]\(([^\s)]+)\)/', file_get_contents($resource->getPathname()), $links);
        foreach ($links[1] as $link) {
            if (str_contains($link, '://') || str_starts_with($link, '#')) {
                continue;
            }
            $target = realpath(dirname($resource->getPathname()) . '/' . explode('#', $link, 2)[0]);
            $prefix = $directory . DIRECTORY_SEPARATOR;
            if ($target === false || !str_starts_with($target, $prefix) || !is_file($target)) {
                throw new RuntimeException('Missing or non-self-contained reference: ' . $link);
            }
        }
    }
    echo $name, ": metadata and local references OK\n";
}
