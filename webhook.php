<?php
file_put_contents(__DIR__.'/telehook.log', date('c').' Start webhook' . PHP_EOL, FILE_APPEND);
file_put_contents(__DIR__.'/telehook.log', __DIR__ . PHP_EOL, FILE_APPEND);

$path = dirname(__DIR__, 3) . '/wp-load.php';
file_put_contents(__DIR__.'/telehook.log', date('c').' Path checked: ' . var_export($path, true) . PHP_EOL, FILE_APPEND);

if (!$path || !file_exists($path)) {
    file_put_contents(__DIR__.'/telehook.log', date('c').' ERROR: wp-load.php not found!' . PHP_EOL, FILE_APPEND);
    exit;
}

if (@include_once $path) {
    file_put_contents(__DIR__.'/telehook.log', date('c').' WP loaded' . PHP_EOL, FILE_APPEND);
} else {
    file_put_contents(__DIR__.'/telehook.log', date('c').' ERROR: wp-load.php could not be included' . PHP_EOL, FILE_APPEND);
}

// Vérification de la classe
file_put_contents(__DIR__.'/telehook.log', date('c')." TEST: About to init notifier".PHP_EOL, FILE_APPEND);

if (!class_exists('ISPAG_Telegram_Notifier')) {
    file_put_contents(__DIR__.'/telehook.log', date('c')." ERROR: ISPAG_Telegram_Notifier not found".PHP_EOL, FILE_APPEND);
    exit;
} else {
    file_put_contents(__DIR__.'/telehook.log', date('c')." FOUND: ISPAG_Telegram_Notifier".PHP_EOL, FILE_APPEND);
}

// Lecture du contenu reçu
$input = file_get_contents("php://input");
file_put_contents(__DIR__.'/telehook.log', date('c').' RAW INPUT: '.print_r($input,true).PHP_EOL, FILE_APPEND);

$data  = json_decode($input, true);
file_put_contents(__DIR__.'/telehook.log', date('c').' DECODED DATA: '.print_r($data,true).PHP_EOL, FILE_APPEND);

// Vérifier si data valide
if (!$data || !isset($data['message'])) {
    file_put_contents(__DIR__.'/telehook.log', date('c').' DATA INVALID OR NO MESSAGE, exiting'.PHP_EOL, FILE_APPEND);
    exit;
}

// Extraire chat_id, user_id, message
$chat_id = $data['message']['chat']['id'];
$user_id = $data['message']['from']['id'];
$message = trim($data['message']['text'] ?? '');

// Loguer les infos
file_put_contents(__DIR__.'/telehook.log', date('c').' CHAT ID: '.$chat_id.PHP_EOL, FILE_APPEND);
file_put_contents(__DIR__.'/telehook.log', date('c').' USER ID: '.$user_id.PHP_EOL, FILE_APPEND);
file_put_contents(__DIR__.'/telehook.log', date('c').' MESSAGE: '.$message.PHP_EOL, FILE_APPEND);


// Si ici, $data est correct
file_put_contents(__DIR__.'/telehook.log', date('c').' DATA OK, proceeding'.PHP_EOL, FILE_APPEND);

// Maintenant tu peux instancier ton notifier
$notifier = new ISPAG_Telegram_Notifier();




// $notifier = new ISPAG_Telegram_Notifier();

// Fonction pour envoyer un message
function sendTelegramMessageBot($chat_id, $text, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chat_id,
        'text'    => $text
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        file_put_contents(__DIR__.'/telehook.log', date('c').' cURL ERROR: '.curl_error($ch).PHP_EOL, FILE_APPEND);
    } else {
        file_put_contents(__DIR__.'/telehook.log', date('c').' RESPONSE: '.$resp.PHP_EOL, FILE_APPEND);
    }
    curl_close($ch);
}


// Logger pour debug
file_put_contents(__DIR__.'/telehook.log', date('c').' '.print_r($data,true).PHP_EOL, FILE_APPEND);

// Commandes

// Vérifier si c'est un /start avec paramètre
if (preg_match('/^\/start\s+subscribe_(\d+)$/', $message, $m)) {
    $message = '/subscribe ' . $m[1];
    file_put_contents(__DIR__.'/telehook.log', date('c').' Converted start param to: '.$message.PHP_EOL, FILE_APPEND);
}

if (stripos($message, '/subscribe') === 0) {
    $parts = explode(' ', $message);
    if (!isset($parts[1]) || !is_numeric($parts[1])) {
        sendTelegramMessageBot($chat_id, "Usage : /subscribe <deal_id>", $notifier->get_bot_token());
        exit;
    }
    $deal_id = intval($parts[1]);
    $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
    $res = $notifier->subscribe_to_project($deal_id, $chat_id, $user_id);
    file_put_contents(__DIR__.'/telehook.log', date('c').' SUBSCRIBE RESULT: '.print_r($res,true).PHP_EOL, FILE_APPEND);

    sendTelegramMessageBot($chat_id, $res['success'] ? "✅ Abonné au projet $project->ObjetCommande" : "<span class=\"dashicons dashicons-warning\"></span> ".$res['message'], $notifier->get_bot_token());

} elseif (stripos($message, '/unsubscribe') === 0) {
    $parts = explode(' ', $message);
    if (!isset($parts[1]) || !is_numeric($parts[1])) {
        sendTelegramMessageBot($chat_id, "Usage : /unsubscribe <deal_id>", $notifier->get_bot_token());
        exit;
    }
    $deal_id = intval($parts[1]);
    $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
    global $wpdb;
    $table = $wpdb->prefix . 'achats_telegram_subscribers';
    $deleted = $wpdb->delete($table, ['deal_id'=>$deal_id,'chat_id'=>$chat_id]);
    sendTelegramMessageBot($chat_id, $deleted ? "❌ Désabonné du projet $project->ObjetCommande" : "<span class=\"dashicons dashicons-warning\"></span> Vous n'étiez pas abonné à ce projet", $notifier->get_bot_token());

} elseif (stripos($message, '/myprojects') === 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'achats_telegram_subscribers';
    $projects = $wpdb->get_col($wpdb->prepare("SELECT deal_id FROM {$table} WHERE chat_id=%d",$chat_id));
    if ($projects) {
        sendTelegramMessageBot($chat_id, "📋 Vos projets abonnés : ".implode(', ',$projects), $notifier->get_bot_token());
    } else {
        sendTelegramMessageBot($chat_id, "Vous n'êtes abonné à aucun projet.", $notifier->get_bot_token());
    }

} else {
    sendTelegramMessageBot($chat_id, "Commandes disponibles :\n/subscribe <deal_id>\n/unsubscribe <deal_id>\n/myprojects", $notifier->get_bot_token());
}
