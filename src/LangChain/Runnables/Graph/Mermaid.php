<?php

declare(strict_types=1);

namespace LangChain\Runnables\Graph;

/**
 * Mermaid flowchart rendering for a drawable {@see Graph}.
 *
 * Port of `graph_mermaid.ts` from `langchain-core/src/runnables`: `drawMermaid` and `drawMermaidImage`.
 */
final class Mermaid
{
    private const MARKDOWN_SPECIAL_CHARS = ['*', '_', '`'];

    private function __construct()
    {
    }

    /**
     * Draw a Mermaid graph from node and edge data.
     *
     * @param array<string, Node> $nodes
     * @param list<Edge>          $edges
     * @param array{firstNode?: string|null, lastNode?: string|null, curveStyle?: string|null, withStyles?: bool|null, nodeColors?: array<string, string>|null, wrapLabelNWords?: int|null} $config
     */
    public static function drawMermaid(array $nodes, array $edges, array $config = []): string
    {
        $firstNode = $config['firstNode'] ?? null;
        $lastNode = $config['lastNode'] ?? null;
        $nodeColors = $config['nodeColors'] ?? null;
        $withStyles = $config['withStyles'] ?? true;
        $curveStyle = $config['curveStyle'] ?? 'linear';
        $wrapLabelNWords = $config['wrapLabelNWords'] ?? 9;

        $mermaidGraph = $withStyles
            ? "%%{init: {'flowchart': {'curve': '" . $curveStyle . "'}}}%%\ngraph TD;\n"
            : "graph TD;\n";

        if ($withStyles) {
            $defaultClassLabel = 'default';
            $formatDict = [$defaultClassLabel => '{0}({1})'];
            if ($firstNode !== null) {
                $formatDict[$firstNode] = '{0}([{1}]):::first';
            }
            if ($lastNode !== null) {
                $formatDict[$lastNode] = '{0}([{1}]):::last';
            }

            foreach ($nodes as $key => $node) {
                $key = (string) $key;
                $parts = explode(':', $node->name);
                $nodeName = $parts[count($parts) - 1];
                $label = self::isMarkdownWrapped($nodeName) ? '<p>' . $nodeName . '</p>' : $nodeName;

                $finalLabel = $label;
                if (($node->metadata ?? []) !== []) {
                    $lines = [];
                    foreach ($node->metadata as $k => $v) {
                        $lines[] = $k . ' = ' . self::jsToString($v);
                    }
                    $finalLabel .= '<hr/><small><em>' . implode("\n", $lines) . '</em></small>';
                }

                $template = $formatDict[$key] ?? $formatDict[$defaultClassLabel];
                $nodeLabel = self::replaceFirst($template, '{0}', self::escapeNodeLabel($key));
                $nodeLabel = self::replaceFirst($nodeLabel, '{1}', $finalLabel);

                $mermaidGraph .= "\t" . $nodeLabel . "\n";
            }
        }

        // Group edges by their common prefixes.
        /** @var array<string, list<Edge>> $edgeGroups */
        $edgeGroups = [];
        foreach ($edges as $edge) {
            $srcParts = explode(':', $edge->source);
            $tgtParts = explode(':', $edge->target);
            $common = [];
            foreach ($srcParts as $i => $src) {
                if (isset($tgtParts[$i]) && $src === $tgtParts[$i]) {
                    $common[] = $src;
                }
            }
            $edgeGroups[implode(':', $common)][] = $edge;
        }

        $seenSubgraphs = [];

        $addSubgraph = function (array $groupEdges, string $prefix) use (&$addSubgraph, &$mermaidGraph, &$seenSubgraphs, $edgeGroups, $wrapLabelNWords): void {
            $selfLoop = count($groupEdges) === 1 && $groupEdges[0]->source === $groupEdges[0]->target;
            if ($prefix !== '' && !$selfLoop) {
                $prefixParts = explode(':', $prefix);
                $subgraph = $prefixParts[count($prefixParts) - 1];

                if (isset($seenSubgraphs[$prefix])) {
                    throw new \RuntimeException(
                        "Found duplicate subgraph '" . $subgraph . "' at '" . $prefix . " -- this likely means that "
                        . "you're reusing a subgraph node with the same name. "
                        . 'Please adjust your graph to have subgraph nodes with unique names.'
                    );
                }

                $seenSubgraphs[$prefix] = true;
                $mermaidGraph .= "\tsubgraph " . $subgraph . "\n";
            }

            // All nested prefixes for this level, sorted by depth.
            $depth = count(explode(':', $prefix));
            $nested = [];
            foreach (array_keys($edgeGroups) as $nestedPrefix) {
                $nestedPrefix = (string) $nestedPrefix;
                if (
                    str_starts_with($nestedPrefix, $prefix . ':')
                    && $nestedPrefix !== $prefix
                    && count(explode(':', $nestedPrefix)) === $depth + 1
                ) {
                    $nested[] = $nestedPrefix;
                }
            }
            usort($nested, static fn (string $a, string $b): int => count(explode(':', $a)) <=> count(explode(':', $b)));

            foreach ($nested as $nestedPrefix) {
                $addSubgraph($edgeGroups[$nestedPrefix], $nestedPrefix);
            }

            foreach ($groupEdges as $edge) {
                if ($edge->data !== null) {
                    $edgeData = $edge->data;
                    $words = explode(' ', $edgeData);
                    if (count($words) > $wrapLabelNWords) {
                        $edgeData = implode('&nbsp;<br>&nbsp;', array_map(
                            static fn (array $chunk): string => implode(' ', $chunk),
                            array_chunk($words, $wrapLabelNWords),
                        ));
                    }
                    $edgeLabel = $edge->conditional === true
                        ? ' -. &nbsp;' . $edgeData . '&nbsp; .-> '
                        : ' -- &nbsp;' . $edgeData . '&nbsp; --> ';
                } else {
                    $edgeLabel = $edge->conditional === true ? ' -.-> ' : ' --> ';
                }

                $mermaidGraph .= "\t" . self::escapeNodeLabel($edge->source) . $edgeLabel
                    . self::escapeNodeLabel($edge->target) . ";\n";
            }

            if ($prefix !== '' && !$selfLoop) {
                $mermaidGraph .= "\tend\n";
            }
        };

        // Start with the top-level edges (no common prefix).
        $addSubgraph($edgeGroups[''] ?? [], '');

        // Add remaining top-level subgraphs.
        foreach ($edgeGroups as $prefix => $groupEdges) {
            $prefix = (string) $prefix;
            if (!str_contains($prefix, ':') && $prefix !== '') {
                $addSubgraph($groupEdges, $prefix);
            }
        }

        if ($withStyles) {
            foreach ($nodeColors ?? [] as $className => $color) {
                $mermaidGraph .= "\tclassDef " . $className . ' ' . $color . ";\n";
            }
        }

        return $mermaidGraph;
    }

    /**
     * Render Mermaid syntax to an image through the Mermaid.INK API and return the image bytes.
     *
     * `$fetch` is the transport seam (upstream calls the global `fetch`): it takes the URL and returns
     * `ok`, `status`, `statusText` and `body`. A transport failure is whatever it throws.
     *
     * @param array{imageType?: string|null, backgroundColor?: string|null} $config
     * @param (callable(string): array{ok: bool, status: int, statusText: string, body: string})|null $fetch
     */
    public static function drawMermaidImage(string $mermaidSyntax, array $config = [], ?callable $fetch = null): string
    {
        $backgroundColor = $config['backgroundColor'] ?? 'white';
        $imageType = $config['imageType'] ?? 'png';

        $mermaidSyntaxEncoded = self::toBase64Url($mermaidSyntax);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/D', $backgroundColor) !== 1) {
            $backgroundColor = '!' . $backgroundColor;
        }
        $imageUrl = 'https://mermaid.ink/img/' . $mermaidSyntaxEncoded . '?bgColor=' . $backgroundColor . '&type=' . $imageType;

        $res = ($fetch ?? self::defaultFetch(...))($imageUrl);
        if (!$res['ok']) {
            throw new \RuntimeException(implode("\n", [
                'Failed to render the graph using the Mermaid.INK API.',
                'Status code: ' . $res['status'],
                'Status text: ' . $res['statusText'],
            ]));
        }

        return $res['body'];
    }

    /**
     * `btoa` plus the URL-safe substitutions. Like `btoa`, it takes Latin-1 text and rejects the rest.
     */
    public static function toBase64Url(string $str): string
    {
        if (preg_match('/[^\x{0}-\x{FF}]/u', $str) === 1) {
            throw new \InvalidArgumentException('Invalid character');
        }
        $latin1 = mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');

        return rtrim(strtr(base64_encode($latin1), '+/', '-_'), '=');
    }

    /**
     * Escape a node label for Mermaid: every code unit outside `[a-zA-Z0-9_-]` becomes `_`.
     *
     * JavaScript counts UTF-16 code units, so a character outside the BMP becomes two underscores.
     */
    private static function escapeNodeLabel(string $nodeLabel): string
    {
        return (string) preg_replace_callback(
            '/[^a-zA-Z\-_0-9]/u',
            static fn (array $m): string => mb_ord($m[0], 'UTF-8') > 0xFFFF ? '__' : '_',
            $nodeLabel,
        );
    }

    private static function isMarkdownWrapped(string $nodeName): bool
    {
        foreach (self::MARKDOWN_SPECIAL_CHARS as $char) {
            if (str_starts_with($nodeName, $char) && str_ends_with($nodeName, $char)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `String.prototype.replace(string, string)`: first occurrence only, with `$`-patterns expanded.
     */
    private static function replaceFirst(string $subject, string $search, string $replacement): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        $before = substr($subject, 0, $pos);
        $after = substr($subject, $pos + strlen($search));

        $expanded = (string) preg_replace_callback(
            '/\$(\$|&|`|\x27)/',
            static fn (array $m): string => match ($m[1]) {
                '$' => '$',
                '&' => $search,
                '`' => $before,
                default => $after,
            },
            $replacement,
        );

        return $before . $expanded . $after;
    }

    /** JavaScript `String(value)` for the values a metadata record can hold. */
    private static function jsToString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? (string) json_encode($value) : ($value > 0 ? 'Infinity' : ($value < 0 ? '-Infinity' : 'NaN')),
            is_array($value) && array_is_list($value) => implode(',', array_map(
                static fn (mixed $v): string => $v === null ? '' : self::jsToString($v),
                $value,
            )),
            default => '[object Object]',
        };
    }

    /**
     * @return array{ok: bool, status: int, statusText: string, body: string}
     */
    private static function defaultFetch(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Network error fetching ' . $url);
        }
        $status = 0;
        $statusText = '';
        /** @var list<string> $http_response_header */
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})\s*(.*)$#', $line, $m) === 1) {
                $status = (int) $m[1];
                $statusText = trim($m[2]);
            }
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'statusText' => $statusText, 'body' => $body];
    }
}
