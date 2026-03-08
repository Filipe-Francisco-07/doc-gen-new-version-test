<?php

namespace App\Parser;

/**
 * Classe responsável por normalizar trechos de código PHP, 
 * identificando e formatando diferentes estruturas de código.
 */
final class Normalizador
{
    /**
     * @return array{0:string,1:bool,2:int}
     *         [codigo_normalizado, eh_fragmento, linhas_adicionadas]
     */
    public function normalizar(string $raw): array
    {
        $s = ltrim($raw);

        if (preg_match('/^\<\?php\b/u', $s)) {
            return [$raw, false, 0];
        }

        $linhasAdd = 0;
        $isFrag = true;

        $startsWith = fn (string $re) => (bool)preg_match($re . 'u', $s);

        $wrapAsFile = function (string $body) use (&$linhasAdd): string {
            $prefix = "<?php\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . $body . "\n";
        };

        $wrapAsFunction = function (string $body) use (&$linhasAdd): string {
            $prefix = "<?php\nfunction __tmp__() {\n";
            $suffix = "\n}\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . rtrim($body) . $suffix;
        };

        $wrapAsClassMethod = function (string $method) use (&$linhasAdd): string {
            $prefix = "<?php\nclass __Tmp__ {\n";
            $suffix = "\n}\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . rtrim($method) . $suffix;
        };

        // 1) Fragments que jÃ¡ parecem "arquivo" (namespace/declaraÃ§Ãµes topo)
        if ($startsWith('/^(namespace\s+[A-Za-z0-9_\\\\]+;\s*)/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^(use\s+[A-Za-z0-9_\\\\]+(?:\s+as\s+[A-Za-z0-9_]+)?\s*;)/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^(class|interface|trait|enum)\b/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^function\b/')) {
            // FunÃ§Ã£o solta Ã© vÃ¡lida no topo do arquivo
            return [$wrapAsFile($s), true, $linhasAdd];
        }

        // 2) MÃ©todo de classe selecionado (public/protected/private function â€¦)
        if ($startsWith('/^(public|protected|private)\s+function\b/')) {
            return [$wrapAsClassMethod($s), true, $linhasAdd];
        }

        // 3) Propriedade de classe selecionada (public/private/protected â€¦;)
        if ($startsWith('/^(public|protected|private)\b/')) {
            // ainda que nÃ£o seja function, tratamos como trecho de classe
            return [$wrapAsClassMethod($s), true, $linhasAdd];
        }

        // 4) SÃ³ o corpo (bloco) â€” embrulhar como funÃ§Ã£o temporÃ¡ria
        // HeurÃ­stica: contÃ©m ; ou {â€¦} mas nÃ£o declaraÃ§Ãµes conhecidas
        if ($startsWith('/[;{}]/')) {
            return [$wrapAsFunction($s), true, $linhasAdd];
        }

        // 5) Fallback: arquivo simples
        return [$wrapAsFile($s), true, $linhasAdd];
    }
}
