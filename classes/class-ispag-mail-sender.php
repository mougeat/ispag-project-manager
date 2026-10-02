<?php

/**
 * Ancien expéditeur Brevo : il n'appelle plus Brevo. Les e-mails d'étape sont envoyés par
 * ISPAG_Phase_Mail (templates achats_template_mail, wp_mail). Cette classe ne fait que l'initialiser,
 * pour que ISPAG_Mail_Sender::init() de ispag-project-manager.php continue de fonctionner.
 */
class ISPAG_Mail_Sender {

    public static function init() {
        ISPAG_Phase_Mail::init();
    }
}
