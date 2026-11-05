<?php
defined('ABSPATH') || exit;

/** Mise en forme HTML des e-mails générés : tous les mails partent en HTML (le texte brut ne sert que de version de secours). */
class ISPAG_Mail_Html {

    /** Texte brut -> document HTML de mail : texte échappé, adresses et liens cliquables, retours à la ligne conservés. */
    public static function from_text($text) {
        $html = nl2br(esc_html((string) $text), false);
        if (function_exists('make_clickable')) $html = make_clickable($html);
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">' . $html . '</div></body></html>';
    }
}
