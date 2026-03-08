<?php

namespace Analyser;

final class ValidadorDocblock
{
    public function precisaGerar(array $item): bool
    {
        $doc = $item['doc'] ?? null;

        if (!$doc) {
            return true;
        }

        $doc = trim($doc);

        if ($doc === '/** */') {
            return true;
        }

        $type = $item['type'] ?? '';

        if ($type === 'method' || $type === 'function') {
            return $this->docMetodoIncompleta($doc, $item);
        }

        if ($type === 'property') {
            return !str_contains($doc, '@var');
        }

        return false;
    }

    private function docMetodoIncompleta(string $doc, array $item): bool
    {
        $params = $item['params'] ?? [];

        foreach ($params as $p) {
            if (!str_contains($doc, '@param')) {
                return true;
            }
        }

        if (!str_contains($doc, '@return')) {
            return true;
        }

        return false;
    }
}