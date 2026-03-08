<?php
{{doc_1}}
class __DocGenTemp {

{{doc_2}}
    public function slugify($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

{{doc_3}}
    public function truncate($text, $limit)
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . "...";
    }

}
