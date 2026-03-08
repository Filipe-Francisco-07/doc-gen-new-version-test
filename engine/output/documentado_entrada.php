<?php
/**
 * Classe responsável por manipulações de strings, como geração de slugs e truncamento de texto.
 */
class __DocGenTemp {

    /**
     * Converte uma string em um formato "slug", substituindo caracteres não alfanuméricos por hífens e convertendo para minúsculas.
     * 
     * @param mixed $text A string a ser convertida em slug.
     * @return mixed A string formatada como slug.
     */
    public function slugify($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Trunca uma string para um limite especificado e adiciona reticências se necessário.
     * 
     * @param mixed $text A string a ser truncada.
     * @param mixed $limit O limite de caracteres para a truncagem.
     * @return mixed A string truncada ou a original se não exceder o limite.
     */
    public function truncate($text, $limit)
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . "...";
    }

}
