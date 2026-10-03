<?php
defined('ABSPATH') || exit;

/**
 * Brouillon d'e-mail (.eml) des demandes de facture d'un projet (situation / facture finale),
 * avec la commande client en pièce jointe.
 *
 * Un lien mailto: ne peut pas joindre de fichier : on génère un .eml marqué « X-Unsent: 1 » que
 * Outlook ouvre comme un nouveau message prêt à envoyer (destinataire, objet, texte et pièce jointe remplis).
 */
class ISPAG_Project_Mail_Draft {

    const ACTION = 'ispag_download_project_eml';
    const ATTACHMENT_TYPE = 'customer_order'; // slug du type de document (achats_doc_types) : « Order »

    public static function init() {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'download']);
    }

    /** Commande client la plus récente du projet (document « customer_order »), ou null. */
    public static function customer_order_file($deal_id) {
        global $wpdb;
        $media_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT IdMedia FROM {$wpdb->prefix}achats_historique
             WHERE hubspot_deal_id = %d AND ClassCss = %s AND IdMedia > 0
             ORDER BY dateReadable DESC, Id DESC LIMIT 1",
            (int) $deal_id, self::ATTACHMENT_TYPE
        ));
        if (!$media_id) return null;
        $path = get_attached_file($media_id);
        if (!$path || !is_readable($path)) return null;
        return ['path' => $path, 'name' => basename($path), 'mime' => get_post_mime_type($media_id) ?: 'application/octet-stream'];
    }

    public static function attachments_count($deal_id) {
        return self::customer_order_file($deal_id) ? 1 : 0;
    }

    public static function download_url($deal_id, $type, array $article_ids = []) {
        return add_query_arg([
            'action'      => self::ACTION,
            'deal_id'     => (int) $deal_id,
            'type'        => $type,
            'article_ids' => implode(',', array_map('intval', $article_ids)),
            '_wpnonce'    => wp_create_nonce(self::ACTION . '_' . (int) $deal_id),
        ], admin_url('admin-ajax.php'));
    }

    public static function download() {
        $deal_id = absint($_GET['deal_id'] ?? 0);
        $type    = sanitize_key($_GET['type'] ?? '');
        if (!$deal_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', self::ACTION . '_' . $deal_id)) {
            wp_die('Invalid request', '', 403);
        }
        if (!current_user_can('manage_order')) {
            wp_die('Not authorized', '', 403);
        }
        $article_ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['article_ids'] ?? '')))));

        $mail = ISPAG_Project_status_btn::build_mail($deal_id, $type, $article_ids);
        if (is_wp_error($mail)) {
            wp_die(esc_html($mail->get_error_message()));
        }

        $attachments = [];
        if ($file = self::customer_order_file($deal_id)) {
            $attachments[] = ['content' => file_get_contents($file['path']), 'name' => $file['name'], 'mime' => $file['mime']];
        }
        $eml  = self::build_eml($mail, $attachments);
        $name = sanitize_file_name($mail['subject'] ?: $type) . '.eml';

        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($eml));
        echo $eml;
        exit;
    }

    private static function encode_header($text) {
        $text = str_replace(["\r", "\n"], ' ', (string) $text);
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /** Message MIME : texte brut UTF-8 + pièces jointes ([['content'=>…, 'name'=>…, 'mime'=>…], …]). */
    public static function build_eml(array $mail, array $attachments) {
        $boundary = '=_ispag_' . md5(uniqid('', true));
        $text     = preg_replace("/\r\n|\r|\n/", "\r\n", (string) $mail['message']);

        $h = ['X-Unsent: 1', 'To: ' . trim($mail['email_contact'])];
        $cc = trim((string) ($mail['email_copy'] ?? ''));
        if ($cc !== '') $h[] = 'Cc: ' . $cc;
        $h[] = 'Subject: ' . self::encode_header($mail['subject']);
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text), 76, "\r\n");

        foreach ($attachments as $att) {
            $fname = self::encode_header($att['name']);
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$att['mime']}; name=\"{$fname}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
            $body .= chunk_split(base64_encode((string) $att['content']), 76, "\r\n");
        }
        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }
}
