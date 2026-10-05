<?php
defined('ABSPATH') || exit;

/**
 * Réglages partagés de la connexion à Baïkal (CalDAV pour le calendrier des livraisons, CardDAV pour les contacts du CRM).
 * Page d'administration : ISPAG Settings → Calendar sync (ISPAG_Baikal_Calendar_Sync).
 *
 * Mot de passe : constante / variable d'environnement ISPAG_BAIKAL_PASSWORD (prioritaire) ou option ispag_baikal_password.
 */
class ISPAG_Baikal_Settings {

    const DEFAULT_HOST       = 'contacts.barthels.duckdns.org';
    const DEFAULT_DEPARTMENT = 'vaulruz_ispag';

    public static function host(): string {
        return (string) get_option('ispag_baikal_host', self::DEFAULT_HOST);
    }

    public static function password_is_external(): bool {
        return (defined('ISPAG_BAIKAL_PASSWORD') && ISPAG_BAIKAL_PASSWORD !== '') || (string) getenv('ISPAG_BAIKAL_PASSWORD') !== '';
    }

    public static function password(): string {
        if (defined('ISPAG_BAIKAL_PASSWORD') && ISPAG_BAIKAL_PASSWORD !== '') return (string) ISPAG_BAIKAL_PASSWORD;
        $env = (string) getenv('ISPAG_BAIKAL_PASSWORD');
        return $env !== '' ? $env : (string) get_option('ispag_baikal_password', '');
    }

    /** Utilisateurs Baïkal cibles du carnet d'adresses : « cyril, claudio » → ['cyril', 'claudio']. */
    public static function parse_users($raw): array {
        $users = [];
        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $u) {
            $u = preg_replace('/[^A-Za-z0-9_.@\-]/', '', $u);
            if ($u !== '') $users[$u] = $u;
        }
        return array_values($users);
    }

    /** Réglages de la synchronisation des contacts (utilisés par le plugin CRM). */
    public static function contacts(): array {
        $interval = (string) get_option('ispag_baikal_ab_interval', 'hourly');
        return [
            'enabled'     => (int) get_option('ispag_baikal_ab_enabled', 1),
            'host'        => self::host(),
            'addressbook' => (string) get_option('ispag_baikal_ab_name', 'ispag') ?: 'ispag',
            'users'       => self::parse_users(get_option('ispag_baikal_ab_users', 'cyril, claudio')),
            'department'  => (string) get_option('ispag_baikal_ab_department', self::DEFAULT_DEPARTMENT) ?: self::DEFAULT_DEPARTMENT,
            'interval'    => in_array($interval, ['hourly', 'twicedaily', 'daily'], true) ? $interval : 'hourly',
            'password'    => self::password(),
        ];
    }
}
