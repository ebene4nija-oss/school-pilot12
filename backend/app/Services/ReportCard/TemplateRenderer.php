<?php

namespace App\Services\ReportCard;

/**
 * The restricted template dialect schools write their report card designs in.
 *
 * WHY NOT BLADE. The obvious way to let a school "code their own report card"
 * is to accept a Blade file. Blade compiles to PHP; an uploaded Blade file is
 * arbitrary code execution on the server that runs every tenant's data. Twig
 * is safer but its sandbox is a policy layer over a full expression language,
 * and one misconfigured extension re-opens the door.
 *
 * So this is a small, closed dialect with no expression evaluation at all:
 * variable interpolation, `each`, `if`/`unless`, and a fixed list of display
 * filters. There is no way to call a function, reach a PHP object, or emit
 * unescaped HTML — `{{{ raw }}}` is not implemented, on purpose.
 *
 * Supported syntax (see docs/report-card-template-contract.md):
 *
 *   {{ student.name }}                     escaped interpolation
 *   {{ score.total | number:1 }}           filters, chainable with |
 *   {{ invoice.balance | naira }}
 *   {{ term.ends_on | date:"j M Y" }}
 *   {{ student.nickname | default:"—" }}
 *   {{#each scores}} ... {{/each}}         iteration
 *   {{ this.subject }} {{@number}}         current item, 1-based counter
 *   {{#if student.is_promoted}} ... {{else}} ... {{/if}}
 *   {{#unless scores}} ... {{/unless}}
 *   {{! a comment }}
 */
class TemplateRenderer
{
    /** Guards against a template that loops itself into an OOM. */
    public const MAX_OUTPUT_BYTES = 4 * 1024 * 1024;
    public const MAX_BLOCK_DEPTH = 12;
    public const MAX_ITERATIONS = 5000;

    private int $iterations = 0;

    /**
     * @throws TemplateException
     */
    public function render(string $template, array $data): string
    {
        $this->iterations = 0;

        $ast = $this->parse($template);
        $output = $this->renderNodes($ast, $data, $data, 0);

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw new TemplateException('Rendered report card exceeded the maximum output size.');
        }

        return $output;
    }

    /**
     * Parse without rendering — used at import time so a broken template is
     * rejected at upload, not at 11pm when a school is printing 400 reports.
     *
     * @return array<int,string> warnings
     * @throws TemplateException
     */
    public function lint(string $template): array
    {
        $ast = $this->parse($template);
        $warnings = [];

        $this->walk($ast, function (array $node) use (&$warnings) {
            if ($node['type'] === 'var') {
                foreach ($node['filters'] as $filter) {
                    if (!in_array($filter['name'], self::FILTERS, true)) {
                        $warnings[] = sprintf(
                            'Unknown filter "%s" on {{ %s }} — it will be ignored.',
                            $filter['name'],
                            $node['path']
                        );
                    }
                }
            }
        });

        return array_values(array_unique($warnings));
    }

    /** Every filter the dialect understands. */
    private const FILTERS = [
        'naira', 'number', 'upper', 'lower', 'title', 'date', 'percent',
        'default', 'ordinal', 'initials', 'trim', 'grade_colour',
    ];

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /**
     * @return array<int,array>
     * @throws TemplateException
     */
    private function parse(string $template): array
    {
        $tokens = preg_split('/(\{\{[^{}]*\}\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new TemplateException('Template could not be parsed.');
        }

        // A stack of open frames. The bottom frame is the document itself;
        // each {{#block}} pushes a frame that is folded into its parent on
        // {{/block}}. No references, so nesting cannot corrupt the tree.
        $stack = [['kind' => null, 'path' => null, 'children' => [], 'else' => [], 'branch' => 'children']];

        foreach ($tokens as $token) {
            if (!str_starts_with($token, '{{')) {
                $this->push($stack, ['type' => 'text', 'value' => $token]);
                continue;
            }

            $inner = trim(substr($token, 2, -2));

            if ($inner === '' || str_starts_with($inner, '!')) {
                continue; // comment
            }

            if (str_starts_with($inner, '#')) {
                [$kind, $expression] = $this->splitBlockTag(substr($inner, 1));

                if (!in_array($kind, ['each', 'if', 'unless'], true)) {
                    throw new TemplateException("Unknown block \"{{#{$kind}}}\". Supported blocks: each, if, unless.");
                }

                if (count($stack) > self::MAX_BLOCK_DEPTH) {
                    throw new TemplateException('Template nests blocks too deeply (maximum ' . self::MAX_BLOCK_DEPTH . ').');
                }

                $stack[] = [
                    'kind' => $kind,
                    'path' => $expression,
                    'children' => [],
                    'else' => [],
                    'branch' => 'children',
                ];
                continue;
            }

            if ($inner === 'else') {
                if (count($stack) < 2) {
                    throw new TemplateException('{{else}} found outside a block.');
                }
                $stack[count($stack) - 1]['branch'] = 'else';
                continue;
            }

            if (str_starts_with($inner, '/')) {
                $kind = trim(substr($inner, 1));

                if (count($stack) < 2) {
                    throw new TemplateException("Closing tag {{/{$kind}}} has no matching opening tag.");
                }

                $frame = array_pop($stack);

                if ($frame['kind'] !== $kind) {
                    throw new TemplateException("Closing tag {{/{$kind}}} does not match the open block {{#{$frame['kind']}}}.");
                }

                $this->push($stack, [
                    'type' => 'block',
                    'kind' => $frame['kind'],
                    'path' => $frame['path'],
                    'children' => $frame['children'],
                    'else' => $frame['else'],
                ]);
                continue;
            }

            $this->push($stack, $this->parseVariable($inner));
        }

        if (count($stack) > 1) {
            $open = $stack[count($stack) - 1]['kind'];
            throw new TemplateException("Block {{#{$open}}} was never closed.");
        }

        return $stack[0]['children'];
    }

    /** Append a node to the current branch of the innermost open frame. */
    private function push(array &$stack, array $node): void
    {
        $index = count($stack) - 1;
        $branch = $stack[$index]['branch'];
        $stack[$index][$branch][] = $node;
    }

    private function splitBlockTag(string $tag): array
    {
        $tag = trim($tag);
        $parts = preg_split('/\s+/', $tag, 2);

        return [$parts[0] ?? '', trim($parts[1] ?? '')];
    }

    /**
     * `path | filter:arg | filter2` — arguments may be quoted strings or bare
     * numbers. No nested expressions, no arithmetic.
     */
    private function parseVariable(string $expression): array
    {
        $segments = $this->splitOnPipes($expression);
        $path = trim(array_shift($segments));
        $filters = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $colon = strpos($segment, ':');
            if ($colon === false) {
                $filters[] = ['name' => $segment, 'arg' => null];
                continue;
            }

            $name = trim(substr($segment, 0, $colon));
            $arg = trim(substr($segment, $colon + 1));
            $arg = trim($arg, "\"'");

            $filters[] = ['name' => $name, 'arg' => $arg];
        }

        return ['type' => 'var', 'path' => $path, 'filters' => $filters];
    }

    /** Split on `|` while respecting quoted filter arguments. */
    private function splitOnPipes(string $expression): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;

        for ($i = 0, $length = strlen($expression); $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '|') {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    private function walk(array $nodes, callable $visitor): void
    {
        foreach ($nodes as $node) {
            $visitor($node);
            if ($node['type'] === 'block') {
                $this->walk($node['children'], $visitor);
                $this->walk($node['else'], $visitor);
            }
        }
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function renderNodes(array $nodes, array $scope, array $root, int $depth): string
    {
        $out = '';

        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => $node['value'],
                'var' => $this->renderVariable($node, $scope, $root),
                'block' => $this->renderBlock($node, $scope, $root, $depth),
                default => '',
            };

            if (strlen($out) > self::MAX_OUTPUT_BYTES) {
                throw new TemplateException('Rendered report card exceeded the maximum output size.');
            }
        }

        return $out;
    }

    private function renderBlock(array $node, array $scope, array $root, int $depth): string
    {
        $value = $this->resolve($node['path'], $scope, $root);

        if ($node['kind'] === 'each') {
            if (!is_array($value) || $value === []) {
                return $this->renderNodes($node['else'], $scope, $root, $depth + 1);
            }

            $items = array_values($value);
            $count = count($items);
            $out = '';

            foreach ($items as $index => $item) {
                if (++$this->iterations > self::MAX_ITERATIONS) {
                    throw new TemplateException('Template exceeded the maximum number of loop iterations.');
                }

                $childScope = is_array($item) ? $item : [];
                $childScope['this'] = $item;
                $childScope['@index'] = $index;
                $childScope['@number'] = $index + 1;
                $childScope['@first'] = $index === 0;
                $childScope['@last'] = $index === $count - 1;
                $childScope['@length'] = $count;
                // Keep the enclosing scope reachable as `..`
                $childScope['..'] = $scope;

                $out .= $this->renderNodes($node['children'], $childScope, $root, $depth + 1);
            }

            return $out;
        }

        $truthy = $this->isTruthy($value);
        if ($node['kind'] === 'unless') {
            $truthy = !$truthy;
        }

        return $truthy
            ? $this->renderNodes($node['children'], $scope, $root, $depth + 1)
            : $this->renderNodes($node['else'], $scope, $root, $depth + 1);
    }

    private function renderVariable(array $node, array $scope, array $root): string
    {
        $value = $this->resolve($node['path'], $scope, $root);

        foreach ($node['filters'] as $filter) {
            $value = $this->applyFilter($filter['name'], $value, $filter['arg']);
        }

        if ($value === null || is_bool($value)) {
            $value = $value === true ? 'Yes' : ($value === false ? 'No' : '');
        }

        if (is_array($value)) {
            $value = ''; // arrays are for {{#each}}, not for printing
        }

        // Always escaped. There is no unescaped variant in this dialect.
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Dotted path lookup against the current scope, then the root. Only array
     * keys are traversed — never object properties or methods, so a template
     * cannot reach an Eloquent model and call something on it.
     */
    private function resolve(string $path, array $scope, array $root)
    {
        $path = trim($path);

        if ($path === '' ) {
            return null;
        }

        if ($path === 'this') {
            return $scope['this'] ?? null;
        }

        // `../student.name` climbs one scope
        while (str_starts_with($path, '../')) {
            $scope = is_array($scope['..'] ?? null) ? $scope['..'] : $root;
            $path = substr($path, 3);
        }

        // Loop metadata (@index, @number, …) and direct scope keys win first.
        if (array_key_exists($path, $scope)) {
            return $scope[$path];
        }

        foreach ([$scope, $root] as $source) {
            $value = $this->traverse($source, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function traverse(array $source, string $path)
    {
        $segments = explode('.', $path);
        $current = $source;

        foreach ($segments as $segment) {
            if ($segment === 'this') {
                $current = $current['this'] ?? $current;
                continue;
            }

            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function isTruthy($value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    private function applyFilter(string $name, $value, ?string $arg)
    {
        return match ($name) {
            // Money is Naira. Always.
            'naira' => '₦' . number_format((float) $value, $arg !== null && $arg !== '' ? (int) $arg : 2),
            'number' => is_numeric($value) ? number_format((float) $value, $arg !== null && $arg !== '' ? (int) $arg : 0) : $value,
            'percent' => is_numeric($value) ? number_format((float) $value, $arg !== null && $arg !== '' ? (int) $arg : 1) . '%' : $value,
            'upper' => mb_strtoupper((string) $value),
            'lower' => mb_strtolower((string) $value),
            'title' => mb_convert_case(mb_strtolower((string) $value), MB_CASE_TITLE, 'UTF-8'),
            'trim' => trim((string) $value),
            'ordinal' => $this->ordinal($value),
            'initials' => $this->initials((string) $value),
            'date' => $this->formatDate($value, $arg ?: 'j M Y'),
            'default' => ($value === null || $value === '' || $value === []) ? ($arg ?? '') : $value,
            'grade_colour' => $this->gradeColour((string) $value),
            default => $value,
        };
    }

    private function ordinal($value): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $number = (int) $value;
        $suffix = 'th';

        if (!in_array($number % 100, [11, 12, 13], true)) {
            $suffix = match ($number % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };
        }

        return $number . $suffix;
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return implode('', array_map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)), array_filter($parts)));
    }

    private function formatDate($value, string $format): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format($format);
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /**
     * A class name, not a colour value — the template's own CSS decides what
     * an A1 looks like. Keeps presentation in the school's stylesheet.
     */
    private function gradeColour(string $grade): string
    {
        return match (strtoupper(substr($grade, 0, 1))) {
            'A' => 'grade-excellent',
            'B' => 'grade-very-good',
            'C' => 'grade-credit',
            'D', 'E' => 'grade-pass',
            'F' => 'grade-fail',
            default => 'grade-none',
        };
    }
}
