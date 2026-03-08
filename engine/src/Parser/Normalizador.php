<?php

namespace App\Parser;

final class Normalizador
{
    public function normalizar(string $raw): array
    {
        $s = ltrim($raw);

        if (preg_match('/^\<\?php\b/u', $s)) {
            return [$raw, false, 0];
        }

        $linhasAdd = 0;
        $isFragment = true;

        $wrap = function (string $prefix, string $body, string $suffix = "") use (&$linhasAdd): string {
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . rtrim($body) . $suffix;
        };

        if (preg_match('/^(namespace|use)\b/u', $s)) {
            return [$wrap("<?php\n", $s, "\n"), $isFragment, $linhasAdd];
        }

        if (preg_match('/^(class|interface|trait|enum)\b/u', $s)) {
            return [$wrap("<?php\n", $s, "\n"), $isFragment, $linhasAdd];
        }

        if (preg_match('/^function\b/u', $s)) {
            return [$wrap("<?php\n", $s, "\n"), $isFragment, $linhasAdd];
        }

        if (preg_match('/^(public|protected|private)/u', $s)) {
            return [
                $wrap(
                    "<?php\nclass __Tmp__ {\n",
                    $s,
                    "\n}\n"
                ),
                $isFragment,
                $linhasAdd
            ];
        }

        return [
            $wrap(
                "<?php\nfunction __tmp__() {\n",
                $s,
                "\n}\n"
            ),
            $isFragment,
            $linhasAdd
        ];
    }
}