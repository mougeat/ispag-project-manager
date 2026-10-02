<?php
defined('ABSPATH') || exit;

/**
 * Bulletin de livraison (PDF) : même mise en page moderne que le bon de commande
 * (en-tête avec filet rouge, cartes grises à liseré rouge, tableau à lignes alternées, pied de page société).
 *
 * Instancié à la demande : la classe parente ISPAG_PDF_Generator doit être chargée avant l'autoload de celle-ci
 * (voir ispag_generate_pdf()).
 */
class ISPAG_Delivery_Note_PDF extends ISPAG_PDF_Generator {

    const MARGIN  = 15;
    const CONTENT = 180; // largeur utile (A4 − 2 × marges)
    const RED     = [200, 0, 0];
    const INK     = [33, 37, 41];
    const MUTED   = [120, 126, 134];
    const LINE    = [225, 228, 232];
    const ZEBRA   = [247, 248, 250];
    const CARD    = [243, 244, 246];

    /** Texte brut propre : retire les balises HTML, décode les entités (même doublement encodées), normalise les retours à la ligne. */
    public static function plain_text($text) {
        $text = stripslashes((string) $text);
        for ($i = 0; $i < 3; $i++) {
            $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
            $text = preg_replace('#</\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $text);
            $text = preg_replace('#<\s*/?\s*[a-z][a-z0-9:-]*\b[^>]*>#i', '', $text);
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }
        $text = str_replace(["\r", "\xC2\xA0"], ['', ' '], $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ ?\n ?/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    protected function color(array $c, string $what = 'text') {
        if ($what === 'fill') $this->SetFillColor($c[0], $c[1], $c[2]);
        elseif ($what === 'draw') $this->SetDrawColor($c[0], $c[1], $c[2]);
        else $this->SetTextColor($c[0], $c[1], $c[2]);
    }

    /** Colonnes : largeurs proportionnelles ramenées à la largeur utile. */
    protected function scaleColumns(array $columns): array {
        $sum = array_sum(array_column($columns, 'width')) ?: 1;
        foreach ($columns as &$col) {
            $col['w'] = $col['width'] * self::CONTENT / $sum;
            $col['align'] = $col['align'] ?? 'L';
        }
        return $columns;
    }

    public function generate($project_header, $project, $infos, array $table_header, array $articles, $title = 'Delivery note') {
        $this->title          = $title;
        $this->project        = $project;
        $this->infos          = $infos;
        $this->project_header = $project_header;

        $this->SetCreator('ISPAG');
        $this->SetTitle($this->cleanStr($title), true);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(true, 25);
        $this->AliasNbPages();
        $this->AddPage();

        $this->drawHeader();
        $bottom = $this->drawInfoCards();
        $this->SetY($bottom + 10);
        $this->drawTable($this->scaleColumns($table_header), $articles);
        $this->drawReceptionBox();
    }

    protected function drawHeader() {
        $this->drawLogo(self::MARGIN, 12, 42, 16);

        $this->SetXY(self::MARGIN, 12);
        $this->SetFont('Arial', 'B', 22);
        $this->color(self::RED);
        $this->Cell(self::CONTENT, 10, $this->cleanStr($this->title), 0, 1, 'R');

        $this->color(self::RED, 'draw');
        $this->SetLineWidth(0.6);
        $this->Line(self::MARGIN, 32, self::MARGIN + self::CONTENT, 32);
        $this->SetLineWidth(0.2);
    }

    /** Deux cartes : adresse de livraison + contact sur place (gauche), références du bulletin (droite). Retourne le bas des cartes. */
    protected function drawInfoCards(): float {
        $top = 40;
        $w   = 87;
        $gap = self::CONTENT - 2 * $w;
        $xL  = self::MARGIN;
        $xR  = self::MARGIN + $w + $gap;

        // --- Adresse de livraison ---
        $lines = [];
        $company = self::plain_text($this->project->nom_entreprise ?? $this->infos->nom_entreprise ?? '');
        if ($company !== '') $lines[] = $company;
        foreach (['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3'] as $k) {
            $v = self::plain_text($this->infos->$k ?? '');
            if ($v !== '') $lines[] = $v;
        }
        $zip_city = trim(self::plain_text(($this->infos->NIP ?? '') . ' ' . ($this->infos->City ?? '')));
        if ($zip_city !== '') $lines[] = $zip_city;

        $contact = array_values(array_filter([
            self::plain_text($this->infos->PersonneContact ?? ''),
            self::plain_text($this->infos->num_tel_contact ?? ''),
        ]));

        $addrH = 10 + max(1, count($lines)) * 5.2 + ($contact ? 3 + count($contact) * 5.2 : 0) + 3;

        // --- Références ---
        $meta  = (array) $this->project_header;
        $metaH = 10 + count($meta) * 6.2 + 2;

        $h = max($addrH, $metaH);

        foreach ([$xL, $xR] as $x) {
            $this->color(self::CARD, 'fill');
            $this->Rect($x, $top, $w, $h, 'F');
            $this->color(self::RED, 'fill');
            $this->Rect($x, $top, 1.2, $h, 'F');
        }

        // Carte adresse
        $this->SetXY($xL + 6, $top + 3);
        $this->SetFont('Arial', 'B', 8);
        $this->color(self::MUTED);
        $this->Cell($w - 8, 4, $this->cleanStr(mb_strtoupper(__('Delivery address', 'creation-reservoir'))), 0, 1);
        $y = $top + 9;
        foreach ($lines as $i => $line) {
            $this->SetXY($xL + 6, $y);
            $this->SetFont('Arial', $i === 0 ? 'B' : '', $i === 0 ? 11 : 10);
            $this->color(self::INK);
            $this->Cell($w - 8, 5.2, $this->cleanStr($line), 0, 1);
            $y += 5.2;
        }
        if ($contact) {
            $y += 1.5;
            $this->color(self::LINE, 'draw');
            $this->Line($xL + 6, $y, $xL + $w - 4, $y);
            $y += 1.5;
            foreach ($contact as $i => $line) {
                $this->SetXY($xL + 6, $y);
                $this->SetFont('Arial', $i === 0 ? 'B' : '', 9);
                $this->color($i === 0 ? self::INK : self::MUTED);
                $this->Cell($w - 8, 5.2, $this->cleanStr($line), 0, 1);
                $y += 5.2;
            }
        }

        // Carte références
        $this->SetXY($xR + 6, $top + 3);
        $this->SetFont('Arial', 'B', 8);
        $this->color(self::MUTED);
        $this->Cell($w - 8, 4, $this->cleanStr(mb_strtoupper(__('Reference', 'creation-reservoir'))), 0, 1);
        $y = $top + 9;
        foreach ($meta as $label => $value) {
            $this->SetXY($xR + 6, $y);
            $this->SetFont('Arial', '', 9);
            $this->color(self::MUTED);
            $this->Cell(30, 6.2, $this->cleanStr($label), 0, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->color(self::INK);
            $this->MultiCell($w - 38, 6.2, $this->cleanStr(self::plain_text($value)), 0, 'L');
            $y = max($y + 6.2, $this->GetY());
        }

        return $top + max($h, $y - $top + 2);
    }

    protected function drawTableHeader(array $columns) {
        $this->SetFont('Arial', 'B', 9);
        $this->color(self::INK);
        foreach ($columns as $col) {
            $this->Cell($col['w'], 8, $this->cleanStr($col['label'] ?? ''), 0, 0, $col['align']);
        }
        $this->Ln();
        $y = $this->GetY();
        $this->color(self::RED, 'draw');
        $this->SetLineWidth(0.5);
        $this->Line(self::MARGIN, $y, self::MARGIN + self::CONTENT, $y);
        $this->SetLineWidth(0.2);
    }

    protected function drawTable(array $columns, array $rows) {
        $lh  = 5;
        $pad = 2;

        $this->drawTableHeader($columns);
        $this->SetFont('Arial', '', 9);

        foreach ($rows as $n => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $col) {
                $text = $this->cleanStr(self::plain_text($row[$col['key']] ?? ''));
                $cells[] = $text;
                $maxLines = max($maxLines, $this->NbLines($col['w'], $text));
            }
            $rowH = $maxLines * $lh + 2 * $pad;

            if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
                $this->AddPage();
                $this->drawTableHeader($columns);
                $this->SetFont('Arial', '', 9);
            }

            $x = self::MARGIN;
            $y = $this->GetY();
            if ($n % 2 === 1) {
                $this->color(self::ZEBRA, 'fill');
                $this->Rect($x, $y, self::CONTENT, $rowH, 'F');
            }
            $this->color(self::INK);
            foreach ($columns as $i => $col) {
                $this->SetXY($x, $y + $pad);
                $this->SetFont('Arial', $col['key'] === 'qty' ? 'B' : '', 9);
                $this->MultiCell($col['w'], $lh, $cells[$i], 0, $col['align']);
                $x += $col['w'];
            }
            $this->color(self::LINE, 'draw');
            $this->Line(self::MARGIN, $y + $rowH, self::MARGIN + self::CONTENT, $y + $rowH);
            $this->SetY($y + $rowH);
        }
    }

    /** Cadre de réception à signer par le destinataire : date, nom, signature. */
    protected function drawReceptionBox() {
        $h = 30;
        if ($this->GetY() + $h + 12 > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        $x = self::MARGIN;
        $y = $this->GetY() + 12;
        $w = 110;

        $this->color(self::CARD, 'fill');
        $this->Rect($x, $y, $w, $h, 'F');
        $this->color(self::RED, 'fill');
        $this->Rect($x, $y, 1.2, $h, 'F');

        $this->SetXY($x + 6, $y + 3);
        $this->SetFont('Arial', 'B', 8);
        $this->color(self::MUTED);
        $this->Cell($w - 8, 4, $this->cleanStr(mb_strtoupper(__('Goods received', 'creation-reservoir'))), 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->color(self::INK);
        $this->SetXY($x + 6, $y + 11);
        $this->Cell(50, 5, $this->cleanStr(__('Date', 'creation-reservoir') . ' : ....................'), 0, 0);
        $this->Cell(50, 5, $this->cleanStr(__('Name', 'creation-reservoir') . ' : ....................'), 0, 1);
        $this->SetXY($x + 6, $y + 20);
        $this->Cell($w - 10, 5, $this->cleanStr(__('Signature', 'creation-reservoir') . ' :'), 0, 0);
    }

    function Footer() {
        $this->SetY(-20);
        $this->color(self::LINE, 'draw');
        $this->Line(self::MARGIN, $this->GetY(), self::MARGIN + self::CONTENT, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Arial', '', 8);
        $this->color(self::MUTED);
        $line1 = implode(' - ', array_filter([
            get_option('wpcb_companyName'),
            get_option('wpcb_companyAdress'),
            trim(get_option('wpcb_companyNIP') . ' ' . get_option('wpcb_companyCity')),
            get_option('wpcb_companyCountry'),
        ]));
        $line2 = implode(' - ', array_filter([
            get_option('wpcb_companyMail'),
            get_option('wpcb_companyPhone'),
            get_option('wpcb_companyWebsite'),
        ]));
        $this->Cell(0, 4, $this->cleanStr($line1), 0, 1, 'C');
        $this->Cell(0, 4, $this->cleanStr($line2), 0, 1, 'C');
        $this->Cell(0, 4, $this->cleanStr('Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
    }
}
