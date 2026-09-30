<?php
/**
 * Class ISPAG_Telegram_Notifier
 * Gère les notifications Telegram pour les commandes et projets ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_telegram_notifier.log.
 */
class ISPAG_Telegram_Notifier
{
    private $bot_token;
    private $admin_chat_id;
    private $wpdb;
    private $table_subs;
    protected static $instance = null;
    private const LOG_NAME = 'telegram_notifier';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->bot_token = getenv('ISPAG_TELEGRAM_TOKEN');
        $this->admin_chat_id = getenv('ISPAG_TELEGRAM_CHAT_ID');
        $this->wpdb = $wpdb;
        $this->table_subs = $wpdb->prefix . 'achats_telegram_subscribers';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', ['bot_token_set' => !empty($this->bot_token), 'admin_chat_id_set' => !empty($this->admin_chat_id)], $user_id);
    }

    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self();
            // $logger->log_user_action(self::LOG_NAME, 'instance_initialized', [], $user_id);
        }

        add_action('ispag_send_telegram_notification', [self::$instance, 'send_telegram_message'], 10, 7);
        // $logger->log_user_action(self::LOG_NAME, 'hook_registered', ['hook' => 'ispag_send_telegram_notification'], $user_id);
    }

    public function get_bot_token()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_bot_token_called', [], $user_id);
        return $this->bot_token;
    }

    public function send_telegram_message($html, $slug, $to_admin = true, $to_subscribers = true, $deal_id = null, $message_is_slug = false, $message_is_markdown = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'send_telegram_message_start', ['slug' => $slug, 'to_admin' => $to_admin, 'to_subscribers' => $to_subscribers, 'deal_id' => $deal_id], $user_id);

        $message = $message_is_slug ? $this->get_message($slug, $deal_id) : $slug;
        $this->logger->log_user_action(self::LOG_NAME, 'message_prepared', ['message_length' => strlen($message)], $user_id);

        $this->send_message(null, $message, $to_admin, $to_subscribers, $deal_id, $message_is_slug, $message_is_markdown);
    }

    public function send_message($html, $message, $to_admin = true, $to_subscribers = true, $deal_id = null, $message_is_slug = false, $message_is_markdown = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'send_message_start', ['to_admin' => $to_admin, 'to_subscribers' => $to_subscribers, 'deal_id' => $deal_id], $user_id);

        if ($message_is_markdown)
        {
            $message = $message_is_slug ? $message : trim($message);
            $this->logger->log_user_action(self::LOG_NAME, 'message_markdown_mode', [], $user_id);
        }
        else
        {
            $message = $message_is_slug ? $message : $this->escape_markdown_v2(trim($message));
            $this->logger->log_user_action(self::LOG_NAME, 'message_escaped', [], $user_id);
        }

        if ($message_is_slug)
        {
            $message = preg_replace('/<br\s*\/?>/i', "\n", $message);
            $this->logger->log_user_action(self::LOG_NAME, 'message_br_replaced', [], $user_id);
        }

        if ($to_admin)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'sending_to_admin', ['admin_chat_id' => $this->admin_chat_id], $user_id);
            $this->send_to_chat($this->admin_chat_id, $message, $message_is_slug);
        }

        if ($to_subscribers)
        {
            $subscribers = $this->get_subscribers($deal_id);
            $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'FETCH_SUBSCRIBERS', ['deal_id' => $deal_id, 'count' => count($subscribers)], $user_id);

            foreach ($subscribers as $sub)
            {
                if ($sub->chat_id != $this->admin_chat_id)
                {
                    $this->logger->log_user_action(self::LOG_NAME, 'sending_to_subscriber', ['chat_id' => $sub->chat_id], $user_id);
                    $this->send_to_chat($sub->chat_id, $message, $message_is_slug);
                }
            }
        }
    }

    public function send_to_chat($chat_id, $message, $message_is_slug = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'send_to_chat_start', ['chat_id' => $chat_id, 'message_length' => strlen($message)], $user_id);

        $parse_mode = $message_is_slug ? 'HTML' : 'MarkdownV2';
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
        $params = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode,
        ];

        $this->logger->log_user_action(self::LOG_NAME, 'curl_params_prepared', ['parse_mode' => $parse_mode], $user_id);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        $response = curl_exec($ch);

        if ($response === false)
        {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->log(self::LOG_NAME, 'ERROR: cURL error - ' . $error, $user_id);
            return false;
        }
        else
        {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->logger->log_user_action(self::LOG_NAME, 'curl_response_received', ['http_code' => $http_code, 'response' => $response], $user_id);
        }

        curl_close($ch);
        return true;
    }

    public function get_subscribers($deal_id = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_subscribers_start', ['deal_id' => $deal_id], $user_id);

        if ($deal_id !== null)
        {
            $chat_ids = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT chat_id FROM {$this->table_subs} WHERE deal_id=%d",
                    $deal_id
                )
            );
            $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'FETCH_SUBSCRIBERS_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($chat_ids)], $user_id);
            return $chat_ids;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'no_deal_id_provided', [], $user_id);
        return [];
    }

    public function add_subscriber($chat_id, $display_name = '', $is_admin = 0)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'add_subscriber_start', ['chat_id' => $chat_id, 'display_name' => $display_name, 'is_admin' => $is_admin], $user_id);

        $result = $this->wpdb->replace($this->table_subs, [
            'chat_id' => $chat_id,
            'display_name' => $display_name,
            'is_admin' => $is_admin,
        ]);

        $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'REPLACE_SUBSCRIBER', ['chat_id' => $chat_id, 'result' => $result], $user_id);
    }

    public function remove_subscriber($chat_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'remove_subscriber_start', ['chat_id' => $chat_id], $user_id);

        $result = $this->wpdb->delete($this->table_subs, ['chat_id' => $chat_id]);
        $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'DELETE_SUBSCRIBER', ['chat_id' => $chat_id, 'result' => $result], $user_id);
    }

    public function get_message($slug, $deal_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_message_start', ['slug' => $slug, 'deal_id' => $deal_id], $user_id);

        global $wpdb;

        $deal_id = (int)$deal_id;
        if ($deal_id <= 0)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid deal_id - ' . $deal_id, $user_id);
            return null;
        }

        $table_tpl = $wpdb->prefix . 'achats_template_mail';
        $table_projets = $wpdb->prefix . 'achats_liste_commande';

        $template = $wpdb->get_var($wpdb->prepare(
            "SELECT telegram FROM $table_tpl WHERE message_family = %s AND message_type = %s AND lang = %s ORDER BY Id DESC LIMIT 1",
            'project', $slug, 'fr_FR'
        ));

        $this->logger->log_db_change(self::LOG_NAME, $table_tpl, 'FETCH_TEMPLATE', ['slug' => $slug, 'template' => $template], $user_id);

        if (empty($template))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Template not found for slug - ' . $slug, $user_id);
            return null;
        }

        $project_name = $wpdb->get_var($wpdb->prepare(
            "SELECT ObjetCommande FROM $table_projets WHERE hubspot_deal_id = %d LIMIT 1",
            $deal_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $table_projets, 'FETCH_PROJECT_NAME', ['deal_id' => $deal_id, 'project_name' => $project_name], $user_id);

        $current_user = wp_get_current_user();
        $user_name = ($current_user && $current_user->display_name) ? $current_user->display_name : 'The ISPAG team';
        $project_link = trailingslashit(get_site_url()) . 'project-detail/' . $deal_id;

        $this->logger->log_user_action(self::LOG_NAME, 'message_variables_prepared', ['user_name' => $user_name, 'project_link' => $project_link], $user_id);

        $clean_name = html_entity_decode($project_name ?: "Projet #$deal_id", ENT_QUOTES, 'UTF-8');
        $result = $template;
        $result = str_replace("{PROJECT_NAME}", $clean_name, $result);
        $result = str_replace("{PROJECT_LINK}", '<a href="' . $project_link . '">View project</a>', $result);
        $result = str_replace("{USER_NAME}", $user_name, $result);
        $result = str_replace("{BR}", "\n", $result);
        $result = str_replace("{PROJECT_URL}", $project_link, $result);

        $this->logger->log_user_action(self::LOG_NAME, 'message_placeholders_replaced', [], $user_id);
        return $result;
    }

    public static function escape_markdown_v2($text)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'escape_markdown_v2_start', ['text_length' => strlen($text)], $user_id);

        $chars_to_escape = [
            '_', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'
        ];

        $escaped = str_replace(
            $chars_to_escape,
            array_map(function($char) {
                return '\\' . $char;
            }, $chars_to_escape),
            $text
        );

        $logger->log_user_action(self::LOG_NAME, 'escape_markdown_v2_complete', ['escaped_length' => strlen($escaped)], $user_id);
        return $escaped;
    }

    public function subscribe_to_project($deal_id, $chat_id, $user_id, $is_admin = 0)
    {
        $user_id_log = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'subscribe_to_project_start', ['deal_id' => $deal_id, 'chat_id' => $chat_id, 'user_id' => $user_id, 'is_admin' => $is_admin], $user_id_log);

        if (!$deal_id || !$chat_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing parameters (deal_id or chat_id)', $user_id_log);
            return ['success' => false, 'message' => 'Missing parameters'];
        }

        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_subs} WHERE deal_id=%d AND chat_id=%d",
                $deal_id, $chat_id
            )
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'CHECK_SUBSCRIPTION_EXISTS', ['deal_id' => $deal_id, 'chat_id' => $chat_id, 'exists' => $exists], $user_id_log);

        if ($exists > 0)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Already subscribed to this project', $user_id_log);
            return ['success' => false, 'message' => 'Already subscribed to this project'];
        }

        $ok = $this->wpdb->insert($this->table_subs, [
            'deal_id' => $deal_id,
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'is_admin' => (int)$is_admin
        ], ['%d', '%d', '%d', '%d']);

        $this->logger->log_db_change(self::LOG_NAME, $this->table_subs, 'INSERT_SUBSCRIPTION', ['deal_id' => $deal_id, 'chat_id' => $chat_id, 'result' => $ok], $user_id_log);

        if ($ok === false)
        {
            $error_msg = $this->wpdb->last_error ?: 'Unknown error';
            $this->logger->log(self::LOG_NAME, 'ERROR: DB error - ' . $error_msg, $user_id_log);
            return [
                'success' => false,
                'message' => 'DB error: ' . $error_msg
            ];
        }

        $this->logger->log_user_action(self::LOG_NAME, 'subscribe_to_project_success', ['deal_id' => $deal_id, 'chat_id' => $chat_id], $user_id_log);
        return ['success' => true, 'message' => 'Abonnement ok'];
    }
}