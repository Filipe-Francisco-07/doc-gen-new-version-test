<?php

namespace Generator;

final class ClienteGPT
{
    public function gerar(string $sBase, string $sApiKey, string $sModelo, string $sPrompt): ?string
    {
        $sUrl = rtrim($sBase, '/') . '/chat/completions';

        $aPayload = [
            'model' => $sModelo,
            'messages' => [
                ['role' => 'system','content' => 'Você gera apenas DocBlocks PHPDoc válidos.'],
                ['role' => 'user','content' => $sPrompt],
            ],
            'temperature' => 0.2,
        ];

        $logDir = __DIR__ . '/../../output';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . '/gpt_debug.log';

        file_put_contents(
            $logFile,
            "=== REQ ===\n" . json_encode($aPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );

        $h = curl_init($sUrl);

        $cacert = getenv('CURL_CA_BUNDLE') ?: 'C:\\php\\extras\\ssl\\cacert.pem';

        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $sApiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($aPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $cacert
        ]);

        $sResp = curl_exec($h);
        $iCode = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $err = curl_error($h);

        curl_close($h);

        file_put_contents(
            $logFile,
            "\n=== HTTP $iCode ===\n$sResp\nErro: $err\n",
            FILE_APPEND
        );

        if ($sResp === false || $iCode >= 400) {
            return null;
        }

        $aJson = json_decode($sResp, true);

        if (!isset($aJson['choices'][0]['message']['content'])) {
            file_put_contents($logFile, "\n[ERRO] JSON sem campo esperado.\n", FILE_APPEND);
            return null;
        }

        $sDoc = trim($aJson['choices'][0]['message']['content']);

        if ($sDoc === '') return null;

        if (!str_starts_with($sDoc, '/**')) {
            $sDoc = "/**\n * " . preg_replace('/^\*?\s*/m', '* ', $sDoc) . "\n */";
        }

        file_put_contents($logFile, "\n=== DOC ===\n$sDoc\n", FILE_APPEND);

        return $sDoc;
    }
}