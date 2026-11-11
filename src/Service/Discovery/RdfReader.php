<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use RuntimeException;

/** Reads N-Triples without retaining a cache of every RDF term in the export. */
final class RdfReader
{
    private const TRIPLE = <<<'REGEX'
        ~^\h*(<[^>]*>|_:\S+)\h+<([^>]*)>\h+(.+?)\h*\.\h*(?:\#.*)?\h*\r?$~u
        REGEX;

    private const LITERAL = <<<'REGEX'
        ~^"((?:[^"\\]|\\(?:[tbnrf"'\x5c]|u[0-9a-fA-F]{4}|U[0-9a-fA-F]{8}))*)"(?:@([a-zA-Z]+(?:-[a-zA-Z0-9]+)*)|\^\^<([^>]*)>)?$~u
        REGEX;

    public function graph(string $body): array
    {
        $graph = [];
        foreach (explode("\n", $body) as $index => $line) {
            $triple = $this->triple($line, $index + 1);
            if ($triple !== null) {
                [$subject, $fact] = $triple;
                $graph[$subject][] = $fact;
            }
        }
        return $graph;
    }

    /** Return the subject and fact for one line, or null for whitespace and comments. */
    public function triple(string $line, int $number): ?array
    {
        $line = rtrim($line, "\r\n");
        $content = ltrim($line);
        if ($content === '' || str_starts_with($content, '#')) {
            return null;
        }
        if (!preg_match(self::TRIPLE, $line, $parts)) {
            throw new RuntimeException(sprintf('Invalid N-Triples statement on line %d.', $number));
        }

        [, $subject, $property, $object] = $parts;
        $subject = $subject[0] === '<' ? $this->decode(substr($subject, 1, -1)) : $subject;
        $fact = [
            'property' => $this->decode($property),
            'value' => $object,
            'lang' => '',
            'datatype' => null,
            'is_uri' => false,
            'is_blank' => false,
        ];

        if (preg_match('~^<([^<>]*)>$~u', $object, $iri)) {
            $fact['is_uri'] = true;
            $fact['value'] = $this->decode($iri[1]);
        } elseif (preg_match('~^_:\S+$~u', $object)) {
            $fact['is_blank'] = true;
        } elseif (preg_match(self::LITERAL, $object, $literal, PREG_UNMATCHED_AS_NULL)) {
            $fact['value'] = $this->decode($literal[1]);
            $fact['lang'] = strtolower($literal[2] ?? '');
            $datatype = isset($literal[3]) ? $this->decode($literal[3]) : null;
            $fact['datatype'] = $datatype === 'http://www.w3.org/2001/XMLSchema#string' ? null : $datatype;
        } else {
            throw new RuntimeException(sprintf('Invalid N-Triples object on line %d.', $number));
        }

        return [$subject, $fact];
    }

    private function decode(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }

        return preg_replace_callback(
            '~\\\\(u[0-9a-fA-F]{4}|U[0-9a-fA-F]{8}|[tbnrf"\x27\x5c])~u',
            static function (array $match): string {
                $escape = $match[1];
                if ($escape[0] === 'u' || $escape[0] === 'U') {
                    $code = hexdec(substr($escape, 1));
                    if ($code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff)) {
                        throw new RuntimeException('Invalid RDF Unicode code point.');
                    }
                    return mb_chr($code, 'UTF-8');
                }

                return match ($escape) {
                    't' => "\t",
                    'b' => "\x08",
                    'n' => "\n",
                    'r' => "\r",
                    'f' => "\f",
                    default => $escape,
                };
            },
            $value,
        );
    }
}
