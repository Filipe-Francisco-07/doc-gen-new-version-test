<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Parser\ServicoAst;
use App\Parser\Normalizador;
use Analyser\MarcadorDocumentacao;
use Generator\ConstrutorPrompt;
use Generator\ClienteGPT;
use Generator\AplicadorDocumentacao;
use Util\InjetorPlaceholder;
use Util\RelatorErros;
use PhpParser\NodeTraverser;

/**
 * Script de orquestração da geração automática de documentação PHPDoc.
 *
 * Responsabilidades principais:
 *  - Percorrer os arquivos PHP do projeto (pasta src/ por padrão);
 *  - Identificar elementos (classes, métodos, funções, etc.) sem DocBlock;
 *  - Gerar prompts estruturados a partir da AST e do contexto de código;
 *  - Consultar a API de IA (ClienteGPT) para obter DocBlocks PHPDoc válidos;
 *  - Injetar placeholders e aplicar os DocBlocks gerados no código-fonte;
 *  - Registrar erros em output/errors.json;
 *  - Gerar um catálogo de classes em output/catalogo_classes.json;
 *  - Manter output/documentation_state.json com histórico dos itens documentados.
 */

// -----------------------------------------------------------------------------
// Configuração de ambiente e dependências
// -----------------------------------------------------------------------------

$rootDir = __DIR__;
$srcDir  = $rootDir . '/src';

$openaiKey   = getenv('OPENAI_API_KEY') ?: '';
$openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
$openaiBase  = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';

// Instâncias dos serviços centrais
$normalizador   = new Normalizador();
$servicoAst     = new ServicoAst();
$clienteGPT     = new ClienteGPT();
$construtor     = new ConstrutorPrompt();
$aplicador      = new AplicadorDocumentacao();
$injetor        = new InjetorPlaceholder();
$relatorErros   = new RelatorErros();

$errosGlobais = [];

// Estado de documentação acumulado
$stateFile = $rootDir . '/output/documentation_state.json';
$documentationState = [];
if (is_file($stateFile)) {
    $rawState = @file_get_contents($stateFile);
    if ($rawState !== false) {
        $decoded = json_decode($rawState, true);
        if (is_array($decoded)) {
            $documentationState = $decoded;
        }
    }
}

/**
 * Apenas log simples em STDERR, para CI e execução manual.
 *
 * @param string $msg
 * @return void
 */
function logDocgen(string $msg): void
{
    fwrite(STDERR, "[DocGen] {$msg}\n");
}

// -----------------------------------------------------------------------------
// 1) Geração de documentação automática (se houver credenciais de IA)
// -----------------------------------------------------------------------------

if ($openaiKey === '') {
    logDocgen('OPENAI_API_KEY não definido. Pulando etapa de geração automática de DocBlocks.');
} else {
    logDocgen("Iniciando geração automática de documentação em {$srcDir}");

    $dirIterator = new RecursiveDirectoryIterator(
        $srcDir,
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
    );
    $iterator = new RecursiveIteratorIterator($dirIterator);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $filePath = $fileInfo->getPathname();
        $code     = @file_get_contents($filePath);

        if ($code === false) {
            $errosGlobais[$filePath][] = ['mensagem' => 'Falha ao ler arquivo para documentação.'];
            continue;
        }

        // Para arquivos reais de src, normalmente já começam com <?php, então
        // o normalizador tende a devolver o código como está.
        [$codigoNormalizado, $ehFragmento, $linhasAdicionadas] = $normalizador->normalizar($code);

        // Análise de AST sobre o código normalizado
        [$ast, $erros] = $servicoAst->analisarCodigo($codigoNormalizado);
        if ($ast === [] && !empty($erros)) {
            $errosGlobais[$filePath] = array_merge($errosGlobais[$filePath] ?? [], $erros);
            continue;
        }

        // Visitor que coleta metadados de documentação
        $marcador = new MarcadorDocumentacao();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($marcador);
        $traverser->traverse($ast);

        $itens = $marcador->aItens;

        // Seleciona apenas itens sem DocBlock atual
        $itensSemDoc = array_values(array_filter(
            $itens,
            static fn(array $item): bool => empty($item['doc'])
        ));

        if ($itensSemDoc === []) {
            // Nada a documentar neste arquivo
            continue;
        }

        // Ajuste de linhas em caso de fragmento embrulhado pelo Normalizador
        if ($ehFragmento) {
            foreach ($itensSemDoc as &$item) {
                $delta = $linhasAdicionadas;
                $item['line']      = max(1, (int)$item['line'] - $delta);
                if (!empty($item['doc_start'])) {
                    $item['doc_start'] = max(1, (int)$item['doc_start'] - $delta);
                }
                if (!empty($item['doc_end'])) {
                    $item['doc_end'] = max(1, (int)$item['doc_end'] - $delta);
                }
            }
            unset($item);
        }

        // Índice auxiliar por ID para mapear depois no estado
        $itensPorId = [];
        foreach ($itensSemDoc as $item) {
            $itensPorId[$item['id']] = $item;
        }

        // Prepara mapa para o InjetorPlaceholder
        $mapa = [];
        foreach ($itensSemDoc as $item) {
            $mapa[] = [
                'id'        => $item['id'],
                'line'      => $item['line'],
                'doc_start' => $item['doc_start'],
                'doc_end'   => $item['doc_end'],
            ];
        }

        // Insere placeholders no arquivo original
        $conteudoComPlaceholders = $injetor->injetar($filePath, $mapa);
        if ($conteudoComPlaceholders === '') {
            $errosGlobais[$filePath][] = ['mensagem' => 'Falha ao injetar placeholders.'];
            continue;
        }

        // Geração de DocBlocks via IA
        $docsGerados = [];
        foreach ($itensSemDoc as $item) {
            $prompt = $construtor->construir($item, $code);
            $doc    = $clienteGPT->gerar($openaiBase, $openaiKey, $openaiModel, $prompt);

            if ($doc === null) {
                $errosGlobais[$filePath][] = [
                    'mensagem' => 'Falha ao gerar DocBlock para ' . ($item['fqn'] ?? $item['name'] ?? $item['id']),
                ];
                continue;
            }

            $docsGerados[$item['id']] = $doc;
        }

        if ($docsGerados === []) {
            // Não conseguiu gerar nada útil para este arquivo
            continue;
        }

        // Aplica os DocBlocks nos lugares dos placeholders
        $novoConteudo = $aplicador->aplicar($conteudoComPlaceholders, $docsGerados);

        if ($novoConteudo === '' || $novoConteudo === $code) {
            // Nada mudou ou falhou; não sobrescrever
            continue;
        }

        file_put_contents($filePath, $novoConteudo);
        logDocgen("Documentação aplicada em: {$filePath}");

        // Atualiza estado de documentação para os itens que receberam doc
        atualizarEstadoDocumentacao(
            $documentationState,
            $filePath,
            $code,
            $docsGerados,
            $itensPorId
        );
    }
}

// -----------------------------------------------------------------------------
// 2) Registro agregado de erros (se houver)
// -----------------------------------------------------------------------------

if (!empty($errosGlobais)) {
    $relatorErros->escrever($rootDir . '/output', $errosGlobais);
    logDocgen('Erros registrados em output/errors.json');
} else {
    logDocgen('Nenhum erro relevante registrado durante a execução.');
}

// -----------------------------------------------------------------------------
// 3) Geração do catálogo de classes (estrutural x domínio)
// -----------------------------------------------------------------------------

gerarCatalogoClasses($rootDir, $servicoAst);
logDocgen('Catálogo de classes gerado em output/catalogo_classes.json.');

// -----------------------------------------------------------------------------
// 4) Persistência do estado de documentação
// -----------------------------------------------------------------------------

salvarEstadoDocumentacao($rootDir, $stateFile, $documentationState);
logDocgen('Estado de documentação salvo em output/documentation_state.json.');

exit(0);

/**
 * Gera um catálogo de classes do projeto, classificando-as por tipo
 * (domínio ou estrutural) com base no caminho do arquivo.
 *
 * O catálogo resultante é gravado em output/catalogo_classes.json.
 *
 * @param string      $projectRoot
 * @param ServicoAst  $servicoAst
 * @return void
 */
function gerarCatalogoClasses(string $projectRoot, ServicoAst $servicoAst): void
{
    $srcRoot = $projectRoot . '/src';

    $domainHints = [
        '/Domain/',
        '/Model/',
    ];

    $structuralHints = [
        '/Infrastructure/',
        '/Support/',
        '/Util/',
        '/Generator/',
        '/Analyser/',
        '/App/Parser/',
    ];

    $catalogo = [];

    if (!is_dir($srcRoot)) {
        logDocgen("Diretório src não encontrado em {$srcRoot}. Catálogo de classes não será gerado.");
        return;
    }

    $dirIterator = new RecursiveDirectoryIterator(
        $srcRoot,
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
    );
    $iterator = new RecursiveIteratorIterator($dirIterator);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $fileInfo->getPathname());

        // Ignora vendor e tests mesmo que estejam abaixo de src por algum motivo
        if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
            continue;
        }

        $fqnList = $servicoAst->listarDeclaracoesEmArquivo($path);
        if ($fqnList === []) {
            continue;
        }

        foreach ($fqnList as $fqn) {
            $tipo = 'dominio';

            foreach ($structuralHints as $hint) {
                if (str_contains($path, $hint)) {
                    $tipo = 'estrutural';
                    break;
                }
            }

            foreach ($domainHints as $hint) {
                if (str_contains($path, $hint)) {
                    $tipo = 'dominio';
                    break;
                }
            }

            $catalogo[$fqn] = [
                'file' => $path,
                'tipo' => $tipo,
            ];
        }
    }

    if (!is_dir($projectRoot . '/output')) {
        mkdir($projectRoot . '/output', 0777, true);
    }

    $jsonPath = $projectRoot . '/output/catalogo_classes.json';

    file_put_contents(
        $jsonPath,
        json_encode($catalogo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Atualiza o estado de documentação para os itens que receberam DocBlock.
 *
 * @param array<string, array<string,mixed>> $state
 * @param string                             $filePath
 * @param string                             $codigoOriginal
 * @param array<string,string>               $docsGerados  [id => docblock]
 * @param array<string,array<string,mixed>>  $itensPorId   [id => item]
 * @return void
 */
function atualizarEstadoDocumentacao(
    array &$state,
    string $filePath,
    string $codigoOriginal,
    array $docsGerados,
    array $itensPorId
): void {
    if ($docsGerados === []) {
        return;
    }

    $linhas = preg_split('/\R/u', $codigoOriginal) ?: [];

    foreach ($docsGerados as $id => $doc) {
        if (!isset($itensPorId[$id])) {
            continue;
        }

        $item = $itensPorId[$id];

        $start = max(1, (int)($item['line'] ?? 1));
        $end   = max($start, (int)($item['endLine'] ?? $start));

        $snippet = extrairTrechoPorLinhas($linhas, $start, $end);
        $bodyHash = hash('sha256', $snippet);
        $docHash  = hash('sha256', $doc);

        $key = $item['fqn'] ?? ($item['name'] ?? $id);

        $state[$key] = [
            'file'        => normalizarCaminho($filePath),
            'type'        => $item['type'] ?? null,
            'fqn'         => $item['fqn'] ?? null,
            'line'        => $start,
            'endLine'     => $end,
            'body_hash'   => $bodyHash,
            'doc_hash'    => $docHash,
            'last_update' => date(DATE_ATOM),
            'status'      => 'documentado',
        ];
    }
}

/**
 * Extrai um trecho de código com base em linhas inicial e final (1-based).
 *
 * @param string[] $linhas
 * @param int      $startLine
 * @param int      $endLine
 * @return string
 */
function extrairTrechoPorLinhas(array $linhas, int $startLine, int $endLine): string
{
    if ($linhas === []) {
        return '';
    }

    $startIdx = max(0, $startLine - 1);
    $len      = max(1, $endLine - $startLine + 1);

    $slice = array_slice($linhas, $startIdx, $len);
    return implode("\n", $slice);
}

/**
 * Normaliza um caminho de arquivo para um formato consistente.
 *
 * @param string $caminho
 * @return string
 */
function normalizarCaminho(string $caminho): string
{
    return str_replace('\\', '/', $caminho);
}

/**
 * Persiste o estado de documentação em disco.
 *
 * @param string $rootDir
 * @param string $stateFile
 * @param array<string, array<string,mixed>> $state
 * @return void
 */
function salvarEstadoDocumentacao(string $rootDir, string $stateFile, array $state): void
{
    if (!is_dir($rootDir . '/output')) {
        mkdir($rootDir . '/output', 0777, true);
    }

    file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}
