<?php

class ISPAG_Telegram_Notifier {
    private $bot_token;
    private $admin_chat_id = getenv('ISPAG_TELEGRAM_CHAT_ID');
    private $wpdb;
    private $table_subs;
    protected static $instance = null;
 
    public function __construct() {
        global $wpdb;
        // $this->bot_token = $bot_token;
        $this->bot_token = getenv('ISPAG_TELEGRAM_TOKEN');
        
        $this->wpdb = $wpdb;
        $this->table_subs = $wpdb->prefix . 'achats_telegram_subscribers';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('ispag_send_telegram_notification', [self::$instance, 'send_telegram_message'], 10, 7);
    }
    public function get_bot_token() {
        return $this->bot_token; 
    }

    public function send_telegram_message($html, $slug, $to_admin = true, $to_subscribers = true, $deal_id = null, $message_is_slug = false, $message_is_markdown = false){ 
        $message = $message_is_slug ? $this->get_message($slug, $deal_id) : $slug;
        $this->send_message(null, $message, $to_admin, $to_subscribers, $deal_id, $message_is_slug, $message_is_markdown);
    }
    
    public function send_message($html, $message, $to_admin = true, $to_subscribers = true, $deal_id = null, $message_is_slug = false, $message_is_markdown = false) {
        // error_log("✅ in send_message : $message");
        if($message_is_markdown){
            $message = $message_is_slug ? $message : trim($message);
        }
        else{
            $message = $message_is_slug ? $message : $this->escape_markdown_v2(trim($message));
        }
        

        if ($message_is_slug) {
            $message = preg_replace('/<br\s*\/?>/i', "\n", $message);
        }
        
        if ($to_admin) {
            $this->send_to_chat($this->admin_chat_id, $message, $message_is_slug);
        }

            if ($to_subscribers) {
                $subscribers = $this->get_subscribers($deal_id);
                
                foreach ($subscribers as $sub) {
                    if ($sub->chat_id != $this->admin_chat_id) {
                        $this->send_to_chat($sub->chat_id, $message, $message_is_slug);
                    }
                }
            }
        
    }

    private function send_to_chat($chat_id, $message, $message_is_slug = false) {
        // error_log('[TELEGRAM] Envoi message à ' . $chat_id . ': ' . $message);
        $parse_mode = $message_is_slug ? 'HTML' : 'MarkdownV2';
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
        $params = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode,
            
        ];

        // wp_remote_post($url, [
        //     'body' => $params,
        //     'timeout' => 10,
        // ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
// error_log('[TELEGRAM] juste avant envoie : ' . print_r($params, true));
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            // error_log("❌ Erreur cURL : $error");
            return false;
        }
        else{
            // error_log('[TELEGRAM] Réponse : ' . $response);
        }

        curl_close($ch);

    
    }

    public function get_subscribers($deal_id = null) {
        file_put_contents(__DIR__.'/../telehook.log', date('c').' get_subscribers: '.$deal_id.PHP_EOL, FILE_APPEND);
        if ($deal_id !== null) {
            $chat_id = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT chat_id FROM {$this->table_subs} WHERE deal_id=%d",
                    $deal_id
                )
            );
            file_put_contents(__DIR__.'/telehook.log', date('c').' '.print_r($chat_id,true).PHP_EOL, FILE_APPEND);
            return $chat_id;
        }
        file_put_contents(__DIR__.'/../telehook.log', date('c').' no subscriber found on project : '.$deal_id.PHP_EOL, FILE_APPEND);
        return;
    }
    


    public function add_subscriber($chat_id, $display_name = '', $is_admin = 0) {
        $this->wpdb->replace($this->table_subs, [
            'chat_id' => $chat_id,
            'display_name' => $display_name,
            'is_admin' => $is_admin,
        ]);
    }

    public function remove_subscriber($chat_id) {
        $this->wpdb->delete($this->table_subs, ['chat_id' => $chat_id]);
    }

    public function get_message($slug, $deal_id) {
        global $wpdb;

        // 1. Sécurisation de l'ID
        $deal_id = (int)$deal_id;
        if ($deal_id <= 0) return null;

        $table_tpl = $wpdb->prefix . 'achats_template_mail';
        $table_projets = $wpdb->prefix . 'achats_liste_commande';

        // 2. Récupération du template Telegram
        $template = $wpdb->get_var($wpdb->prepare(
            "SELECT telegram FROM $table_tpl WHERE message_family = %s AND message_type = %s AND lang = %s ORDER BY Id DESC LIMIT 1",
            'project', $slug, 'fr_FR'
        ));

        if (empty($template)) return null;

        // 3. Récupération DIRECTE du nom du projet (SQL léger)
        $project_name = $wpdb->get_var($wpdb->prepare(
            "SELECT ObjetCommande FROM $table_projets WHERE hubspot_deal_id = %d LIMIT 1",
            $deal_id
        ));

        // 4. Préparation des variables
        $current_user = wp_get_current_user();
        $user_name = ($current_user && $current_user->display_name) ? $current_user->display_name : 'L\'équipe ISPAG';
        $project_link = 'https://app.ispag-asp.ch/details-du-projet/?deal_id=' . $deal_id;

        // 5. Remplacements manuels des tags
        $result = $template;
        
        // On nettoie les éventuels entités HTML (ex: &agrave; -> à) pour Telegram
        $clean_name = html_entity_decode($project_name ?: "Projet #$deal_id", ENT_QUOTES, 'UTF-8');

        $result = str_replace("{PROJECT_NAME}", $clean_name, $result);
        $result = str_replace("{PROJECT_LINK}", '<a href="'.$project_link.'">Voir le projet</a>', $result);
        $result = str_replace("{USER_NAME}", $user_name, $result);  
        $result = str_replace("{BR}", "\n", $result);
        
        // Sécurité supplémentaire pour d'autres variantes de tags
        $result = str_replace("{PROJECT_URL}", $project_link, $result);

        return $result;
    }

    public static function escape_markdown_v2($text) {
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

        return $escaped;
    }


    public function subscribe_to_project($deal_id, $chat_id, $user_id, $is_admin = 0) {
        if (!$deal_id || !$chat_id) return ['success'=>false,'message'=>'Paramètres manquants'];

        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_subs} WHERE deal_id=%d AND chat_id=%d",
                $deal_id, $chat_id
            )
        );
        if ($exists > 0) return ['success'=>false,'message'=>'Déjà abonné à ce projet'];

        $ok = $this->wpdb->insert($this->table_subs, [
            'deal_id'=>$deal_id,'chat_id'=>$chat_id,'user_id'=>$user_id,'is_admin'=>(int)$is_admin
        ], ['%d','%d','%d','%d']);

        if ($ok === false) {
            $error_msg = $this->wpdb->last_error ?: 'Erreur inconnue';
// \1('❌ [TELEGRAM][DB] ' . $error_msg);
            return [
                'success' => false,
                'message' => 'Erreur DB : ' . $error_msg
            ];
        }

        return ['success'=>true,'message'=>'Abonnement ok'];
    }


}
