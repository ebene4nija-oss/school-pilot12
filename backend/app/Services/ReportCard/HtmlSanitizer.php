<?php

namespace App\Services\ReportCard;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Whitelist sanitiser for school-supplied report card markup and CSS.
 *
 * The threat model is not "a school admin is malicious" — it is that the
 * markup arrives from outside our codebase and is then rendered by dompdf on
 * our server, and served back into an admin's browser as an HTML preview. Both
 * of those are dangerous sinks:
 *
 *  - dompdf resolves `<img src>` and `@import` server-side. An unfiltered
 *    `file:///etc/passwd` or an internal `http://169.254.169.254/...` turns a
 *    report card design into local file disclosure and SSRF.
 *  - the preview endpoint renders the same markup in an admin session, so a
 *    `<script>` or an `onerror=` handler runs with that admin's privileges
 *    against every tenant they can reach.
 *
 * Anything not explicitly allowed is removed. Sanitising happens at import
 * time (so a bad template never lands in the database) and the stored result
 * is what gets rendered.
 */
class HtmlSanitizer
{
    /**
     * Tags a report card legitimately needs. Layout, tables, basic text,
     * images. No script, no iframe, no object/embed, no form controls, no
     * link/meta (which can pull in remote resources).
     */
    private const ALLOWED_TAGS = [
        'div', 'span', 'p', 'br', 'hr', 'section', 'header', 'footer', 'article', 'main', 'aside',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'img', 'figure', 'figcaption', 'blockquote', 'pre', 'code', 'abbr', 'time',
    ];

    /** Attributes allowed on any element. */
    private const GLOBAL_ATTRIBUTES = ['class', 'id', 'style', 'title', 'dir', 'lang'];

    /** Extra attributes allowed on specific elements. */
    private const TAG_ATTRIBUTES = [
        'img' => ['src', 'alt', 'width', 'height'],
        'td' => ['colspan', 'rowspan', 'align', 'valign'],
        'th' => ['colspan', 'rowspan', 'align', 'valign', 'scope'],
        'col' => ['span', 'width'],
        'colgroup' => ['span'],
        'table' => ['border', 'cellpadding', 'cellspacing'],
        'time' => ['datetime'],
        'abbr' => ['title'],
        'ol' => ['start', 'type'],
    ];

    /**
     * URL schemes an `<img src>` may use.
     *
     * `data:` is allowed only for images and is what a school should use to
     * embed its crest — it needs no network fetch at PDF time, so it cannot be
     * used to probe anything. `file:` and `//host` are refused outright.
     */
    private const ALLOWED_IMG_SCHEMES = ['https', 'http', 'data'];

    /**
     * CSS constructs that fetch or execute. Used to *detect* them in an
     * inline `style` attribute (where the whole attribute is then dropped).
     */
    private const FORBIDDEN_CSS_PATTERNS = [
        '/@import\b/i',
        '/\bexpression\s*\(/i',
        '/\bjavascript\s*:/i',
        '/\bvbscript\s*:/i',
        '/\bbehavior\s*:/i',
        '/-moz-binding/i',
        '/url\s*\(\s*[\'"]?\s*(?!data:image\/)[a-z][a-z0-9+.-]*:/i', // url() to a non-data:image scheme
    ];

    /**
     * The same constructs, matched as whole statements so a stylesheet can
     * have them *removed* rather than merely flagged.
     *
     * Matching the whole construct matters: excising just the `@import` token
     * leaves `url("https://evil.test/x.css");` sitting in the stylesheet,
     * which is inert on its own but is exactly the kind of residue that a
     * later edit, or a differently-lenient parser, can re-animate.
     */
    private const CSS_REMOVAL_PATTERNS = [
        ['/@import[^;]*;?/i', 'an @import (stylesheets may not pull in remote resources)'],
        ['/\bexpression\s*\([^)]*\)/i', 'a CSS expression()'],
        ['/\bbehavior\s*:[^;}]*;?/i', 'a behavior: binding'],
        ['/-moz-binding\s*:[^;}]*;?/i', 'a -moz-binding'],
        ['/\b(?:javascript|vbscript)\s*:[^;}\'")]*/i', 'a script URL'],
    ];

    /** @var array<int,string> */
    private array $removals = [];

    /**
     * @return array{html: string, removals: array<int,string>}
     */
    public function sanitize(string $html): array
    {
        $this->removals = [];

        if (trim($html) === '') {
            return ['html' => '', 'removals' => []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks entity/DTD network fetches; the wrapper div
        // gives us a stable root to serialise back out of.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="sp-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new TemplateException('Template markup could not be parsed as HTML.');
        }

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//*[@id="sp-root"]')->item(0);

        if (!$root instanceof DOMElement) {
            throw new TemplateException('Template markup could not be parsed as HTML.');
        }

        $this->cleanNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return ['html' => $out, 'removals' => array_values(array_unique($this->removals))];
    }

    private function cleanNode(DOMNode $node): void
    {
        // Snapshot children: we mutate the list while walking it.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->removals[] = "Removed disallowed <{$tag}> element.";
                    $child->parentNode->removeChild($child);
                    continue;
                }

                $this->cleanAttributes($child, $tag);
                $this->cleanNode($child);
                continue;
            }

            // Comments can hide conditional-comment payloads; text and CDATA
            // are fine and stay.
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode->removeChild($child);
            }
        }
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);
        $attributes = iterator_to_array($element->attributes);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue;

            // Every event handler, in one rule.
            if (str_starts_with($name, 'on')) {
                $this->removals[] = "Removed event handler attribute \"{$name}\" from <{$tag}>.";
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                // data-* is inert and useful for a school's own tooling.
                if (str_starts_with($name, 'data-')) {
                    continue;
                }

                $this->removals[] = "Removed disallowed attribute \"{$name}\" from <{$tag}>.";
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'style' && !$this->isSafeInlineStyle($value)) {
                $this->removals[] = "Removed unsafe inline style on <{$tag}>.";
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'src' && !$this->isSafeImageUrl($value)) {
                $this->removals[] = "Removed unsafe image source on <{$tag}>.";
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // An image without alt text is an accessibility hole and a broken
        // report card if the file 404s. Give it an empty alt rather than none.
        if ($tag === 'img' && !$element->hasAttribute('alt')) {
            $element->setAttribute('alt', '');
        }
    }

    /**
     * A stylesheet url() may point at an embedded image or at something in
     * this deployment's own public path. Never at another host, and never at
     * the filesystem — dompdf resolves both server-side.
     */
    private function isSafeCssUrl(string $target): bool
    {
        if ($target === '') {
            return false;
        }

        if (preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $target)) {
            return true;
        }

        if (str_starts_with($target, '//')) {
            return false;
        }

        // A relative or root-relative path carries no scheme.
        return !preg_match('/^[a-z][a-z0-9+.-]*:/i', $target);
    }

    private function isSafeInlineStyle(string $style): bool
    {
        foreach (self::FORBIDDEN_CSS_PATTERNS as $pattern) {
            if (preg_match($pattern, $style)) {
                return false;
            }
        }

        return true;
    }

    private function isSafeImageUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Template tokens are resolved before rendering; leave them alone.
        if (str_contains($url, '{{')) {
            return true;
        }

        // Protocol-relative URLs inherit a scheme we cannot predict.
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Same-origin relative path — fine.
        if (str_starts_with($url, '/') || !str_contains($url, ':')) {
            return true;
        }

        $scheme = strtolower(strtok($url, ':'));

        if (!in_array($scheme, self::ALLOWED_IMG_SCHEMES, true)) {
            return false;
        }

        if ($scheme === 'data') {
            return (bool) preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $url);
        }

        return true;
    }

    /**
     * CSS gets a coarser treatment than HTML: strip comments, refuse the
     * handful of constructs that fetch or execute, and cap the size. Full CSS
     * parsing would buy little — the dangerous surface is small and named.
     *
     * @return array{css: string, removals: array<int,string>}
     */
    public function sanitizeCss(string $css, int $maxBytes = 200000): array
    {
        $removals = [];

        if (strlen($css) > $maxBytes) {
            throw new TemplateException("Stylesheet exceeds the {$maxBytes} byte limit.");
        }

        // Comments can hide `@im/**/port`-style evasion; remove before matching.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        // url() first: an @import's url() would otherwise be neutralised into
        // `none` and leave a bare `@import none;` behind.
        $css = preg_replace_callback(
            '/url\s*\(\s*(["\']?)([^)"\']*)\1\s*\)/i',
            function (array $match) use (&$removals) {
                $target = trim($match[2]);

                if ($this->isSafeCssUrl($target)) {
                    return $match[0];
                }

                $removals[] = 'Removed a stylesheet url() pointing at "' . $target . '". Embed images as data:image URIs instead — the PDF renderer cannot fetch remote files.';

                return 'none';
            },
            $css
        ) ?? '';

        foreach (self::CSS_REMOVAL_PATTERNS as [$pattern, $description]) {
            $count = 0;
            $css = preg_replace($pattern, '', $css, -1, $count) ?? '';

            if ($count > 0) {
                $removals[] = 'Removed ' . $description . ' from the stylesheet.';
            }
        }

        // `</style>` inside the stylesheet would break out of the tag.
        if (stripos($css, '</style') !== false) {
            $css = preg_replace('#</style#i', '', $css) ?? '';
            $removals[] = 'Removed a "</style" sequence from the stylesheet.';
        }

        return ['css' => $css, 'removals' => array_values(array_unique($removals))];
    }
}
