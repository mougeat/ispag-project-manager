<?php
defined('ABSPATH') || exit;
/**
 * Classe ISPAG_Logger
 *
 * Gère le logging centralisé pour toutes les actions utilisateurs et modifications de DB.
 * Format des logs :
 * - [DATE] [USER:$user_id] [TYPE:message] (pour les actions utilisateurs)
 * - [DATE] [USER:$user_id] [ERROR] message (pour les erreurs)
 * - [DATE] [USER:$user_id] [DB_CHANGE] message (pour les modifications de DB)
 *
 * Fichiers de log : ispag_NOM_DES_LOGS.log (stockés dans wp-content/ispag_logs/)
 */
class ISPAG_Logger
{
    /** @var string Dossier de stockage des logs. */
    private static $log_dir = 'ispag_logs';

    /** @var ISPAG_Logger|null Instance unique (Singleton). */
    private static $instance = null;

    /**
     * Constructeur privé pour le Singleton.
     */
    private function __construct()
    {
        $this->ensure_log_dir_exists();
    }

    /**
     * Récupère l'instance unique de ISPAG_Logger.
     * @return ISPAG_Logger
     */
    public static function get_instance(): ISPAG_Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Vérifie que le dossier de logs existe, sinon le crée.
     */
    private function ensure_log_dir_exists(): void
    {
        $upload_dir = wp_upload_dir();
        $log_path = trailingslashit($upload_dir['basedir']) . self::$log_dir;

        if (!file_exists($log_path)) {
            wp_mkdir_p($log_path);
            // Bloque l'accès direct au dossier
            file_put_contents($log_path . '/index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Log un message générique.
     *
     * @param string $log_name Nom du log (ex: "document_manager").
     * @param string $message Message à logger.
     * @param int|null $user_id ID de l'utilisateur (null si action automatique).
     */
    public function log(string $log_name, string $message, ?int $user_id = null): void
    {
        $upload_dir = wp_upload_dir();
        $log_path = trailingslashit($upload_dir['basedir']) . self::$log_dir;
        $log_file = $log_path . '/ispag_' . sanitize_file_name($log_name) . '.log';

        $timestamp = current_time('Y-m-d H:i:s');
        $user_part = $user_id !== null ? "USER:$user_id" : "AUTO";
        $log_line = "[$timestamp] [$user_part] $message" . PHP_EOL;

        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log une action utilisateur.
     *
     * @param string $log_name Nom du log.
     * @param string $action Action effectuée (ex: "save_fittings").
     * @param array $data Données associées (optionnel).
     * @param int|null $user_id ID de l'utilisateur.
     */
    public function log_user_action(string $log_name, string $action, array $data = [], ?int $user_id = null): void
    {
        $message = "USER_ACTION: $action";
        if (!empty($data)) {
            $message .= ' | DATA: ' . $this->format_data($data);
        }
        $this->log($log_name, $message, $user_id);
    }

    /**
     * Log une erreur.
     *
     * @param string $log_name Nom du log.
     * @param string $message Message d'erreur.
     * @param array $context Contexte supplémentaire (optionnel).
     * @param int|null $user_id ID de l'utilisateur.
     */
    public function log_error(string $log_name, string $message, array $context = [], ?int $user_id = null): void
    {
        $formatted_message = "ERROR: $message";
        if (!empty($context)) {
            $formatted_message .= ' | CONTEXT: ' . $this->format_data($context);
        }
        $this->log($log_name, $formatted_message, $user_id);
    }

    /**
     * Log une modification de base de données.
     *
     * @param string $log_name Nom du log.
     * @param string $table Table concernée.
     * @param string $action Action (ex: "INSERT", "UPDATE", "DELETE").
     * @param array $data Données modifiées.
     * @param int|null $user_id ID de l'utilisateur.
     */
    public function log_db_change(string $log_name, string $table, string $action, array $data = [], ?int $user_id = null): void
    {
        $message = "DB_CHANGE: $action ON $table";
        if (!empty($data)) {
            $message .= ' | DATA: ' . $this->format_data($data);
        }
        $this->log($log_name, $message, $user_id);
    }

    /**
     * Formate les données pour les logs (JSON avec gestion des erreurs).
     *
     * @param mixed $data Données à formater.
     * @return string Données formatées en JSON.
     */
    private function format_data($data): string
    {
        if (is_array($data) || is_object($data)) {
            try {
                return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (Exception $e) {
                return '[DATA_FORMAT_ERROR] ' . $e->getMessage();
            }
        }
        return (string) $data;
    }

    /**
     * Nettoie les logs plus vieux que $days jours.
     * @param int $days Nombre de jours à conserver (30 par défaut).
     */
    public function cleanup_old_logs(int $days = 30): void
    {
        $upload_dir = wp_upload_dir();
        $log_path = trailingslashit($upload_dir['basedir']) . self::$log_dir;
        $files = glob($log_path . '/ispag_*.log');

        $cutoff = time() - ($days * DAY_IN_SECONDS);
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    /**
     * Log une erreur critique (avec trace stack si disponible).
     *
     * @param string $log_name Nom du log.
     * @param string|Exception $error Message d'erreur ou exception.
     * @param array $context Contexte supplémentaire.
     * @param int|null $user_id ID de l'utilisateur.
     */
    public function log_critical(string $log_name, $error, array $context = [], ?int $user_id = null): void
    {
        if ($error instanceof Exception) {
            $message = "CRITICAL: " . $error->getMessage();
            $context = array_merge($context, [
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ]);
        } else {
            $message = "CRITICAL: $error";
        }

        $this->log_error($log_name, $message, $context, $user_id);
    }
}