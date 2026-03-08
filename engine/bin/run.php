<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Parser\Normalizador;
use App\Parser\ServicoAst;
use PhpParser\NodeTraverser;
use Analyser\MarcadorDocumentacao;
use Analyser\ValidadorDocblock;
use Util\InjetorPlaceholder;
use Util\RelatorErros;
use Generator\ConstrutorPrompt;
use Generator\ClienteGPT;
use Generator\AplicadorDocumentacao;

/*
|----------------------------------------------------------------------
| CLI
|----------------------------------------------------------------------
*/

$options = getopt("", ["input:", "base:"]);

$inputPath = $options['input'] ?? (__DIR__ . '/../input/entrada.php');
$inputPath = realpath($inputPath) ?: $inputPath;

$base = $options['base'] ?? pathinfo($inputPath, PATHINFO_FILENAME);

$outDir = __DIR__ . '/../output';

@mkdir($outDir, 0777, true);

foreach (glob($outDir . '/*_' . $base . '.*') as $f) {
    @unlink($f);
}

@unlink($outDir . '/errors.json');

echo "=> Input:  {$inputPath}\n";
echo "=> Output: " . realpath($outDir) . "\n";
echo "=> Base:   {$base}\n";

/*
|----------------------------------------------------------------------
| Load
|----------------------------------------------------------------------
*/

$raw = @file_get_contents($inputPath);

if ($raw === false) {

    (new RelatorErros())->escrever($outDir, [[
        'mensagem' => "Arquivo não encontrado: {$inputPath}",
        'linha_inicio' => 0,
        'linha_fim' => 0
    ]]);

    fwrite(STDERR, "Erro: arquivo de entrada ausente\n");

    exit(1);
}

/*
|----------------------------------------------------------------------
| Normalize
|----------------------------------------------------------------------
*/

[$normalized, $isFragment, $addedLines] =
    (new Normalizador())->normalizar($raw);

/*
|----------------------------------------------------------------------
| AST
|----------------------------------------------------------------------
*/

[$ast, $parseErrors] =
    (new ServicoAst())->analisarCodigo($normalized);

if (!empty($parseErrors)) {

    (new RelatorErros())->escrever($outDir, $parseErrors);

    file_put_contents(
        "{$outDir}/doc_map_{$base}.json",
        json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    exit(1);
}

/*
|----------------------------------------------------------------------
| Map nodes
|----------------------------------------------------------------------
*/

$marker = new MarcadorDocumentacao();

$tr = new NodeTraverser();
$tr->addVisitor($marker);
$tr->traverse($ast);

$items = array_map(function ($it) use ($addedLines) {

    $adj = fn($v) =>
        is_int($v) ? max(1, $v - $addedLines) : $v;

    $it['line'] = $adj($it['line'] ?? 1);
    $it['endLine'] = $adj($it['endLine'] ?? null);
    $it['doc_start'] = $adj($it['doc_start'] ?? null);
    $it['doc_end'] = $adj($it['doc_end'] ?? null);

    return $it;

}, $marker->aItens ?? []);

$validator = new ValidadorDocblock();

$items = array_values(array_filter(
    $items,
    fn($it) => $validator->precisaGerar($it)
));


/*
|----------------------------------------------------------------------
| Fragment → remover classes
|----------------------------------------------------------------------
*/

if ($isFragment) {

    $items = array_values(array_filter(
        $items,
        fn($it) => in_array(
            $it['type'] ?? '',
            ['function','method','property','constant'],
            true
        )
    ));
}

file_put_contents(
    "{$outDir}/doc_map_{$base}.json",
    json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Mapping -> {$outDir}/doc_map_{$base}.json\n";

/*
|----------------------------------------------------------------------
| Placeholders
|----------------------------------------------------------------------
*/

if (!$isFragment) {

    $map = array_map(function ($it) {

        return [
            'id' => $it['id'],
            'line' => max(2, (int)($it['line'] ?? 1)),
            'doc_start' => $it['doc_start'] ?? null,
            'doc_end' => $it['doc_end'] ?? null
        ];

    }, $items);

    $srcWithPH =
        (new InjetorPlaceholder())->injetar($inputPath, $map);

    file_put_contents(
        "{$outDir}/placeholder_{$base}.php",
        $srcWithPH
    );

    echo "Placeholders -> {$outDir}/placeholder_{$base}.php\n";
}

/*
|----------------------------------------------------------------------
| Prompts
|----------------------------------------------------------------------
*/

$constr = new ConstrutorPrompt();

$prompts = [];

foreach ($items as $it) {
    $prompts[$it['id']] = $constr->construir($it, $raw);
}

/*
|----------------------------------------------------------------------
| GPT
|----------------------------------------------------------------------
*/

$apiKey = getenv('OPENAI_API_KEY') ?: '';
$model  = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
$baseUrl = rtrim(
    getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1',
    '/'
);

$docs = [];

if ($apiKey !== '') {

    $cli = new ClienteGPT();

    foreach ($items as $it) {

        $doc = $cli->gerar(
            $baseUrl,
            $apiKey,
            $model,
            $prompts[$it['id']]
        );

        if (!$doc || trim($doc) === '') {

            $doc = "/**\n * Documentação não gerada automaticamente.\n */";
        }

        $docs[$it['id']] = $doc;
    }

} else {

    foreach ($items as $it) {

        $docs[$it['id']] =
            "/**\n * Documentação gerada (FAKE).\n */";
    }
}

file_put_contents(
    "{$outDir}/generated_docs_{$base}.json",
    json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Docs gerados: " . count($docs) . "\n";

/*
|----------------------------------------------------------------------
| Apply docs
|----------------------------------------------------------------------
*/

if (!$isFragment && file_exists("{$outDir}/placeholder_{$base}.php")) {

    $srcPH = file_get_contents(
        "{$outDir}/placeholder_{$base}.php"
    );

    $final =
        (new AplicadorDocumentacao())->aplicar($srcPH, $docs);

    file_put_contents(
        "{$outDir}/documentado_{$base}.php",
        $final
    );

    echo "Documentado -> {$outDir}/documentado_{$base}.php\n";

} else {

    if (!empty($docs)) {

        $preview =
            implode("\n\n", array_values($docs));

        file_put_contents(
            "{$outDir}/preview_patch_{$base}.txt",
            $preview
        );

        echo "Preview -> {$outDir}/preview_patch_{$base}.txt\n";
    }
}