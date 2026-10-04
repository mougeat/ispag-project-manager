<?php
defined('ABSPATH') || exit;

/**
 * Réception d'un bulletin de livraison par QR code.
 *
 *  1. À la génération du PDF, une « réception » est enregistrée (jeton secret + contenu du bulletin) et son adresse
 *     est imprimée sous forme de QR code sur le bulletin.
 *  2. Le réceptionnaire scanne le QR code avec son téléphone : page publique (sans connexion) où il saisit son nom
 *     et signe avec le doigt.
 *  3. Le bulletin est régénéré avec nom, date et signature, puis enregistré dans les documents du projet
 *     (type « delivery_note »).
 *
 * Le jeton (128 bits aléatoires) fait office de secret : une réception ne peut être signée qu'une seule fois.
 */
class ISPAG_Delivery_Receipt {

    const ACTION_PAGE   = 'ispag_dn_receipt';
    const ACTION_SUBMIT = 'ispag_dn_receipt_submit';
    const DOC_TYPE      = 'delivery_note';
    const MAX_SIGNATURE = 700000; // octets du PNG (après décodage)

    public static function init() {
        foreach ([self::ACTION_PAGE => 'render_page', self::ACTION_SUBMIT => 'ajax_submit'] as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, $method]);
        }
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'achats_delivery_receipts';
    }

    // ------------------------------------------------------------------ création (génération du PDF)

    /** Enregistre une réception et retourne son adresse publique (pour le QR code). */
    public static function create(array $payload, int $deal_id, int $purchase_id): string {
        global $wpdb;
        $token = bin2hex(random_bytes(16));
        $wpdb->insert(self::table(), [
            'token'           => $token,
            'hubspot_deal_id' => $deal_id,
            'purchase_order'  => $purchase_id,
            'payload'         => wp_json_encode($payload),
            'created_by'      => get_current_user_id(),
            'created_at'      => current_time('mysql'),
        ]);
        return self::url($token);
    }

    public static function url(string $token): string {
        return add_query_arg(['action' => self::ACTION_PAGE, 't' => $token], admin_url('admin-ajax.php'));
    }

    private static function find(string $token) {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE token = %s", $token));
    }

    // ------------------------------------------------------------------ page publique

    public static function render_page() {
        $row = self::find(sanitize_text_field($_GET['t'] ?? ''));
        if (!$row) {
            wp_die(esc_html__('This delivery note link is not valid.', 'creation-reservoir'), '', ['response' => 404]);
        }
        $p        = json_decode((string) $row->payload, true) ?: [];
        $signed   = !empty($row->signed_at);
        $company  = (string) get_option('wpcb_companyName');
        $header   = (array) ($p['project_header'] ?? []);
        $articles = (array) ($p['articles'] ?? []);

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        $cfg = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION_SUBMIT,
            'token'   => $row->token,
            'i18n'    => [
                'nameMissing'      => __('Please enter your name.', 'creation-reservoir'),
                'signatureMissing' => __('Please sign in the box.', 'creation-reservoir'),
                'sending'          => __('Sending…', 'creation-reservoir'),
                'error'            => __('Something went wrong, please try again.', 'creation-reservoir'),
            ],
        ];
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html(__('Delivery note', 'creation-reservoir')); ?></title>
            <style>
                * { box-sizing: border-box; }
                body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: #f3f4f6; color: #212529; }
                .wrap { max-width: 560px; margin: 0 auto; padding: 16px; }
                .brand { text-align: center; padding: 8px 0 14px; font-weight: 700; color: #6b7280; letter-spacing: .04em; }
                .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 18px; margin-bottom: 14px; border-top: 4px solid #c80000; }
                h1 { margin: 0 0 4px; font-size: 20px; color: #c80000; }
                .meta { margin: 10px 0 0; font-size: 14px; }
                .meta div { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; border-bottom: 1px solid #eef0f2; }
                .meta span { color: #6b7280; }
                ul.items { list-style: none; margin: 8px 0 0; padding: 0; font-size: 14px; }
                ul.items li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #eef0f2; }
                ul.items .qty { font-weight: 700; white-space: nowrap; }
                label { display: block; font-size: 13px; font-weight: 600; margin: 4px 0 6px; color: #374151; }
                input[type=text] { width: 100%; padding: 12px; font-size: 16px; border: 1px solid #d1d5db; border-radius: 8px; }
                .pad { position: relative; border: 2px dashed #9ca3af; border-radius: 10px; background: #fff; touch-action: none; }
                .pad canvas { display: block; width: 100%; height: 190px; touch-action: none; }
                .pad .hint { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #c0c4cc; pointer-events: none; font-size: 15px; }
                .row { display: flex; gap: 10px; margin-top: 12px; }
                button { font-size: 16px; padding: 13px 16px; border-radius: 8px; border: 0; cursor: pointer; }
                .btn-clear { background: #e5e7eb; color: #374151; flex: 0 0 auto; }
                .btn-ok { background: #00a32a; color: #fff; flex: 1 1 auto; font-weight: 600; }
                button:disabled { opacity: .6; }
                .msg { margin-top: 10px; font-size: 14px; color: #b91c1c; min-height: 1.2em; }
                .done { text-align: center; }
                .done .tick { font-size: 44px; }
                .done img { max-width: 100%; max-height: 120px; margin-top: 10px; }
            </style>
        </head>
        <body>
        <div class="wrap">
            <div class="brand"><?php echo esc_html($company ?: 'ISPAG'); ?></div>

            <div class="card">
                <h1>📄 <?php esc_html_e('Delivery note', 'creation-reservoir'); ?></h1>
                <div class="meta">
                    <?php foreach ($header as $label => $value): if (trim((string) $value) === '') continue; ?>
                        <div><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(wp_strip_all_tags((string) $value)); ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($articles): ?>
                    <ul class="items">
                        <?php foreach ($articles as $a): ?>
                            <li><span><?php echo esc_html(wp_strip_all_tags(stripslashes((string) ($a['description'] ?? '')))); ?></span><span class="qty">× <?php echo esc_html((string) ($a['qty'] ?? '')); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if ($signed): ?>
                <div class="card done">
                    <div class="tick">✅</div>
                    <strong><?php esc_html_e('Reception confirmed', 'creation-reservoir'); ?></strong>
                    <p><?php echo esc_html(sprintf(__('Received by %1$s on %2$s.', 'creation-reservoir'), $row->receiver_name, mysql2date('d.m.Y H:i', $row->signed_at))); ?></p>
                </div>
            <?php else: ?>
                <form class="card" id="receipt-form" novalidate>
                    <label for="receiver-name"><?php esc_html_e('Your name', 'creation-reservoir'); ?></label>
                    <input type="text" id="receiver-name" autocomplete="name" placeholder="<?php echo esc_attr__('First and last name', 'creation-reservoir'); ?>">

                    <label style="margin-top:14px"><?php esc_html_e('Signature', 'creation-reservoir'); ?></label>
                    <div class="pad"><canvas id="pad"></canvas><div class="hint" id="pad-hint"><?php esc_html_e('Sign here with your finger', 'creation-reservoir'); ?></div></div>

                    <div class="row">
                        <button type="button" class="btn-clear" id="btn-clear">↺ <?php esc_html_e('Clear', 'creation-reservoir'); ?></button>
                        <button type="submit" class="btn-ok" id="btn-ok">✅ <?php esc_html_e('Confirm reception', 'creation-reservoir'); ?></button>
                    </div>
                    <div class="msg" id="msg" aria-live="polite"></div>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$signed): ?>
        <script>
        (function () {
            const cfg = <?php echo wp_json_encode($cfg); ?>;
            const canvas = document.getElementById('pad');
            const ctx = canvas.getContext('2d');
            const hint = document.getElementById('pad-hint');
            const msg = document.getElementById('msg');
            let drawing = false, dirty = false, last = null;

            function resize() {
                const ratio = window.devicePixelRatio || 1;
                const keep = dirty ? canvas.toDataURL() : null;
                canvas.width = canvas.clientWidth * ratio;
                canvas.height = canvas.clientHeight * ratio;
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
                if (keep) { const img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, canvas.clientWidth, canvas.clientHeight); }; img.src = keep; }
            }
            resize();
            window.addEventListener('resize', resize);

            function pt(e) { const r = canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
            canvas.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                canvas.setPointerCapture(e.pointerId);
                drawing = true; last = pt(e);
                ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(last[0] + 0.1, last[1] + 0.1); ctx.stroke();
                dirty = true; hint.style.display = 'none';
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!drawing) return;
                const p = pt(e);
                ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke();
                last = p;
            });
            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { canvas.addEventListener(ev, function () { drawing = false; }); });

            document.getElementById('btn-clear').addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight);
                dirty = false; hint.style.display = '';
            });

            document.getElementById('receipt-form').addEventListener('submit', function (e) {
                e.preventDefault();
                const name = document.getElementById('receiver-name').value.trim();
                if (!name) { msg.textContent = cfg.i18n.nameMissing; document.getElementById('receiver-name').focus(); return; }
                if (!dirty) { msg.textContent = cfg.i18n.signatureMissing; return; }
                const btn = document.getElementById('btn-ok');
                btn.disabled = true; msg.style.color = '#6b7280'; msg.textContent = cfg.i18n.sending;
                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: cfg.action, t: cfg.token, name: name, signature: canvas.toDataURL('image/png') })
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res.success) { location.reload(); return; }
                    throw new Error((res.data && res.data.message) || cfg.i18n.error);
                }).catch(function (err) {
                    btn.disabled = false; msg.style.color = '#b91c1c'; msg.textContent = err.message || cfg.i18n.error;
                });
            });
        })();
        </script>
        <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    // ------------------------------------------------------------------ signature

    public static function ajax_submit() {
        global $wpdb;
        $row = self::find(sanitize_text_field(wp_unslash($_POST['t'] ?? '')));
        if (!$row) wp_send_json_error(['message' => __('This delivery note link is not valid.', 'creation-reservoir')], 404);
        if (!empty($row->signed_at)) wp_send_json_error(['message' => __('This delivery note has already been signed.', 'creation-reservoir')], 409);

        $name = trim(sanitize_text_field(wp_unslash($_POST['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150) wp_send_json_error(['message' => __('Please enter your name.', 'creation-reservoir')], 400);

        // Signature : PNG (data URL) dessiné sur le canvas
        $data = (string) wp_unslash($_POST['signature'] ?? '');
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) wp_send_json_error(['message' => __('Please sign in the box.', 'creation-reservoir')], 400);
        $png = base64_decode($m[1], true);
        $info = $png ? @getimagesizefromstring($png) : false;
        if (!$png || strlen($png) > self::MAX_SIGNATURE || !$info || $info[2] !== IMAGETYPE_PNG) {
            wp_send_json_error(['message' => __('Invalid signature.', 'creation-reservoir')], 400);
        }

        try {
            $media_id = self::build_signed_pdf($row, $name, $png);
        } catch (Throwable $e) {
            error_log('[ISPAG delivery receipt] ' . $e->getMessage());
            wp_send_json_error(['message' => __('Something went wrong, please try again.', 'creation-reservoir')], 500);
        }

        // Une seule signature par réception (mise à jour conditionnelle)
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET signed_at = %s, receiver_name = %s, signed_ip = %s, signed_media_id = %d WHERE id = %d AND signed_at IS NULL",
            current_time('mysql'), $name, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $media_id, $row->id
        ));
        if (!$updated) wp_send_json_error(['message' => __('This delivery note has already been signed.', 'creation-reservoir')], 409);

        // La livraison est confirmée : les articles du bulletin passent à « livré » dans le projet (sans jamais bloquer la signature)
        $delivered = 0;
        try {
            $delivered = self::mark_delivered($row);
        } catch (Throwable $e) {
            error_log('[ISPAG delivery receipt] mark delivered: ' . $e->getMessage());
        }

        self::notify($row, $name, $delivered);
        wp_send_json_success();
    }

    /** Régénère le bulletin avec nom, date et signature, l'enregistre dans la médiathèque et dans les documents du projet. */
    private static function build_signed_pdf($row, string $name, string $png): int {
        global $wpdb;
        $p = json_decode((string) $row->payload, true) ?: [];

        require_once __DIR__ . '/class-ispag-pdf-generator.php';

        $tmp = wp_tempnam('ispag-signature.png');
        file_put_contents($tmp, $png);

        $now    = current_time('mysql');
        $pdf    = new ISPAG_Delivery_Note_PDF();
        $infos  = (object) ($p['infos'] ?? []);
        $pdf->generate(
            (array) ($p['project_header'] ?? []),
            (object) ['nom_entreprise' => $p['company'] ?? ''],
            $infos,
            (array) ($p['table_header'] ?? []),
            (array) ($p['articles'] ?? []),
            (string) ($p['title'] ?? __('Delivery note', 'creation-reservoir')),
            ['signed' => ['name' => $name, 'date' => mysql2date('d.m.Y H:i', $now), 'image' => $tmp]]
        );

        $upload   = wp_upload_dir();
        $filename = 'delivery-note-signed-' . $row->id . '-' . substr($row->token, 0, 8) . '.pdf';
        $target   = trailingslashit($upload['path']) . $filename;
        $pdf->Output('F', $target);
        @unlink($tmp);

        $attach_id = wp_insert_attachment([
            'guid'           => trailingslashit($upload['url']) . $filename,
            'post_mime_type' => 'application/pdf',
            'post_title'     => 'Delivery note (signed) - ' . ($p['project_header'][__('Project', 'creation-reservoir')] ?? $row->hubspot_deal_id),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $target);
        if (is_wp_error($attach_id) || !$attach_id) throw new Exception('Attachment creation failed');

        $wpdb->insert($wpdb->prefix . 'achats_historique', [
            'hubspot_deal_id' => (int) $row->hubspot_deal_id,
            'purchase_order'  => (int) $row->purchase_order,
            'Date'            => time(),
            'dateReadable'    => $now,
            'IdUser'          => (int) $row->created_by,
            'Historique'      => 'Delivery note',
            'IdMedia'         => $attach_id,
            'is_task'         => 0,
            'is_done'         => 0,
            'ClassCss'        => self::DOC_TYPE,
        ], ['%d', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s']);

        return (int) $attach_id;
    }

    /**
     * Marque « livrés » les articles du bulletin (même effet que « date de livraison » en modification groupée : Livre = 1 et date de
     * livraison = maintenant). Seuls les articles du projet concerné, pas encore livrés, sont touchés.
     * @return int nombre d'articles passés à « livré »
     */
    private static function mark_delivered($row): int {
        global $wpdb;
        $p   = json_decode((string) $row->payload, true) ?: [];
        $ids = array_values(array_filter(array_map('intval', (array) ($p['article_ids'] ?? []))));
        $deal = (int) $row->hubspot_deal_id;
        if (!$ids || !$deal || (int) $row->purchase_order > 0) return 0;

        $in  = implode(',', $ids);
        $ts  = time();
        $n   = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}achats_details_commande SET Livre = 1, TimestampDateDeLivraisonFin = %d
             WHERE Id IN ($in) AND hubspot_deal_id = %d AND (Livre IS NULL OR Livre = 0)",
            $ts, $deal
        ));
        if ($n > 0) {
            do_action('ispag_achat_set_article_as_delivered', '', $ids, $ts);
            do_action('isag_run_auto_update', $deal); // étapes du projet recalculées
        }
        return $n;
    }

    private static function notify($row, string $name, int $delivered = 0) {
        if (!class_exists('ISPAG_Notifications_Manager') || !$row->created_by) return;
        $project = (json_decode((string) $row->payload, true)['project_header'][__('Project', 'creation-reservoir')] ?? '');
        ISPAG_Notifications_Manager::send(
            [(int) $row->created_by],
            'delivery_note_signed',
            sprintf(esc_html__('✅ Delivery note signed: %s', 'ispag-crm'), esc_html($project)),
            sprintf(esc_html__('The delivery note was signed by <strong>%s</strong>. The signed PDF is in the project documents.', 'ispag-crm'), esc_html($name))
                . ($delivered > 0 ? ' ' . sprintf(esc_html__('%d article(s) marked as delivered.', 'ispag-crm'), $delivered) : ''),
            $row->hubspot_deal_id ? 'project-detail/' . (int) $row->hubspot_deal_id . '/' : '',
            (int) $row->hubspot_deal_id
        );
    }
}
