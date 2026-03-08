<?php


require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

$srcDir = __DIR__ . '/../src';
$testsDir = __DIR__ . '/../tests';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Erro: diretório src/ não encontrado.\n");
    exit(1);
}

if (!is_dir($testsDir)) {
    mkdir($testsDir, 0777, true);
    echo "Criado diretório de testes: $testsDir\n";
}

// Cria parser compatível com versões novas do PHP
$parser = (new ParserFactory())->createForNewestSupportedVersion();

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$totalGerados = 0;
$totalIgnorados = 0;

foreach ($rii as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $srcPath = $file->getPathname();
    $relPath = str_replace([$srcDir . DIRECTORY_SEPARATOR, '.php'], ['', ''], $srcPath);
    $className = str_replace(DIRECTORY_SEPARATOR, '\\', $relPath);
    $testName = basename($file, '.php') . 'Test';
    $testPath = $testsDir . DIRECTORY_SEPARATOR . $testName . '.php';

    // Se o teste já existe, pula
    if (file_exists($testPath)) {
        echo "Teste já existe: $testPath\n";
        $totalIgnorados++;
        continue;
    }

    echo "Gerando teste: $testPath\n";

    $code = <<<PHP
<?php
use PHPUnit\\Framework\\TestCase;
use $className;

/**
 * Testes automatizados para a classe $className.
 */
class $testName extends TestCase
{
    public function testPlaceholder()
    {
        \$this->markTestIncomplete('TODO: implementar testes para $className');
    }
}

PHP;

    file_put_contents($testPath, $code);
    $totalGerados++;
}

echo "\nResumo:\n";
echo "  Testes gerados:   $totalGerados\n";
echo "  Testes existentes: $totalIgnorados\n";
echo "  Diretório de saída: $testsDir\n";
