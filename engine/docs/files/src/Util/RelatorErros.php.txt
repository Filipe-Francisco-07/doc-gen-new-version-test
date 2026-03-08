<?php

namespace Util;

/**
 * Classe responsável por registrar erros em um arquivo JSON.
 */
final class RelatorErros
{
    /**
     * Cria um diretório se ele não existir e grava um arquivo JSON com os erros fornecidos.
     * 
     * @param string $sDir O diretório onde o arquivo de erros será salvo.
     * @param array $aErros A lista de erros a serem gravados no arquivo.
     * @return void
     */
    public function escrever(string $sDir, array $aErros): void
    {
        if (!is_dir($sDir)) {
            mkdir($sDir, 0777, true);
        }
        file_put_contents(
            rtrim($sDir, '/') . '/errors.json',
            json_encode($aErros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
