<?php
defined('ABSPATH') || exit;

/**
 * PDF d'offre remis au client (bouton « Offer PDF » d'une offre).
 *
 * Mise en page : adresse du client, références, texte d'introduction, puis les articles par groupe
 * (intertitre + code de position + description + prix), totaux de groupe, total brut, rabais, RPLP, TVA,
 * conditions et bloc de signature. Une variante (article « en variante ») s'imprime entre parenthèses et
 * n'entre dans aucun total. Les textes viennent de ISPAG_Offer_PDF_Settings.
 */
class ISPAG_Offer_PDF extends ISPAG_PDF_Generator {

    const L      = 18;     // marge gauche
    const R      = 192;    // bord droit utile
    const INK    = [33, 37, 41];
    const MUTED  = [110, 116, 124];
    const LINE   = [200, 204, 210];

    // Colonnes du tableau (x, largeur)
    const C_POS = [18, 14];
    const C_TXT = [32, 64];
    const C_UQ  = [96, 9];
    const C_QTY = [105, 12];
    const C_GRS = [117, 19];   // prix brut
    const C_DSC = [136, 13];   // rabais %
    const C_NET = [149, 21];   // prix net
    const C_AMT = [170, 22];   // montant (net × quantité)

    private $offer_number = '';
    private $company_sign = '';

    // ------------------------------------------------------------------ données

    private static function txt($s): string {
        return ISPAG_Delivery_Note_PDF::plain_text($s);
    }

    private static function money(float $n, bool $paren = false): string {
        $s = ($n < 0 ? '-' : '') . number_format(abs($n), 2, '.', "'");
        return $paren ? '(' . $s . ')' : $s;
    }

    /** Adresse de l'entreprise cliente : nom, rue, NPA localité. */
    private static function company_lines($project): array {
        $lines = [];
        $name = self::txt($project->nom_entreprise ?? '');
        if ($name !== '' && $name !== 'N/C') $lines[] = $name;
        $cid = (int) ($project->AssociatedCompanyID ?? 0);
        if ($cid && class_exists('ISPAG_Crm_Company_Constants')) {
            global $wpdb;
            $t = ISPAG_Crm_Company_Constants::TABLE_COMPANY_META;
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$t} WHERE company_id = %d AND meta_key IN (%s, %s, %s) ORDER BY meta_id ASC", $cid,
                ISPAG_Crm_Company_Constants::META_COMPANY_ADDRESS, ISPAG_Crm_Company_Constants::META_COMPANY_POSTAL_CODE, ISPAG_Crm_Company_Constants::META_COMPANY_CITY
            ));
            $m = [];
            foreach ($rows as $r) $m[$r->meta_key] = self::txt($r->meta_value);   // la dernière valeur écrite l'emporte
            $street = $m[ISPAG_Crm_Company_Constants::META_COMPANY_ADDRESS] ?? '';
            $zipcity = trim(($m[ISPAG_Crm_Company_Constants::META_COMPANY_POSTAL_CODE] ?? '') . ' ' . ($m[ISPAG_Crm_Company_Constants::META_COMPANY_CITY] ?? ''));
            if ($street !== '') $lines[] = $street;
            if ($zipcity !== '') $lines[] = $zipcity;
        }
        return $lines;
    }

    /** Premier contact du projet : [prénom, nom, nom affiché]. */
    private static function contact($project): array {
        $ids = array_filter(array_map('intval', preg_split('/[;,\s]+/', (string) ($project->AssociatedContactIDs ?? ''))));
        foreach ($ids as $id) {
            $u = get_userdata($id);
            if ($u) {
                $first = trim((string) $u->first_name);
                $last  = trim((string) $u->last_name);
                if ($first === '' && $last === '') { $first = ''; $last = (string) $u->display_name; }
                return [$first, $last, (string) $u->display_name];
            }
        }
        return ['', '', ''];
    }

    private function fill(string $text, array $contact): string {
        return trim(preg_replace('/\s{2,}/', ' ', strtr($text, ['{PRENOM}' => $contact[0], '{NOM}' => $contact[1]])));
    }

    // ------------------------------------------------------------------ construction

    public function build($project, array $grouped) {
        $S = 'ISPAG_Offer_PDF_Settings';
        $num = (string) ($project->NumCommande ?? '');
        $this->offer_number = $num;
        $this->company_sign = trim($S::get('signature_company')) ?: (string) get_option('wpcb_companyName', '');
        $contact = self::contact($project);
        $me = wp_get_current_user();

        $this->SetCreator('ISPAG');
        $this->SetTitle($this->cleanStr('Offre ' . $num), true);
        $this->SetMargins(self::L, 15, 210 - self::R);
        $this->SetAutoPageBreak(true, 22);
        $this->AliasNbPages();
        $this->AddPage();

        // --- Logo, adresse du client ---
        $this->drawLogo(self::R - 46, 10, 46, 18);
        $this->SetXY(self::L, 42);
        $this->setInk();
        $this->SetFont('Arial', '', 9.5);
        $addr = self::company_lines($project);
        if ($contact[2] !== '') array_splice($addr, min(1, count($addr)), 0, [trim($contact[0] . ' ' . $contact[1]) ?: $contact[2]]);
        foreach ($addr as $l) { $this->Cell(90, 4.6, $this->cleanStr($l), 0, 1); }

        // --- Titre et références ---
        $this->SetY(80);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 8, 'Offre', 0, 1);
        $this->Ln(2);

        $base = preg_replace('/\.\d+$/', '', $num);
        $created = !empty($project->date_creation) ? date('d.m.Y', strtotime($project->date_creation)) : '';
        $rows = [
            ['No. offre:', $num, 'No. offre:', $base],
            ['Date offre:', date('d.m.Y'), 'Date offre:', $created],
            null,
            ['Notre référence:', $me->display_name, 'Votre référence:', $contact[2]],
        ];
        $this->SetFont('Arial', '', 9);
        foreach ($rows as $r) {
            if ($r === null) { $this->Ln(4); continue; }
            $y = $this->GetY();
            $this->SetXY(self::L, $y); $this->Cell(34, 4.6, $this->cleanStr($r[0]));
            $this->Cell(52, 4.6, $this->cleanStr($r[1]));
            $this->Cell(34, 4.6, $this->cleanStr($r[2]));
            $this->Cell(50, 4.6, $this->cleanStr($r[3]), 0, 1);
        }

        $this->Ln(5);
        $this->SetFont('Arial', 'B', 11.5);
        $this->MultiCell(0, 6, $this->cleanStr(self::txt($project->ObjetCommande ?? '')), 0, 'L');
        $this->Ln(5);

        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->cleanStr($this->fill($S::get('greeting'), $contact)), 0, 1);
        $this->Ln(2);
        $this->MultiCell(0, 4.6, $this->cleanStr(self::txt($S::get('intro'))), 0, 'L');
        $this->Ln(6);

        $this->tableHeader();

        // --- Groupes ---
        $net = 0.0;
        foreach ($grouped as $group => $articles) {
            $lines = [];
            foreach ($articles as $a) {
                if (isset($a->customer_visible) && (int) $a->customer_visible === 0) continue;   // article non visible du client
                $lines[] = $a;
                foreach ((array) ($a->secondaires ?? []) as $sec) {
                    if (isset($sec->customer_visible) && (int) $sec->customer_visible === 0) continue;
                    if (!empty($sec->archive)) continue;
                    $lines[] = $sec;
                }
            }
            if (!$lines) continue;

            $this->need(30);
            $this->SetFont('Arial', 'B', 12);
            $this->setInk();
            $this->SetX(self::C_TXT[0]);
            $this->Cell(100, 7, $this->cleanStr(stripslashes($group)), 0, 1);
            $this->Ln(1);

            $g_total = 0.0;
            foreach ($lines as $a) {
                $qty   = max(0, (float) $a->Qty);
                $unit  = (float) ($a->prix_total_calculé ?? 0);
                $net_unit = (float) ($a->prix_net_calculé ?? 0);
                $net_a    = round($net_unit * $qty, 2);
                $variant  = !empty($a->is_variant);
                if (!$variant) { $g_total += $net_a; $net += $net_a; }
                $this->articleBlock($a, $unit, $net_unit, $qty, $net_a, $variant);
            }

            $this->need(10);
            $this->Ln(1);
            $this->SetFont('Arial', 'B', 9);
            $this->SetX(self::C_TXT[0]);
            $this->Cell(self::C_AMT[0] - self::C_TXT[0], 5, $this->cleanStr('Total ' . stripslashes($group)));
            $this->Cell(self::C_AMT[1], 5, self::money($g_total), 0, 1, 'R');
            $this->Ln(6);
        }

        // --- Totaux ---
        $this->totals($net, (float) get_option('rplp', 0), (float) get_option('wpcb_vat', 8.1));

        // --- Conditions, clôture, signature ---
        $this->closing($S, $project);
    }

    private function setInk() { $this->SetTextColor(self::INK[0], self::INK[1], self::INK[2]); }

    /** Saute de page s'il reste moins de $h mm. */
    private function need(float $h) {
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
    }

    private function tableHeader() {
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(self::MUTED[0], self::MUTED[1], self::MUTED[2]);
        $y = $this->GetY();
        $this->SetXY(self::C_POS[0], $y); $this->Cell(self::C_POS[1], 5, 'Position');
        $this->Cell(self::C_TXT[1], 5, 'Texte');
        $this->Cell(self::C_UQ[1], 5, 'UQ');
        $this->Cell(self::C_QTY[1], 5, $this->cleanStr('Qté'), 0, 0, 'R');
        $this->Cell(self::C_GRS[1], 5, 'Prix brut', 0, 0, 'R');
        $this->Cell(self::C_DSC[1], 5, 'Rabais', 0, 0, 'R');
        $this->Cell(self::C_NET[1], 5, 'Prix net', 0, 0, 'R');
        $this->Cell(self::C_AMT[1], 5, 'Montant', 0, 1, 'R');
        $this->SetDrawColor(self::LINE[0], self::LINE[1], self::LINE[2]);
        $this->Line(self::L, $this->GetY(), self::R, $this->GetY());
        $this->Ln(2);
        $this->setInk();
    }

    /** Lignes de texte (déjà nettoyées) : titre de l'article, puis description sans répéter le titre. */
    private function textLines($article): array {
        $title = self::txt(stripslashes((string) ($article->Article ?? '')));
        $desc  = self::txt((string) ($article->Description ?? ''));
        $lines = $desc === '' ? [] : array_map('trim', explode("\n", $desc));
        $norm  = function ($s) { return mb_strtolower(preg_replace('/\s+/', ' ', trim($s))); };
        if ($lines && $title !== '' && $norm($lines[0]) === $norm($title)) array_shift($lines);
        if ($title === '' && $lines) $title = array_shift($lines);
        return [$title, array_values(array_filter($lines, function ($l) { return $l !== ''; }))];
    }

    private function articleBlock($a, float $unit, float $net_unit, float $qty, float $amt, bool $variant) {
        $S = 'ISPAG_Offer_PDF_Settings';
        $type = (int) ($a->Type ?? 0);
        $heading = ''; $code = trim($S::get('code_other'));
        if ($type === 1)      { $heading = $S::get('heading_tank');       $code = $S::get('code_tank'); }
        elseif ($type === 2)  { $heading = $S::get('heading_insulation'); $code = $S::get('code_insulation'); }
        elseif ($type === 3)  { $heading = $S::get('heading_welding');    $code = $S::get('code_welding'); }

        [$title, $lines] = $this->textLines($a);

        $this->need(24);
        if ($heading !== '') {
            $this->SetFont('Arial', 'B', 9.5);
            $this->SetX(self::C_TXT[0]);
            $this->Cell(100, 5.6, $this->cleanStr($heading), 0, 1);
        }

        // Première ligne : code, titre, UQ, quantité, prix, montant
        $y = $this->GetY();
        $this->SetFont('Arial', '', 8.5);
        $this->SetXY(self::C_POS[0], $y);
        $this->Cell(self::C_POS[1], 4.2, $this->cleanStr($code));
        $this->SetXY(self::C_TXT[0], $y);
        $this->MultiCell(self::C_TXT[1], 4.2, $this->cleanStr($title), 0, 'L');
        $y_after_title = $this->GetY();
        $this->SetXY(self::C_UQ[0], $y);
        $this->Cell(self::C_UQ[1], 4.2, 'Pce');
        $this->Cell(self::C_QTY[1], 4.2, number_format($qty, 2, '.', ''), 0, 0, 'R');
        $pct = $unit > 0 ? round((1 - $net_unit / $unit) * 100, 2) : 0.0;
        $this->Cell(self::C_GRS[1], 4.2, self::money($unit), 0, 0, 'R');
        $this->Cell(self::C_DSC[1], 4.2, abs($pct) > 0.004 ? self::money(-$pct) . '%' : '', 0, 0, 'R');
        $this->Cell(self::C_NET[1], 4.2, self::money($net_unit), 0, 0, 'R');
        $this->Cell(self::C_AMT[1], 4.2, self::money($amt, $variant), 0, 0, 'R');
        $this->SetXY(self::C_TXT[0], $y_after_title);

        $this->SetFont('Arial', '', 8);
        foreach ($lines as $l) {
            $this->SetX(self::C_TXT[0]);
            $this->MultiCell(self::C_TXT[1], 3.8, $this->cleanStr($l), 0, 'L');
        }
        $this->Ln(3);
    }

    private function totals(float $net, float $rplp_rate, float $vat_rate) {
        $this->need(60);
        $label_w = self::C_UQ[0] - self::C_TXT[0];
        $row = function ($label, $unit, $qty, $base, $amount, $bold = false) use ($label_w) {
            $this->SetFont('Arial', $bold ? 'B' : '', 9);
            $this->SetX(self::C_TXT[0]);
            $this->Cell($label_w, 5.2, $this->cleanStr($label));
            $this->Cell(self::C_UQ[1], 5.2, $unit);
            $this->Cell(self::C_QTY[1], 5.2, $qty, 0, 0, 'R');
            $this->Cell(self::C_GRS[1] + self::C_DSC[1], 5.2, '');
            $this->Cell(self::C_NET[1], 5.2, $base, 0, 0, 'R');
            $this->Cell(self::C_AMT[1], 5.2, $amount, 0, 1, 'R');
        };

        $this->SetDrawColor(self::LINE[0], self::LINE[1], self::LINE[2]);
        $this->Line(self::C_TXT[0], $this->GetY(), self::R, $this->GetY());
        $this->Ln(1.5);
        $total_net = $net;
        $row('Total net HT', '', '', '', self::money($total_net), true);

        $total = $total_net;
        if ($rplp_rate > 0) {
            $rplp = round($total_net * $rplp_rate / 100, 2);
            $row('RPLP', '%', number_format($rplp_rate, 2, '.', ''), self::money($total_net), self::money($rplp));
            $total = round($total_net + $rplp, 2);
            $row('Total TVA non comprise', '', '', '', self::money($total), true);
        }
        $vat = round($total * $vat_rate / 100, 2);
        $row('Taxe sur la valeur ajoutée', '%', number_format($vat_rate, 2, '.', ''), self::money($total), self::money($vat));
        $this->Line(self::C_TXT[0], $this->GetY() + 0.5, self::R, $this->GetY() + 0.5);
        $this->Ln(2);
        $row('Total TVA comprise', '', '', '', self::money(round($total + $vat, 2)), true);
        $this->Ln(8);
    }

    /** Conditions de paiement propres à l'entreprise (champ de la fiche entreprise), sinon celles des réglages. */
    private static function payment_terms($project): string {
        $cid = (int) ($project->AssociatedCompanyID ?? 0);
        if ($cid && class_exists('ISPAG_Crm_Company_Constants')) {
            global $wpdb;
            $t = ISPAG_Crm_Company_Constants::TABLE_COMPANY_META;
            $v = trim((string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$t} WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $cid, 'ispag_company_payment_terms')));
            if ($v !== '') return $v;
        }
        return trim(ISPAG_Offer_PDF_Settings::get('payment_terms'));
    }

    private function closing($S, $project) {
        $this->need(70);
        $this->SetFont('Arial', '', 9);
        $this->setInk();
        $para = function ($text, $gap = 0) {
            $this->SetX(self::L);
            $this->MultiCell(0, 4.6, $this->cleanStr($text), 0, 'L');
            if ($gap) $this->Ln($gap);
        };

        $rplp = (float) get_option('rplp', 0);
        $note = trim(self::txt($S::get('rplp_note')));
        if ($rplp > 0 && $note !== '') {
            $rate = rtrim(rtrim(number_format($rplp, 2, '.', ''), '0'), '.');
            $para(str_replace('{RPLP}', $rate, $note), 5);
        }

        $deal_id = (int) ($project->deal_id ?? $project->hubspot_deal_id ?? 0);
        $pay = self::payment_terms($project);
        if ($pay !== '') $para('Conditions de paiement: ' . $pay);
        $valid = $S::validity_date($deal_id);
        $para('Validité de l\'offre: ' . date('d.m.Y', strtotime($valid)));
        if (trim($S::get('delivery')) !== '') $para('Livraison : ' . trim($S::get('delivery')));

        $text = trim(self::txt($S::get('closing_paragraph')));
        if ($text !== '') { $this->Ln(5); $para($text); }

        $this->Ln(5);
        $this->SetX(self::L); $this->Cell(0, 5, $this->cleanStr($S::get('closing')), 0, 1);
        $this->Ln(2);
        $this->SetX(self::L); $this->Cell(0, 5, $this->cleanStr($this->company_sign), 0, 1);

        $accept = trim(self::txt($S::get('acceptance')));
        if ($accept !== '') {
            $this->Ln(8);
            $this->SetFont('Arial', '', 7.5);
            $para($accept, 4);
            $this->SetFont('Arial', '', 9);
            $this->SetX(self::L);
            $this->Cell(0, 6, $this->cleanStr('Date : ……………………. Signature : ……………………………….'), 0, 1);
        }
    }

    // ------------------------------------------------------------------ en-tête (pages suivantes) et pied

    function Header() {
        if ($this->PageNo() < 2) return;
        $this->SetY(10);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(self::MUTED[0], self::MUTED[1], self::MUTED[2]);
        $this->SetX(self::L);
        $this->Cell(40, 4, 'Page:   ' . $this->PageNo() . '/{nb}');
        $this->Cell(80, 4, $this->cleanStr($this->offer_number));
        $this->Cell(self::R - self::L - 120, 4, $this->cleanStr($this->company_sign), 0, 1, 'R');
        $this->SetY(22);
        $this->setInk();
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(self::MUTED[0], self::MUTED[1], self::MUTED[2]);
        $parts = array_filter([
            get_option('wpcb_companyName'),
            get_option('wpcb_companyAdress'),
            trim(get_option('wpcb_companyNIP') . ' ' . get_option('wpcb_companyCity')),
            get_option('wpcb_companyPhone'),
            get_option('wpcb_companyMail'),
            get_option('wpcb_companyWebsite'),
        ]);
        $this->Cell(0, 4, $this->cleanStr(implode('  •  ', $parts)), 0, 0, 'C');
    }
}
