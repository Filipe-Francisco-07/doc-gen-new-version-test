<?php
class __DocGenTemp {

    public function slugify($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    public function truncate($text, $limit)
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . "...";
    }

}
