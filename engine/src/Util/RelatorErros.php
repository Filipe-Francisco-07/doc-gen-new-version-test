<?php

namespace Util;

final class RelatorErros
{
    public function escrever(string $dir, array $erros): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            rtrim($dir, '/') . '/errors.json',
            json_encode($erros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}