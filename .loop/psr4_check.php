#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PSR-4 + duplicate-FQCN check, driven by composer.json's OWN autoload map.
 *
 * The first version of this hardcoded a `LangChain\` prefix and reported 236
 * mismatches — every file in src/LangGraph. The port has TWO namespace roots
 * (`LangChain\` and `LangGraph\`), so the probe, not the repo, was wrong. A large
 * number from a broken instrument is not a large number of defects, which is the
 * same trap as a zero from a command that failed.
 *
 * Reads the map rather than assuming it, so a future third root cannot produce
 * the same phantom sweep.
 *
 *     php .loop/psr4_check.php
 */

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$map = $composer['autoload']['psr-4'] ?? [];
if ($map === []) {
    fwrite(STDERR, "no autoload.psr-4 map in composer.json; refusing to assume one\n");
    exit(1);
}

// Longest dir first: a namespace must match the most specific mapping.
$roots = [];
foreach ($map as $prefix => $dir) {
    $roots[] = ['prefix' => rtrim($prefix, '\\') . '\\', 'dir' => rtrim($dir, '/')];
}
usort($roots, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));

$mismatch = 0;
$declared = [];
$files = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    ++$files;
    $abs = $f->getPathname();
    $rel = substr($abs, strlen($root) + 1);

    $src = (string) file_get_contents($abs);
    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
        printf("NO NAMESPACE  %s\n", $rel);
        ++$mismatch;
        continue;
    }
    $ns = trim($m[1]);

    // Which mapping owns this file?
    $owner = null;
    foreach ($roots as $r) {
        if (str_starts_with($rel, $r['dir'] . '/')) {
            $owner = $r;
            break;
        }
    }
    if ($owner === null) {
        printf("UNMAPPED FILE  %s (matches no autoload.psr-4 dir)\n", $rel);
        ++$mismatch;
        continue;
    }

    $sub = trim(substr($rel, strlen($owner['dir'])), '/');
    // dirname() of a bare filename is '.', so a file directly in the root maps to
    // the bare prefix.
    $expect = $owner['prefix'] . ($sub === '' ? '' : str_replace(
        '/',
        '\\',
        dirname($sub) === '.' ? '' : dirname($sub)
    ));

    if ($ns !== $expect) {
        printf("MISMATCH  %s: %s != %s\n", $rel, $ns, $expect);
        ++$mismatch;
        continue;
    }

    // Duplicate fully-qualified type names across the whole tree.
    if (preg_match('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $c)) {
        $fqcn = $ns . '\\' . $c[1];
        if (isset($declared[$fqcn])) {
            printf("DUPLICATE FQCN  %s  (%s and %s)\n", $fqcn, $declared[$fqcn], $rel);
            ++$mismatch;
        }
        $declared[$fqcn] = $rel;
    }
}

printf(
    "\n%d files checked, %d distinct types, %d problem(s)\n",
    $files,
    count($declared),
    $mismatch
);
exit($mismatch === 0 ? 0 : 1);