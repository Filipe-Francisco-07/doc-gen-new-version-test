<?php

/**
 * Versão mínima do gerador de dashboard.
 *
 * Objetivo agora: garantir que SEMPRE exista docs/dashboard.html
 * para o GitHub Pages publicar, sem depender de JSON ou funções
 * mais novas. Depois de confirmado, dá pra enriquecer o conteúdo.
 */

$rootDir = dirname(__DIR__);      // pasta raiz do repositório
$docsDir = $rootDir . '/docs';

if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0777, true);
}

$dashboardPath = $docsDir . '/dashboard.html';
$now = date('Y-m-d H:i:s');

$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Dashboard de Documentação - PHP DocGen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #020617;
            color: #e5e7eb;
            padding: 24px;
        }
        .container { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 1.8rem; margin-bottom: 4px; }
        p.sub { color: #9ca3af; font-size: 0.9rem; margin-top: 0; margin-bottom: 24px; }
        .card {
            background: #0b1220;
            border-radius: 12px;
            padding: 16px 18px;
            border: 1px solid #1f2937;
            box-shadow: 0 18px 40px rgba(0,0,0,0.45);
        }
        .muted { color: #9ca3af; font-size: 0.9rem; }
        a {
            color: #38bdf8;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
        .footer {
            margin-top: 18px;
            font-size: 0.75rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Dashboard de Documentação</h1>
    <p class="sub">
        Página de dashboard gerada automaticamente pelo workflow do PHP DocGen.
    </p>

    <div class="card">
        <p class="muted">
            Esta é uma versão mínima de teste do dashboard, apenas para garantir
            que o arquivo <code>dashboard.html</code> exista e seja publicado
            pelo GitHub Pages.
        </p>
        <p class="muted">
            Gerado em: <strong>{$now}</strong>
        </p>
        <p class="muted">
            Após confirmar que a URL
            <code>/tcc-phpdocgen/dashboard.html</code> funciona, o conteúdo
            desta página poderá ser substituído por uma visão detalhada usando
            os arquivos <code>output/catalogo_classes.json</code> e
            <code>output/documentation_state.json</code>.
        </p>
        <p class="muted">
            Voltar para a documentação principal:
            <a href="index.html">index.html</a>
        </p>
    </div>

    <div class="footer">
        PHP DocGen · Dashboard mínimo de verificação
    </div>
</div>
</body>
</html>
HTML;

file_put_contents($dashboardPath, $html);

// Também tenta injetar um link simples no index.html, se existir
$indexPath = $docsDir . '/index.html';
if (is_file($indexPath)) {
    $idxHtml = file_get_contents($indexPath);
    if ($idxHtml !== false && strpos($idxHtml, 'dashboard.html') === false) {
        $snippet = '<p><a href="dashboard.html">Dashboard de documentação (PHP DocGen)</a></p>';
        $pattern = '/(<h1[^>]*>\\s*Documentation\\s*<\\/h1>)/i';
        $idxNew  = preg_replace($pattern, "$1\n{$snippet}", $idxHtml, 1);
        if ($idxNew !== null) {
            file_put_contents($indexPath, $idxNew);
        }
    }
}

echo "[Dashboard] Arquivo gerado em {$dashboardPath}\n";
