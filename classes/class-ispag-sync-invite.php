<?php
defined('ABSPATH') || exit;

/**
 * Invitation discrète, réservée aux commerciaux ISPAG, à ajouter à leur téléphone / agenda :
 *  - livraisons + leurs propres rappels de tâches (lien d'abonnement personnel, ouvert directement par l'iPhone et le Mac : webcal://),
 *  - contacts du CRM (compte CardDAV du site : mot de passe d'application à créer une fois).
 * Affichée sous le contenu de la page d'accueil et de la page des livraisons ; « Plus tard » la masque 30 jours, « C'est fait » pour toujours.
 */
class ISPAG_Sync_Invite {

    const META   = 'ispag_sync_invite';
    const ACTION = 'ispag_sync_invite_state';
    const NONCE  = 'ispag_sync_invite';

    public static function init() {
        add_filter('the_content', [self::class, 'append'], 30);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax_state']);
    }

    /** Commerciaux ISPAG (et administrateurs). Les rôles se changent avec le filtre « ispag_sync_invite_roles ». */
    public static function eligible(?WP_User $user = null): bool {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->ID) return false;
        $roles = (array) apply_filters('ispag_sync_invite_roles', ['administrator', 'vente_ispag', 'ispag_commercial']);
        return (bool) array_intersect($roles, (array) $user->roles);
    }

    private static function hidden(int $uid): bool {
        $m = get_user_meta($uid, self::META, true);
        if (!is_array($m)) return false;
        return !empty($m['done']) || (!empty($m['until']) && (int) $m['until'] > time());
    }

    public static function ajax_state() {
        if (!is_user_logged_in() || !wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE) || !self::eligible()) wp_send_json_error([], 403);
        $v = sanitize_key($_POST['value'] ?? '');
        $uid = get_current_user_id();
        if ($v === 'done')      update_user_meta($uid, self::META, ['done' => 1, 't' => time()]);
        elseif ($v === 'later') update_user_meta($uid, self::META, ['until' => time() + 30 * DAY_IN_SECONDS, 't' => time()]);
        else wp_send_json_error([], 400);
        wp_send_json_success();
    }

    public static function append($content) {
        if (is_admin() || !in_the_loop() || !is_main_query() || !is_user_logged_in()) return $content;
        if (!has_shortcode((string) $content, 'ispag_home') && !has_shortcode((string) $content, 'ispag_calendar_livraisons')) return $content;
        return $content . self::render();
    }

    public static function render(): string {
        $user = wp_get_current_user();
        if (!self::eligible($user) || self::hidden((int) $user->ID)) return '';
        $cal_url = class_exists('ISPAG_Baikal_Calendar_Sync') ? ISPAG_Baikal_Calendar_Sync::feed_url((int) $user->ID) : '';
        $has_cal = $cal_url !== '';
        $has_contacts = class_exists('ISPAG_Crm_Carddav_Server') && ISPAG_Crm_Carddav_Server::enabled();
        if (!$has_cal && !$has_contacts) return '';
        $webcal   = preg_replace('#^https?://#', 'webcal://', $cal_url);
        $profile  = admin_url('profile.php#application-passwords-section');
        $host     = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $nonce    = wp_create_nonce(self::NONCE);

        ob_start();
        ?>
        <aside class="ispag-sync-invite" id="ispag-sync-invite" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" aria-label="<?php esc_attr_e('Your ISPAG agenda and contacts', 'creation-reservoir'); ?>">
            <style>
                .ispag-sync-invite{max-width:1100px;margin:28px auto;padding:18px 22px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04)}
                .ispag-sync-invite h3{margin:0 0 4px;font-size:1.05rem}
                .ispag-sync-invite .si-lead{margin:0 0 14px;color:#6b7280;font-size:.95rem}
                .ispag-sync-invite .si-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
                .ispag-sync-invite .si-col{padding:14px;border-radius:10px;background:#f8fafc;border:1px solid #eef0f2}
                .ispag-sync-invite .si-col strong{display:block;margin-bottom:4px}
                .ispag-sync-invite .si-col p{margin:0 0 10px;color:#6b7280;font-size:.9rem;line-height:1.45}
                .ispag-sync-invite .si-btn{display:inline-block;background:var(--ispag-red,#d32f2f);color:#fff!important;text-decoration:none!important;font-weight:600;padding:8px 16px;border-radius:8px;font-size:.9rem}
                .ispag-sync-invite .si-link{background:none;border:0;padding:0 0 0 12px;color:#6b7280;text-decoration:underline;cursor:pointer;font-size:.85rem}
                .ispag-sync-invite details{margin-top:8px;font-size:.88rem;color:#4b5563}.ispag-sync-invite details summary{cursor:pointer;color:var(--ispag-red,#d32f2f)}
                .ispag-sync-invite ol{margin:6px 0 0 18px;padding:0}.ispag-sync-invite code{background:#eef0f2;padding:1px 5px;border-radius:4px;word-break:break-all}
                .ispag-sync-invite .si-foot{display:flex;justify-content:flex-end;gap:6px;margin-top:12px}
                .ispag-sync-invite .si-ok{color:#1e7b34;font-size:.85rem;margin-left:8px}
            </style>
            <h3>📅 <?php esc_html_e('Your agenda and contacts, on your phone', 'creation-reservoir'); ?></h3>
            <p class="si-lead"><?php esc_html_e('Deliveries, your task reminders and your CRM contacts follow you automatically: nothing to retype, always up to date.', 'creation-reservoir'); ?></p>
            <div class="si-cols">
                <?php if ($has_cal): ?>
                <div class="si-col">
                    <strong><?php esc_html_e('Agenda', 'creation-reservoir'); ?></strong>
                    <p><?php esc_html_e('Delivery dates and your own task reminders in your iPhone, Mac or Outlook calendar.', 'creation-reservoir'); ?></p>
                    <a class="si-btn" href="<?php echo esc_url($webcal, ['webcal']); ?>"><?php esc_html_e('Add to my agenda', 'creation-reservoir'); ?></a>
                    <button type="button" class="si-link" data-si-copy="<?php echo esc_attr($cal_url); ?>"><?php esc_html_e('Copy the link (Outlook, Google)', 'creation-reservoir'); ?></button><span class="si-ok" hidden>✓</span>
                </div>
                <?php endif; ?>
                <?php if ($has_contacts): ?>
                <div class="si-col">
                    <strong><?php esc_html_e('Contacts', 'creation-reservoir'); ?></strong>
                    <p><?php esc_html_e('Your CRM contacts, with their photo, in the Phone and Messages apps of your iPhone.', 'creation-reservoir'); ?></p>
                    <details>
                        <summary><?php esc_html_e('How to add them (2 minutes)', 'creation-reservoir'); ?></summary>
                        <ol>
                            <li><?php printf(wp_kses(__('Create your <a href="%s">application password</a> (name: iPhone) and copy it.', 'creation-reservoir'), ['a' => ['href' => []]]), esc_url($profile)); ?></li>
                            <li><?php esc_html_e('iPhone: Settings → Contacts → Accounts → Add account → Other → Add CardDAV account.', 'creation-reservoir'); ?></li>
                            <li><?php esc_html_e('Server:', 'creation-reservoir'); ?> <code><?php echo esc_html($host); ?></code> · <?php esc_html_e('User:', 'creation-reservoir'); ?> <code><?php echo esc_html($user->user_login); ?></code> · <?php esc_html_e('Password: the application password.', 'creation-reservoir'); ?></li>
                        </ol>
                    </details>
                </div>
                <?php endif; ?>
            </div>
            <div class="si-foot">
                <button type="button" class="si-link" data-si-state="later"><?php esc_html_e('Maybe later', 'creation-reservoir'); ?></button>
                <button type="button" class="si-link" data-si-state="done"><?php esc_html_e('Done, do not show again', 'creation-reservoir'); ?></button>
            </div>
            <script>
            (function () {
                var box = document.getElementById('ispag-sync-invite'); if (!box) return;
                box.addEventListener('click', function (e) {
                    var c = e.target.closest('[data-si-copy]');
                    if (c) {
                        var url = c.getAttribute('data-si-copy'), ok = box.querySelector('.si-ok');
                        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).catch(function () { var t = document.createElement('input'); t.value = url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); })
                            .then(function () { if (ok) ok.hidden = false; }, function () {});
                        if (ok) ok.hidden = false;
                        return;
                    }
                    var s = e.target.closest('[data-si-state]');
                    if (s) {
                        var fd = new FormData(); fd.append('action', <?php echo wp_json_encode(self::ACTION); ?>); fd.append('nonce', box.dataset.nonce); fd.append('value', s.getAttribute('data-si-state'));
                        try { fetch(box.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }); } catch (x) {}
                        box.style.transition = 'opacity .25s'; box.style.opacity = '0'; setTimeout(function () { box.remove(); }, 260);
                    }
                });
            })();
            </script>
        </aside>
        <?php
        return (string) ob_get_clean();
    }
}
