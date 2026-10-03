<?php
defined('ABSPATH') || exit;
/**
 * Class ISPAG_Mail_Service
 * Service centralisé pour l'envoi d'e-mails via wp_mail ou Brevo.
 * Logging : Toutes les actions sont loguées dans ispag_mail_service.log.
 */
class ISPAG_Mail_Service
{
    private static $default_sender = ['name' => 'ISPAG', 'email' => 'noreply@ispag-asp.com'];

    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    /**
     * Initialise les hooks ou configurations globales.
     */
    public static function init()
    {
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('mail_service', 'class_initialized', [], $user_id);

        add_action('ispag_send_custom_mails', [self::class, 'send_bulk_mails']);
        // self::$logger->log_user_action('mail_service', 'cron_hook_registered', ['hook' => 'ispag_send_custom_mails'], $user_id);
    }

    /**
     * Envoie un mail via wp_mail ou Brevo.
     *
     * @param string $to_email Email du destinataire.
     * @param string $to_name Nom du destinataire.
     * @param string $subject Sujet du mail.
     * @param string $html_content Contenu HTML du mail.
     * @param array $attachments Pièces jointes (optionnel).
     * @param bool $use_brevo Utiliser Brevo ? (défaut: false).
     * @return bool Succès ou échec.
     */
    public static function send_mail(
        string $to_email,
        string $to_name,
        string $subject,
        string $html_content,
        array $attachments = [],
        bool $use_brevo = false
    ): bool
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mail_service', 'send_mail_start', ['to_email' => $to_email, 'subject' => $subject, 'use_brevo' => $use_brevo], $user_id);

        if ($use_brevo)
        {
            $result = self::send_via_brevo($to_email, $to_name, $subject, $html_content, $attachments);
            self::$logger->log_user_action('mail_service', 'send_via_brevo', ['to_email' => $to_email, 'result' => $result], $user_id);
            return $result;
        }
        else
        {
            $result = self::send_via_wp_mail($to_email, $to_name, $subject, $html_content, $attachments);
            self::$logger->log_user_action('mail_service', 'send_via_wp_mail', ['to_email' => $to_email, 'result' => $result], $user_id);
            return $result;
        }
    }

    /**
     * Envoie un mail via wp_mail (WordPress natif).
     *
     * @param string $to_email Email du destinataire.
     * @param string $to_name Nom du destinataire.
     * @param string $subject Sujet du mail.
     * @param string $html_content Contenu HTML du mail.
     * @param array $attachments Pièces jointes.
     * @return bool Succès ou échec.
     */
    private static function send_via_wp_mail(
        string $to_email,
        string $to_name,
        string $subject,
        string $html_content,
        array $attachments = []
    ): bool
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mail_service', 'send_via_wp_mail_prepare', ['to_email' => $to_email, 'subject' => $subject, 'attachments_count' => count($attachments)], $user_id);

        $headers = [
            "Content-Type: text/html; charset=UTF-8",
            "From: " . self::$default_sender['name'] . " <" . self::$default_sender['email'] . ">",
        ];

        $sent = wp_mail($to_email, $subject, $html_content, $headers, $attachments);

        if (!$sent)
        {
            $error = error_get_last();
            $error_message = $error ? $error['message'] : 'Unknown error';
            self::$logger->log('mail_service', 'ERROR: wp_mail failed - ' . $error_message, $user_id);
        }
        else
        {
            self::$logger->log_user_action('mail_service', 'wp_mail_sent_successfully', ['to_email' => $to_email, 'subject' => $subject], $user_id);
        }

        return $sent;
    }

    /**
     * Envoie un mail via Brevo (API).
     *
     * @param string $to_email Email du destinataire.
     * @param string $to_name Nom du destinataire.
     * @param string $subject Sujet du mail.
     * @param string $html_content Contenu HTML du mail.
     * @param array $attachments Pièces jointes.
     * @return bool Succès ou échec.
     */
    private static function send_via_brevo(
        string $to_email,
        string $to_name,
        string $subject,
        string $html_content,
        array $attachments = []
    ): bool
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mail_service', 'send_via_brevo_start', ['to_email' => $to_email, 'subject' => $subject, 'attachments_count' => count($attachments)], $user_id);

        $api_key = getenv('BREVO_API_KEY');
        if (empty($api_key))
        {
            self::$logger->log('mail_service', 'ERROR: Brevo API key is missing', $user_id);
            return false;
        }

        $data = [
            "sender" => self::$default_sender,
            "to" => [["email" => $to_email, "name" => $to_name]],
            "subject" => $subject,
            "htmlContent" => $html_content,
        ];

        self::$logger->log_user_action('mail_service', 'brevo_payload_prepared', ['to_email' => $to_email], $user_id);

        if (!empty($attachments))
        {
            $data['attachment'] = array_map(function($file) use ($user_id)
            {
                $encoded_url = self::encode_url_path($file['url']);
                self::$logger->log_user_action('mail_service', 'attachment_encoded', ['original_url' => $file['url'], 'encoded_url' => $encoded_url], $user_id);
                return [
                    "url" => $encoded_url,
                    "name" => $file['name'] ?? basename($file['url']),
                ];
            }, $attachments);
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "api-key: $api_key",
            "Content-Type: application/json",
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        self::$logger->log_user_action('mail_service', 'brevo_api_response', ['http_code' => $httpcode, 'response_length' => strlen($response)], $user_id);

        if ($httpcode !== 201 && $httpcode !== 200)
        {
            self::$logger->log('mail_service', 'ERROR: Brevo API request failed - HTTP ' . $httpcode . ': ' . $response . ($curl_error ? ' | cURL Error: ' . $curl_error : ''), $user_id);
            return false;
        }

        self::$logger->log_user_action('mail_service', 'brevo_email_sent_successfully', ['to_email' => $to_email, 'subject' => $subject], $user_id);
        return true;
    }

    /**
     * Encode les URLs pour Brevo (évite les problèmes d'encodage).
     *
     * @param string $url URL à encoder.
     * @return string URL encodée.
     */
    private static function encode_url_path($url)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mail_service', 'encode_url_path_start', ['url' => $url], $user_id);

        $parts = parse_url($url);
        if (!isset($parts['path']))
        {
            self::$logger->log_user_action('mail_service', 'url_no_path', ['url' => $url], $user_id);
            return $url;
        }

        $path = $parts['path'];
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $result = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $encoded_path . (isset($parts['query']) ? '?' . $parts['query'] : '');

        self::$logger->log_user_action('mail_service', 'url_encoded', ['original' => $url, 'encoded' => $result], $user_id);
        return $result;
    }

    /**
     * Envoie des mails en masse (ex: livraisons aux commerciaux).
     *
     * @param array $recipients Liste de destinataires.
     * @param bool $use_brevo Utiliser Brevo ?
     * @return array Résultats (succès/échecs).
     */
    public static function send_bulk_mails(array $recipients, bool $use_brevo = false): array
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mail_service', 'send_bulk_mails_start', ['recipients_count' => count($recipients), 'use_brevo' => $use_brevo], $user_id);

        $results = ['success' => [], 'failed' => []];

        foreach ($recipients as $recipient)
        {
            self::$logger->log_user_action('mail_service', 'processing_recipient', ['email' => $recipient['email'], 'subject' => $recipient['subject']], $user_id);

            $sent = self::send_mail(
                $recipient['email'],
                $recipient['name'] ?? '',
                $recipient['subject'],
                $recipient['content'],
                $recipient['attachments'] ?? [],
                $use_brevo
            );

            if ($sent)
            {
                $results['success'][] = $recipient['email'];
                self::$logger->log_user_action('mail_service', 'email_sent_to_recipient', ['email' => $recipient['email']], $user_id);
            }
            else
            {
                $results['failed'][] = $recipient['email'];
                self::$logger->log('mail_service', 'ERROR: Failed to send email to ' . $recipient['email'], $user_id);
            }
        }

        self::$logger->log_user_action('mail_service', 'send_bulk_mails_complete', ['success_count' => count($results['success']), 'failed_count' => count($results['failed'])], $user_id);
        return $results;
    }
}