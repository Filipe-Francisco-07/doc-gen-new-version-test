<?php
/**
 * Classe responsável por gerar slugs a partir de textos.
 */
class __DocGenTemp {

    /**
     * Converte uma string em um formato amigável para URLs, substituindo caracteres não alfanuméricos por hífens.
     * 
     * @param mixed $text A string a ser convertida.
     * @return mixed A string formatada para uso em URLs.
     */
    public function slugify($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }


}
