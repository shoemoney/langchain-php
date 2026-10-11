<?php

declare(strict_types=1);

// Best-effort parser check only: PhpParser explicitly accepts some newer syntax.
// This does not replace execution on the project's supported PHP runtimes.
require dirname(__DIR__) . '/vendor/autoload.php';

$parser = (new PhpParser\ParserFactory())->createForVersion(PhpParser\PhpVersion::fromString('8.2'));
$count = 0;
$failures = 0;
foreach (['src', 'tests'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $directory));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        try {
            $parser->parse((string) file_get_contents($file->getPathname()));
        } catch (PhpParser\Error $error) {
            $failures++;
            fwrite(STDERR, $file->getPathname() . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
}
printf("PHP 8.2-targeted best-effort parser: %d files, %d failures; runtime compatibility remains separate.\n", $count, $failures);
exit($failures === 0 ? 0 : 1);
