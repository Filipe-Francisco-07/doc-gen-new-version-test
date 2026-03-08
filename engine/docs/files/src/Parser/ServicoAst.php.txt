<?php

namespace App\Parser;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser\Php7;

/**
 * Classe responsável por analisar código PHP e arquivos, retornando a árvore sintática abstrata (AST) e mensagens de erro.
 */
final class ServicoAst {
    /**
     * Analisa o código fornecido e retorna a árvore sintática abstrata (AST) junto com mensagens de erro, se houver.
     * 
     * @param string $sCodigo O código fonte a ser analisado.
     * @return array Um array contendo a AST e as mensagens de erro.
     */
    public function analisarCodigo(string $sCodigo): array {
        $oParser = new Php7(new Emulative());
        $oErros  = new Collecting();

        try {
            $aAst = $oParser->parse($sCodigo, $oErros);
        } catch (\Throwable $oEx) {
            $aAst = null;
        }

        $aMsgs = [];
        foreach ($oErros->getErrors() as $oErr) {
            $aMsgs[] = [
                'mensagem'     => $oErr->getMessage(),
                'linha_inicio' => $oErr->getStartLine(),
                'linha_fim'    => $oErr->getEndLine(),
            ];
        }

        if ($aAst === null) {
            return [[], $aMsgs ?: [['mensagem' => 'Falha ao parsear.']]];
        }

        $oTrav = new NodeTraverser();
        $oTrav->addVisitor(new NameResolver());
        $oTrav->traverse($aAst);

        return [$aAst, $aMsgs];
    }

    /**
     * Lê o conteúdo de um arquivo e analisa seu código, retornando os resultados da análise.
     * 
     * @param string $sCaminho O caminho do arquivo a ser lido.
     * @return array Um array contendo os resultados da análise do código ou uma mensagem de erro se o arquivo não for encontrado.
     */
    public function analisarArquivo(string $sCaminho): array
    {
        $sCodigo = @file_get_contents($sCaminho);
        if ($sCodigo === false) {
            return [[], [['mensagem' => "Arquivo nÃ£o encontrado: {$sCaminho}"]]];
        }
        return $this->analisarCodigo($sCodigo);
    }
}
