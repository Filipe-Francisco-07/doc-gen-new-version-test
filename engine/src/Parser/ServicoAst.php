<?php

namespace App\Parser;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser\Php7;

/**
 * Classe responsável por analisar código PHP e arquivos, retornando a árvore sintática abstrata (AST),
 * mensagens de erro e, quando necessário, informações estruturais como declarações de tipos.
 */
final class ServicoAst
{
    /**
     * Analisa o código fornecido e retorna a árvore sintática abstrata (AST) junto com mensagens de erro, se houver.
     *
     * @param string $sCodigo O código fonte a ser analisado.
     * @return array{0: array<int, Node>, 1: array<int, array<string, mixed>>}
     *         Um array contendo a AST e a lista de mensagens de erro.
     */
    public function analisarCodigo(string $sCodigo): array
    {
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
     * @return array{0: array<int, Node>, 1: array<int, array<string, mixed>>}
     *         Um array contendo a AST e as mensagens de erro ou uma mensagem de erro se o arquivo não for encontrado.
     */
    public function analisarArquivo(string $sCaminho): array
    {
        $sCodigo = @file_get_contents($sCaminho);
        if ($sCodigo === false) {
            return [[], [['mensagem' => "Arquivo nÃ£o encontrado: {$sCaminho}"]]];
        }
        return $this->analisarCodigo($sCodigo);
    }

    /**
     * Lista todas as declarações de tipos (classes, interfaces, traits, enums)
     * presentes na AST fornecida.
     *
     * @param array<int, Node> $aAst
     * @return string[] Lista de nomes totalmente qualificados (FQN) encontrados.
     */
    private function listarDeclaracoesNaAst(array $aAst): array
    {
        if ($aAst === []) {
            return [];
        }

        $oFinder = new NodeFinder();
        /** @var ClassLike[] $aDecls */
        $aDecls = $oFinder->findInstanceOf($aAst, ClassLike::class);

        $aResultado = [];

        foreach ($aDecls as $oDecl) {
            $sFqn = $this->resolverNomeCompleto($oDecl);
            if ($sFqn === null || $sFqn === '') {
                continue;
            }
            $aResultado[] = $sFqn;
        }

        return array_values(array_unique($aResultado));
    }

    /**
     * Resolve o nome totalmente qualificado (FQN) de uma declaração de tipo.
     *
     * Utiliza o atributo "namespacedName" inserido pelo NameResolver.
     *
     * @param ClassLike $oNo
     * @return string|null
     */
    private function resolverNomeCompleto(ClassLike $oNo): ?string
    {
        if (property_exists($oNo, 'namespacedName') && $oNo->namespacedName instanceof Node\Name) {
            return $oNo->namespacedName->toString();
        }

        if ($oNo->name !== null) {
            return $oNo->name->toString();
        }

        return null;
    }

    /**
     * Analisa um arquivo e lista as declarações contidas na sua AST.
     * 
     * @param string $sCaminho O caminho do arquivo a ser analisado.
     * @return array Retorna um array com as declarações encontradas na AST.
     */
    public function listarDeclaracoesEmArquivo(string $sCaminho): array
    {
        [$aAst, $aErros] = $this->analisarArquivo($sCaminho);

        if ($aAst === [] || !empty($aErros) && empty($aAst)) {
            // Se a AST estiver vazia ou houve erro crítico, não há declarações válidas.
            return [];
        }

        return $this->listarDeclaracoesNaAst($aAst);
    }
}
