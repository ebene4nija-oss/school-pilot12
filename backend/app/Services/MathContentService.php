<?php

namespace App\Services;

/**
 * LaTeX maths support for CBT questions, exam instructions and worksheets.
 *
 * Content is authored once and rendered by many clients (Next.js web, Flutter
 * mobile, the offline exam client). All of them render with KaTeX or an
 * equivalent, so the server's job is not rendering — it is making sure that
 * what reaches those renderers is well-formed and harmless.
 *
 * Two real risks this guards against:
 *
 *  1. **Malformed delimiters.** An unbalanced `$` turns the rest of a paper
 *     into maths. Catching it at authoring time beats catching it at 9am on
 *     exam day with 200 candidates seated.
 *  2. **Hostile TeX.** KaTeX is safe by default but grows unsafe fast when
 *     `trust` is enabled, and some clients use MathJax instead. Macros that
 *     inject HTML (`\htmlClass`, `\href`, `\includegraphics`) or that expand
 *     without bound (`\def` recursion, `\csname`) never belong in a question
 *     a teacher typed, so they are refused outright rather than escaped.
 */
class MathContentService
{
    /** Longest single expression we will accept, in characters. */
    public const MAX_EXPRESSION_LENGTH = 1000;

    /** Most expressions allowed in one field. */
    public const MAX_EXPRESSIONS = 40;

    /** Deepest brace nesting. Beyond this is almost always an expansion bomb. */
    public const MAX_BRACE_DEPTH = 20;

    /**
     * Macros refused anywhere in maths content.
     *
     * HTML/URL injection: htmlClass, htmlId, htmlStyle, htmlData, href, url,
     * includegraphics. Macro definition and expansion: def, gdef, edef, xdef,
     * newcommand, renewcommand, let, csname, expandafter, noexpand. File and
     * shell access (MathJax/real TeX pipelines): input, include, write,
     * openin, openout, read, immediate, catcode, special. Unbounded loops:
     * loop, repeat.
     */
    private const FORBIDDEN_MACROS = [
        'htmlClass', 'htmlId', 'htmlStyle', 'htmlData', 'href', 'url', 'includegraphics',
        'def', 'gdef', 'edef', 'xdef', 'newcommand', 'renewcommand', 'providecommand',
        'let', 'csname', 'endcsname', 'expandafter', 'noexpand', 'futurelet',
        'input', 'include', 'write', 'openin', 'openout', 'read', 'immediate',
        'catcode', 'special', 'lowercase', 'uppercase',
        'loop', 'repeat', 'newread', 'newwrite', 'usepackage', 'documentclass',
    ];

    /**
     * Delimiter pairs recognised, longest opener first so `$$` is matched
     * before `$` and `\[` before `\(`.
     */
    private const DELIMITERS = [
        ['$$', '$$', 'display'],
        ['\\[', '\\]', 'display'],
        ['\\(', '\\)', 'inline'],
        ['$', '$', 'inline'],
    ];

    /**
     * Validate maths content.
     *
     * @return array{valid: bool, errors: array<int,string>, expressions: array<int,array>}
     */
    public function validate(?string $content): array
    {
        $errors = [];

        if ($content === null || trim($content) === '') {
            return ['valid' => true, 'errors' => [], 'expressions' => []];
        }

        $expressions = $this->extractExpressions($content, $errors);

        if (count($expressions) > self::MAX_EXPRESSIONS) {
            $errors[] = sprintf(
                'Content contains %d maths expressions; the maximum is %d.',
                count($expressions),
                self::MAX_EXPRESSIONS
            );
        }

        foreach ($expressions as $index => $expression) {
            $this->validateExpression($expression['tex'], $index + 1, $errors);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'expressions' => $expressions,
        ];
    }

    /**
     * Validate and throw — the form used by controllers that want a 422.
     *
     * @throws \InvalidArgumentException
     */
    public function assertValid(?string $content, string $fieldLabel = 'content'): void
    {
        $result = $this->validate($content);

        if (!$result['valid']) {
            throw new \InvalidArgumentException(
                "Invalid LaTeX in {$fieldLabel}: " . implode(' ', $result['errors'])
            );
        }
    }

    /**
     * Pull out every maths expression with its delimiters and offsets.
     *
     * Escaped dollars (`\$`, as in a ₦/US$ price in a word problem) are not
     * delimiters and are skipped.
     *
     * @param array<int,string> $errors collected in place
     * @return array<int,array{tex: string, mode: string, offset: int, length: int}>
     */
    public function extractExpressions(string $content, array &$errors = []): array
    {
        $expressions = [];
        $length = strlen($content);
        $i = 0;

        while ($i < $length) {
            if ($content[$i] === '\\' && $i + 1 < $length && $content[$i + 1] === '$') {
                $i += 2; // escaped dollar — literal currency, not maths
                continue;
            }

            $matched = null;
            foreach (self::DELIMITERS as [$open, $close, $mode]) {
                if (substr($content, $i, strlen($open)) === $open) {
                    $matched = [$open, $close, $mode];
                    break;
                }
            }

            if ($matched === null) {
                $i++;
                continue;
            }

            [$open, $close, $mode] = $matched;
            $searchFrom = $i + strlen($open);
            $closePos = $this->findClosingDelimiter($content, $close, $searchFrom);

            if ($closePos === false) {
                $errors[] = sprintf(
                    'Unclosed maths delimiter "%s" at character %d. Every opener needs a matching "%s".',
                    $open,
                    $i,
                    $close
                );
                break;
            }

            $tex = substr($content, $searchFrom, $closePos - $searchFrom);
            $expressions[] = [
                'tex' => $tex,
                'mode' => $mode,
                'offset' => $i,
                'length' => ($closePos + strlen($close)) - $i,
            ];

            $i = $closePos + strlen($close);
        }

        return $expressions;
    }

    /**
     * Replace every maths expression with a placeholder, leaving prose behind.
     *
     * Used when question text is fed to search indexing or to the Claude API —
     * raw TeX confuses both, and a prompt full of `\frac{}{}` wastes tokens.
     */
    public function stripMath(?string $content, string $placeholder = '[maths]'): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $expressions = $this->extractExpressions($content);
        if ($expressions === []) {
            return $content;
        }

        // Replace back-to-front so earlier offsets stay valid.
        foreach (array_reverse($expressions) as $expression) {
            $content = substr_replace($content, $placeholder, $expression['offset'], $expression['length']);
        }

        return $content;
    }

    /** Does this content contain any maths at all? */
    public function containsMath(?string $content): bool
    {
        return $content !== null && $this->extractExpressions($content) !== [];
    }

    /**
     * Resolve the format to store: 'latex' when the author asked for it or
     * maths is present, 'plain' otherwise. Keeps clients from paying KaTeX
     * parse cost on questions that are pure prose.
     */
    public function resolveFormat(?string $requested, string ...$contents): string
    {
        if ($requested === 'latex') {
            return 'latex';
        }

        foreach ($contents as $content) {
            if ($this->containsMath($content)) {
                return 'latex';
            }
        }

        return 'plain';
    }

    private function findClosingDelimiter(string $content, string $close, int $from)
    {
        $length = strlen($content);

        for ($i = $from; $i < $length; $i++) {
            if ($content[$i] === '\\' && $i + 1 < $length && $content[$i + 1] === '$') {
                $i++;
                continue;
            }

            if (substr($content, $i, strlen($close)) === $close) {
                return $i;
            }
        }

        return false;
    }

    private function validateExpression(string $tex, int $position, array &$errors): void
    {
        if (strlen($tex) > self::MAX_EXPRESSION_LENGTH) {
            $errors[] = sprintf(
                'Maths expression %d is %d characters; the maximum is %d.',
                $position,
                strlen($tex),
                self::MAX_EXPRESSION_LENGTH
            );
        }

        if (trim($tex) === '') {
            $errors[] = sprintf('Maths expression %d is empty.', $position);
        }

        foreach (self::FORBIDDEN_MACROS as $macro) {
            // \def and \definecolor are different commands; require a
            // non-letter (or end of string) after the macro name.
            if (preg_match('/\\\\' . preg_quote($macro, '/') . '(?![a-zA-Z])/', $tex)) {
                $errors[] = sprintf(
                    'Maths expression %d uses the "\\%s" command, which is not allowed in question content.',
                    $position,
                    $macro
                );
            }
        }

        $this->validateBraces($tex, $position, $errors);
    }

    private function validateBraces(string $tex, int $position, array &$errors): void
    {
        $depth = 0;
        $maxDepth = 0;
        $length = strlen($tex);

        for ($i = 0; $i < $length; $i++) {
            if ($tex[$i] === '\\') {
                $i++; // skip the escaped character, e.g. \{ or \}
                continue;
            }

            if ($tex[$i] === '{') {
                $depth++;
                $maxDepth = max($maxDepth, $depth);
            } elseif ($tex[$i] === '}') {
                $depth--;
                if ($depth < 0) {
                    $errors[] = sprintf('Maths expression %d has an unmatched closing brace "}".', $position);
                    return;
                }
            }
        }

        if ($depth > 0) {
            $errors[] = sprintf(
                'Maths expression %d has %d unclosed brace(s) "{".',
                $position,
                $depth
            );
        }

        if ($maxDepth > self::MAX_BRACE_DEPTH) {
            $errors[] = sprintf(
                'Maths expression %d nests braces %d deep; the maximum is %d.',
                $position,
                $maxDepth,
                self::MAX_BRACE_DEPTH
            );
        }
    }
}
