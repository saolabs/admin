<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

/** Deliberately small HTML vocabulary: no scripts, styles, embeds or arbitrary attributes. */
final class RichText
{
    public static function clean(string $html): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $body = $document->getElementsByTagName('body')->item(0);
        $render = function (\DOMNode $node) use (&$render): string {
            if ($node instanceof \DOMText) return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (!$node instanceof \DOMElement) return '';
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'math', 'template'], true)) return '';
            $children = implode('', array_map($render, iterator_to_array($node->childNodes)));
            if (!in_array($tag, ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a'], true)) return $children;
            $href = $tag === 'a' ? SafeUrlRule::safe($node->getAttribute('href')) : '';
            $attributes = $href === '' ? '' : ' href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="noopener noreferrer"';
            return '<'.$tag.$attributes.'>'.($tag === 'br' ? '' : $children.'</'.$tag.'>');
        };
        return $body ? implode('', array_map($render, iterator_to_array($body->childNodes))) : '';
    }
}
