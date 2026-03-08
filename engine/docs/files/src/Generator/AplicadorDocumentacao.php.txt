<?php

namespace Generator;

/**
 * Classe responsável por aplicar documentação a partir de um conteúdo e um conjunto de documentos.
 */
final class AplicadorDocumentacao {

    /**
     * Processa o conteúdo fornecido, substituindo marcadores de documentação por suas respectivas representações em DocBlock.
     * 
     * @param string $sConteudo O conteúdo que contém os marcadores de documentação.
     * @param array $aDocs Um array associativo que mapeia identificadores de documentação para suas representações.
     * @return string O conteúdo processado com os DocBlocks inseridos.
     */
    public function aplicar(string $sConteudo, array $aDocs): string {
        $sConteudo = str_replace("\r\n", "\n", $sConteudo);
        $aLinhas   = explode("\n", $sConteudo);

        $iTotal = count($aLinhas);
        for ($i = 0; $i < $iTotal; $i++) {
            $sLinha = $aLinhas[$i];

            if (!preg_match('/^\s*\{\{\s*(doc_[A-Za-z0-9_-]+)\s*\}\}\s*$/', $sLinha, $m)) {
                continue;
            }
            $sId = $m[1];
            if (!isset($aDocs[$sId])) {
                continue;
            }
            preg_match('/^([ \t]*)/', $sLinha, $mi);
            $sIndent = $mi[1] ?? '';

            if ($sIndent === '' && $i + 1 < $iTotal) {
                $j = $i + 1;
                while ($j < $iTotal && trim($aLinhas[$j]) === '') {
                    $j++;
                }
                if ($j < $iTotal && preg_match('/^([ \t]+)/', $aLinhas[$j], $mn)) {
                    $sIndent = $mn[1];
                }
            }

            $aLinhas[$i] = $this->paraDocblockComIdentacao($aDocs[$sId], $sIndent);
        }
        return implode("\n", $aLinhas);
    }

    /**
     * Formata um texto em um DocBlock com a indentação especificada.
     * 
     * @param string $sTexto O texto a ser formatado como DocBlock.
     * @param string $sIndent A string de indentação a ser aplicada a cada linha do DocBlock.
     * @return string O texto formatado como DocBlock com a indentação aplicada.
     */
    private function paraDocblockComIdentacao(string $sTexto, string $sIndent = ''): string {
        $sTexto = trim(str_replace("\r\n", "\n", $sTexto));

        if (!str_starts_with($sTexto, '/**')) {
            $aLinhas = $sTexto === '' ? ['DocumentaÃ§Ã£o gerada.'] : explode("\n", $sTexto);
            $aLinhas = array_map(fn ($l) => ' * ' . ltrim(preg_replace('/^\*\s*/', '', $l)), $aLinhas);
            $sTexto  = "/**\n" . implode("\n", $aLinhas) . "\n */";
        }

        $aOut = array_map(fn ($l) => $sIndent . $l, explode("\n", $sTexto));
        return implode("\n", $aOut);
    }
}
