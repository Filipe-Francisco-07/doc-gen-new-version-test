<?php

namespace Generator;

/**
 * Classe responsável por gerar DocBlocks PHPDoc a partir de informações fornecidas em um array e um contexto de código.
 */
final class ConstrutorPrompt {

    /**
     * Gera um DocBlock PHPDoc válido com base nas informações fornecidas.
     * 
     * Este método processa um item de array e um código de contexto, extraindo informações relevantes e formatando-as em um JSON.
     * 
     * @param array $aItem Informações do item, incluindo tipo, FQN, parâmetros e tipo de retorno.
     * @param string $sCodigoContexto Código de contexto onde o item está localizado.
     * @return string Retorna uma string formatada em JSON com os metadados do item.
     */
    public function construir(array $aItem, string $sCodigoContexto): string {
        $sTipo  = $aItem['type'] ?? 'desconhecido';
        $sFqn   = $aItem['fqn']  ?? ($aItem['name'] ?? '');
        $iIniLn = (int)($aItem['line'] ?? 1);
        $iFimLn = (int)($aItem['endLine'] ?? ($iIniLn + 1));

        $aLinhas = preg_split('/\R/u', $sCodigoContexto);
        $iIni0   = max(0, $iIniLn - 1);
        $iFim0   = min(count($aLinhas), $iFimLn);
        $sTrecho = implode("\n", array_slice($aLinhas, $iIni0, $iFim0 - $iIni0));

        $aMeta = [
            'type'        => $sTipo,
            'fqn'         => $sFqn,
            'params'      => $aItem['params'] ?? [],
            'returnType'  => $aItem['returnType'] ?? null,
            'modificadores' => $aItem['modificadores'] ?? [],
            'atributos'   => $aItem['atributos'] ?? [],
            'heranca'     => $aItem['heranca'] ?? null,
            'tipos_uso'   => $aItem['tipos_uso'] ?? [],
            'operadores'  => $aItem['operadores'] ?? [],
            'operacao_principal' => $aItem['operacao_principal'] ?? null,
            'throws'      => $aItem['throws'] ?? [],
            'efeitos'     => $aItem['efeitos_colaterais'] ?? [],
            'retornos'    => $aItem['retornos'] ?? [],
            'complexidade' => $aItem['complexidade'] ?? [],
            'checagens'   => $aItem['checagens'] ?? [],
            'linhas'      => ['start' => $iIniLn, 'end' => $iFimLn, 'loc' => max(1, $iFimLn - $iIniLn + 1)],
            'chamadas'    => array_values(array_slice($aItem['chamadas'] ?? [], 0, 10)),
        ];
        $sMetaJson = json_encode($aMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sAss = $sTipo . ' ' . $sFqn;
        $sRet = $aItem['returnType'] ?? 'mixed';

        $sRegras = match ($sTipo) {
            'function','method' =>
                "- Descreva objetivamente o que o corpo FAZ, nÃ£o o nome.\n"
                . "- Uma frase de descriÃ§Ã£o. Linha em branco.\n"
                . "- @param para cada parÃ¢metro na ordem, com propÃ³sito.\n"
                . "- @return {$sRet} coerente com o corpo.\n"
                . "- NÃ£o invente @throws. SÃ³ inclua se houver throw/declaraÃ§Ã£o visÃ­vel.",
            'class','interface','trait','enum' =>
                "- Papel/responsabilidade em 1â€“2 linhas. Sem @param/@return.",
            'property' =>
                "- DescriÃ§Ã£o curta. Use @var <tipo> descriÃ§Ã£o. Sem @param/@return.",
            'constant' =>
                "- DescriÃ§Ã£o curta. Sem @param/@return.",
            default =>
                "- DescriÃ§Ã£o curta baseada no corpo/metadata.",
        };

        return <<<PROMPT
Gere APENAS um DocBlock PHPDoc vÃ¡lido entre /** e */. NÃ£o use crases.
Se metadados e nomes divergirem do corpo, documente PELO CORPO.

Alvo: {$sAss} (linhas {$iIniLn}-{$iFimLn})

REGRAS:
{$sRegras}

METADADOS (JSON):
{$sMetaJson}

TRECHO DO CÃ“DIGO (inÃ­cioâ†’fim do elemento):
{$sTrecho}
PROMPT;
    }
}
