<?php
defined('ABSPATH') || exit;

/**
 * Réglages des e-mails de facturation des projets (ISPAG Settings → Invoicing).
 *
 *  - le contact (utilisateur WordPress) qui rédige les factures : destinataire des e-mails
 *    « facture de situation » (partielle) et « facture finale » envoyés depuis la fiche projet ;
 *  - le texte de ces deux e-mails (français uniquement). Valeurs par défaut fournies, modifiables ici.
 *
 * Balises disponibles : voir tags().
 */
class ISPAG_Invoice_Settings {

    const OPT_CONTACT = 'ispag_invoice_contact_id';
    const OPT_PREFIX  = 'ispag_invoice_mail_'; // + <type>_subject / <type>_message
    const LEGACY_CONTACT_ID = 6052; // ancien contact codé en dur, utilisé tant que rien n'est configuré
    const NONCE = 'ispag_invoice_settings';

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    /** Types d'e-mail : clé (message_type du bouton) => libellé. */
    public static function types(): array {
        return [
            'situation'   => __('Partial invoice (situation)', 'creation-reservoir'),
            'facturation' => __('Final invoice', 'creation-reservoir'),
        ];
    }

    public static function tags(): array {
        return [
            '{PRENOM}'         => __('Invoice contact first name', 'creation-reservoir'),
            '{NOM}'            => __('Invoice contact last name', 'creation-reservoir'),
            '{PROJECT_NAME}'   => __('Project name', 'creation-reservoir'),
            '{PROJECT_NUMBER}' => __('Order number', 'creation-reservoir'),
            '{PROJECT_URL}'    => __('Link to the project', 'creation-reservoir'),
            '{PRODUCT_LIST}'   => __('List of items, grouped', 'creation-reservoir'),
            '{DELIVERY_ADRESS}' => __('Delivery address', 'creation-reservoir'),
            '{DELIVERY_NIP}'   => __('Delivery postal code', 'creation-reservoir'),
            '{DELIVERY_CITY}'  => __('Delivery city', 'creation-reservoir'),
            '{INVOICE_DATE}'   => __('Month and year (e.g. octobre 2026)', 'creation-reservoir'),
            '{MOIS_EN_COURS}'  => __('Current month and year, to ask for the invoice month (e.g. octobre 2026)', 'creation-reservoir'),
        ];
    }

    /** Textes par défaut (français). */
    public static function defaults(): array {
        return [
            'situation' => [
                'subject' => 'Facture de situation - {PROJECT_NAME} ({PROJECT_NUMBER})',
                'message' => "Bonjour {PRENOM},\n\nMerci d'établir une situation pour le projet suivant :\n\n"
                    . "Projet : {PROJECT_NAME}\nN° de commande : {PROJECT_NUMBER}\nPériode : {INVOICE_DATE}\n\n"
                    . "Articles :\n{PRODUCT_LIST}\n\nSi possible facture sur {MOIS_EN_COURS}\n\nMerci et bonne journée.",
            ],
            'facturation' => [
                'subject' => 'Facture finale - {PROJECT_NAME} ({PROJECT_NUMBER})',
                'message' => "Bonjour {PRENOM},\n\nLe projet suivant est terminé, merci d'établir la facture finale :\n\n"
                    . "Projet : {PROJECT_NAME}\nN° de commande : {PROJECT_NUMBER}\n\n"
                    . "Si possible facture sur {MOIS_EN_COURS}\n\nMerci et bonne journée.",
            ],
        ];
    }

    /** Contact qui rédige les factures (WP_User) ou null. */
    public static function contact() {
        $id = (int) get_option(self::OPT_CONTACT, 0);
        if (!$id) $id = self::LEGACY_CONTACT_ID;
        $user = get_user_by('ID', $id);
        return $user ?: null;
    }

    /** @return array{subject:string,message:string}|null  texte enregistré, sinon texte par défaut */
    public static function template(string $type) {
        $defaults = self::defaults();
        if (!isset($defaults[$type])) return null;
        $subject = trim((string) get_option(self::OPT_PREFIX . $type . '_subject', ''));
        $message = trim((string) get_option(self::OPT_PREFIX . $type . '_message', ''));
        return [
            'subject' => $subject !== '' ? $subject : $defaults[$type]['subject'],
            'message' => $message !== '' ? $message : $defaults[$type]['message'],
        ];
    }

    public static function admin_menu() {
        $title = __('Invoicing', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', 'ispag-invoice-settings', [self::class, 'render']);
        } else {
            add_management_page($title, $title, 'manage_options', 'ispag-invoice-settings', [self::class, 'render']);
        }
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }

        $notice = '';
        if (!empty($_POST['ispag_invoice_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ispag_invoice_nonce'])), self::NONCE)) {
            update_option(self::OPT_CONTACT, absint($_POST['invoice_contact'] ?? 0), false);
            foreach (array_keys(self::types()) as $type) {
                $reset = !empty($_POST['reset_' . $type]);
                update_option(self::OPT_PREFIX . $type . '_subject', $reset ? '' : sanitize_text_field(wp_unslash($_POST[$type . '_subject'] ?? '')), false);
                update_option(self::OPT_PREFIX . $type . '_message', $reset ? '' : sanitize_textarea_field(wp_unslash($_POST[$type . '_message'] ?? '')), false);
            }
            $notice = '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'creation-reservoir') . '</p></div>';
        }

        $contact = self::contact();
        echo '<div class="wrap"><h1>' . esc_html__('Invoicing', 'creation-reservoir') . '</h1>' . $notice;
        echo '<p>' . esc_html__('E-mails prepared by the "Send partial invoice request" and "Send final invoice" buttons of a project. Texts are in French only.', 'creation-reservoir') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'ispag_invoice_nonce');

        echo '<table class="form-table"><tr><th><label for="invoice_contact">' . esc_html__('Invoice contact', 'creation-reservoir') . '</label></th><td>';
        wp_dropdown_users([
            'name'             => 'invoice_contact',
            'id'               => 'invoice_contact',
            'selected'         => $contact ? $contact->ID : 0,
            'show_option_none' => __('— Select —', 'creation-reservoir'),
            'show'             => 'display_name_with_login',
        ]);
        echo '<p class="description">' . esc_html__('Writes the invoices and receives these e-mails.', 'creation-reservoir') . '</p></td></tr></table>';

        $defaults = self::defaults();
        foreach (self::types() as $type => $label) {
            $tpl = self::template($type);
            echo '<h2>' . esc_html($label) . '</h2><table class="form-table">';
            echo '<tr><th><label>' . esc_html__('Subject', 'creation-reservoir') . '</label></th><td><input type="text" class="large-text" name="' . esc_attr($type) . '_subject" value="' . esc_attr($tpl['subject']) . '"></td></tr>';
            echo '<tr><th><label>' . esc_html__('Message', 'creation-reservoir') . '</label></th><td><textarea class="large-text code" rows="12" name="' . esc_attr($type) . '_message">' . esc_textarea($tpl['message']) . '</textarea>';
            echo '<p><label><input type="checkbox" name="reset_' . esc_attr($type) . '" value="1"> ' . esc_html__('Restore the default text', 'creation-reservoir') . '</label></p></td></tr></table>';
        }

        echo '<h2>' . esc_html__('Available tags', 'creation-reservoir') . '</h2><ul>';
        foreach (self::tags() as $tag => $desc) {
            echo '<li><code>' . esc_html($tag) . '</code> — ' . esc_html($desc) . '</li>';
        }
        echo '</ul><p><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button></p></form></div>';
    }
}
