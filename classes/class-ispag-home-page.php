<?php
defined('ABSPATH') || exit;

/**
 * Page d'accueil ISPAG : présente aux utilisateurs la conception de réservoirs et le suivi de projet, puis les invite
 * à se connecter, ou leur donne accès à la liste des projets s'ils le sont déjà.
 *
 * Shortcode : [ispag_home]. La page « Accueil » (fr) / « Willkommen » (de) est créée par le plugin
 * (install/pages.php, voir ISPAG_Page_Installer) ; elle n'est jamais définie automatiquement comme page d'accueil du site :
 * Réglages > Lecture permet de l'utiliser comme page d'accueil statique.
 *
 * Filtres : ispag_home_texts (textes par langue), ispag_home_projects_url (adresse de la liste des projets).
 */
class ISPAG_Home_Page {

    public static function init() {
        add_shortcode('ispag_home', [self::class, 'render']);
    }

    private static function lang(): string {
        $l = function_exists('pll_current_language') ? (string) pll_current_language() : substr((string) determine_locale(), 0, 2);
        return in_array($l, ['fr', 'de', 'en'], true) ? $l : 'fr';
    }

    private static function texts(string $lang): array {
        $all = [
            'fr' => [
                'eyebrow'   => 'Plateforme ISPAG',
                'title'     => 'De la conception du réservoir à la livraison, tout au même endroit',
                'lead'      => 'Configurez vos réservoirs et suivez chaque projet, étape par étape, avec vos équipes, vos fournisseurs et vos clients.',
                'f1_t'      => 'Conception de réservoirs',
                'f1_d'      => 'Type, dimensions, soudures, piquages et isolation guidés étape par étape, avec échangeurs à plaques, plan technique et jumeau numérique 3D.',
                'f2_t'      => 'Suivi de projet',
                'f2_d'      => 'Phases de réalisation par famille d\'articles, documents, validation des plans et commentaires, pour savoir à tout moment où en est chaque projet.',
                'f3_t'      => 'Achats et livraisons',
                'f3_d'      => 'Commandes fournisseurs, réceptions, planning des livraisons et bulletins de livraison signés sur téléphone grâce au QR code.',
                'login_t'   => 'Connectez-vous pour accéder à vos projets',
                'login_d'   => 'L\'accès est réservé aux utilisateurs autorisés. Utilisez l\'identifiant que nous vous avons communiqué.',
                'login'     => 'Se connecter',
                'logged_t'  => 'Bonjour %s, vos projets vous attendent',
                'logged_d'  => 'Retrouvez vos projets en cours, leurs documents et leurs prochaines étapes.',
                'projects'  => 'Accéder à la liste des projets',
                'noaccess'  => 'Votre compte n\'a pas encore accès aux projets. Contactez votre administrateur pour obtenir les droits nécessaires.',
            ],
            'de' => [
                'eyebrow'   => 'ISPAG-Plattform',
                'title'     => 'Von der Behälterkonstruktion bis zur Lieferung, alles an einem Ort',
                'lead'      => 'Konfigurieren Sie Ihre Behälter und verfolgen Sie jedes Projekt Schritt für Schritt mit Ihren Teams, Lieferanten und Kunden.',
                'f1_t'      => 'Behälterkonstruktion',
                'f1_d'      => 'Typ, Abmessungen, Schweissnähte, Stutzen und Isolation Schritt für Schritt, mit Plattenwärmetauschern, technischer Zeichnung und digitalem 3D-Zwilling.',
                'f2_t'      => 'Projektverfolgung',
                'f2_d'      => 'Projektphasen je Artikelfamilie, Dokumente, Zeichnungsfreigabe und Kommentare: Sie wissen jederzeit, wo jedes Projekt steht.',
                'f3_t'      => 'Einkauf und Lieferungen',
                'f3_d'      => 'Lieferantenbestellungen, Wareneingänge, Lieferplanung und Lieferscheine, die per QR-Code auf dem Handy unterschrieben werden.',
                'login_t'   => 'Melden Sie sich an, um auf Ihre Projekte zuzugreifen',
                'login_d'   => 'Der Zugang ist autorisierten Benutzern vorbehalten. Verwenden Sie die Ihnen mitgeteilten Zugangsdaten.',
                'login'     => 'Anmelden',
                'logged_t'  => 'Hallo %s, Ihre Projekte warten auf Sie',
                'logged_d'  => 'Finden Sie Ihre laufenden Projekte, ihre Dokumente und die nächsten Schritte.',
                'projects'  => 'Zur Projektliste',
                'noaccess'  => 'Ihr Konto hat noch keinen Zugriff auf Projekte. Wenden Sie sich an Ihren Administrator.',
            ],
            'en' => [
                'eyebrow'   => 'ISPAG platform',
                'title'     => 'From tank design to delivery, all in one place',
                'lead'      => 'Configure your tanks and follow every project step by step with your teams, suppliers and customers.',
                'f1_t'      => 'Tank design',
                'f1_d'      => 'Type, dimensions, welding, fittings and insulation guided step by step, with plate heat exchangers, technical drawing and a 3D digital twin.',
                'f2_t'      => 'Project follow-up',
                'f2_d'      => 'Production phases per article family, documents, drawing approval and comments, so you always know where each project stands.',
                'f3_t'      => 'Purchasing and deliveries',
                'f3_d'      => 'Supplier orders, receipts, delivery schedule and delivery notes signed on a phone through the QR code.',
                'login_t'   => 'Log in to access your projects',
                'login_d'   => 'Access is reserved for authorized users. Use the credentials we gave you.',
                'login'     => 'Log in',
                'logged_t'  => 'Hello %s, your projects are waiting',
                'logged_d'  => 'Find your ongoing projects, their documents and next steps.',
                'projects'  => 'Open the project list',
                'noaccess'  => 'Your account does not have access to projects yet. Please contact your administrator.',
            ],
        ];
        return apply_filters('ispag_home_texts', $all[$lang], $lang);
    }

    /** Adresse de la liste des projets : page créée par le plugin dans la langue courante, sinon l'adresse habituelle. */
    public static function projects_url(string $lang): string {
        $keys = $lang === 'de' ? ['projects_list_de', 'projects_list'] : ['projects_list', 'projects_list_de'];
        $url  = '';
        foreach ($keys as $key) {
            $ids = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids',
                'meta_key' => ISPAG_Page_Installer::KEY_META, 'meta_value' => $key, 'suppress_filters' => true]);
            if ($ids) { $url = get_permalink($ids[0]); break; }
        }
        if ($url === '') {
            $url = home_url('/' . ($lang === 'de' ? 'projektliste' : 'liste-des-projets-new') . '/');
        }
        return (string) apply_filters('ispag_home_projects_url', $url, $lang);
    }

    public static function render($atts = []): string {
        $lang = self::lang();
        $t    = self::texts($lang);
        $url  = self::projects_url($lang);
        $svg = function (string $paths): string {
            return '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
        };
        $features = [
            ['f1', $svg('<ellipse cx="12" cy="5.5" rx="7" ry="2.5"/><path d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13"/><path d="M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/>')],
            ['f2', $svg('<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="m9 13 2 2 4-4"/>')],
            ['f3', $svg('<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7"/><circle cx="7" cy="17.5" r="1.7"/><circle cx="17" cy="17.5" r="1.7"/>')],
        ];

        ob_start();
        ?>
        <div class="ispag-home">
            <style>
                .ispag-home{--ih-accent:var(--ispag-red,#d32f2f);--ih-ink:#1f2937;--ih-muted:#6b7280;--ih-line:#e5e7eb;--ih-bg:#f8fafc;max-width:1100px;margin:0 auto;padding:0 16px;color:var(--ih-ink);box-sizing:border-box}
                .ispag-home *{box-sizing:border-box}
                .ispag-home-hero{text-align:center;padding:56px 0 36px}
                .ispag-home-eyebrow{display:inline-block;font-size:.8rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--ih-accent);margin-bottom:14px}
                .ispag-home-hero h1{font-size:clamp(1.7rem,4.5vw,2.8rem);line-height:1.15;margin:0 auto 16px;max-width:820px}
                .ispag-home-lead{font-size:clamp(1rem,2.2vw,1.2rem);color:var(--ih-muted);max-width:680px;margin:0 auto}
                .ispag-home-features{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin:12px 0 36px}
                .ispag-home-card{background:#fff;border:1px solid var(--ih-line);border-radius:14px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
                .ispag-home-icon{color:var(--ih-accent);line-height:0;margin-bottom:12px}
                .ispag-home-card h2{font-size:1.1rem;margin:0 0 8px}
                .ispag-home-card p{margin:0;color:var(--ih-muted);font-size:.95rem;line-height:1.55}
                .ispag-home-cta{background:var(--ih-bg);border:1px solid var(--ih-line);border-radius:16px;text-align:center;padding:36px 20px;margin-bottom:56px}
                .ispag-home-cta h2{font-size:1.35rem;margin:0 0 8px}
                .ispag-home-cta p{margin:0 auto 20px;color:var(--ih-muted);max-width:560px}
                .ispag-home-btn{display:inline-block;background:var(--ih-accent);color:#fff!important;text-decoration:none!important;font-weight:600;padding:14px 28px;border-radius:10px;min-height:44px;line-height:1.2}
                .ispag-home-btn:hover{filter:brightness(.92)}
                .ispag-home-note{color:var(--ih-muted);font-style:italic}
                @media (max-width:820px){.ispag-home-features{grid-template-columns:1fr}.ispag-home-hero{padding:32px 0 20px}.ispag-home-btn{display:block}}
            </style>

            <section class="ispag-home-hero">
                <span class="ispag-home-eyebrow"><?php echo esc_html($t['eyebrow']); ?></span>
                <h1><?php echo esc_html($t['title']); ?></h1>
                <p class="ispag-home-lead"><?php echo esc_html($t['lead']); ?></p>
            </section>

            <section class="ispag-home-features">
                <?php foreach ($features as [$k, $icon]): ?>
                    <article class="ispag-home-card">
                        <div class="ispag-home-icon" aria-hidden="true"><?php echo $icon; // SVG interne, sans donnée utilisateur ?></div>
                        <h2><?php echo esc_html($t[$k . '_t']); ?></h2>
                        <p><?php echo esc_html($t[$k . '_d']); ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="ispag-home-cta">
                <?php if (!is_user_logged_in()): ?>
                    <h2><?php echo esc_html($t['login_t']); ?></h2>
                    <p><?php echo esc_html($t['login_d']); ?></p>
                    <a class="ispag-home-btn" href="<?php echo esc_url(wp_login_url($url)); ?>"><?php echo esc_html($t['login']); ?></a>
                <?php else: ?>
                    <h2><?php echo esc_html(sprintf($t['logged_t'], wp_get_current_user()->display_name)); ?></h2>
                    <?php if (current_user_can('read_orders')): ?>
                        <p><?php echo esc_html($t['logged_d']); ?></p>
                        <a class="ispag-home-btn" href="<?php echo esc_url($url); ?>"><?php echo esc_html($t['projects']); ?></a>
                    <?php else: ?>
                        <p class="ispag-home-note"><?php echo esc_html($t['noaccess']); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
