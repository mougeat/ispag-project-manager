<?php
defined('ABSPATH') || exit;

/**
 * Mise en page des listes de prix PDF (isolations, soudures sur place).
 * Les données viennent de ISPAG_Price_List_Export ; ici on ne fait que dessiner.
 *
 * Principes de mise en page :
 *  - bandeau d'en-tête sur chaque page : logo, titre, date de validité des prix, filet rouge ISPAG
 *  - une section (un produit) par page, avec titre, sous-titre et tableau lisible : lignes alternées, volume en gras
 *  - en-têtes de colonnes à deux niveaux (hauteur / épaisseur, matière / nombre de soudures)
 *  - encadré « Options et conditions » en bas de section, pied de page « Page x / y » avec les coordonnées de l'entreprise
 */
class ISPAG_Price_List_PDF extends ISPAG_PDF_Generator {

    /** Rouge ISPAG (identique aux autres documents PDF). */
    const RED   = [200, 0, 0];
    const GREY  = [244, 244, 244];
    const DARK  = [40, 40, 40];
    const MID   = [110, 110, 110];
    const LINE  = [215, 215, 215];

    /** Thème « achat » : bleu ardoise à la place du rouge ISPAG, pour ne jamais confondre avec un tarif de vente. */
    const PURCHASE_ACCENT = [31, 78, 121];
    const PURCHASE_DARK   = [17, 45, 78];
    const PURCHASE_TINT   = [233, 240, 247];

    protected $doc_title = '';
    protected $doc_subtitle = '';
    protected $purchase = false;
    protected $accent = self::RED;
    protected $accent_dark = [150, 0, 0];
    protected $tint = self::GREY;

    /** @param string $theme 'sales' (rouge ISPAG, liste clients) ou 'purchase' (bleu, usage interne) */
    public function __construct($orientation = 'P', $theme = 'sales') {
        parent::__construct($orientation, 'mm', 'A4');
        if ($theme === 'purchase') {
            $this->purchase    = true;
            $this->accent      = self::PURCHASE_ACCENT;
            $this->accent_dark = self::PURCHASE_DARK;
            $this->tint        = self::PURCHASE_TINT;
        }
        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(true, 24);
        $this->AliasNbPages();
    }

    public function begin($title, $subtitle) {
        $this->doc_title    = $title;
        $this->doc_subtitle = $subtitle;
        $this->SetCreator('ISPAG');
        $this->SetTitle($this->cleanStr($title), true);
    }

    /** Largeur utile (entre les marges). */
    public function usable_width() {
        return $this->GetPageWidth() - $this->lMargin - $this->rMargin;
    }

    // ------------------------------------------------------------------ Gabarit

    function Header() {
        $w = $this->usable_width();
        $this->drawLogo($this->lMargin, 9, 38, 15);

        $this->SetXY($this->lMargin, 9);
        $this->SetFont('Arial', 'B', 17);
        $this->SetTextColor(...$this->accent);
        $this->Cell($w, 8, $this->cleanStr($this->doc_title), 0, 2, 'R');
        $this->SetFont('Arial', '', 9.5);
        $this->SetTextColor(...self::MID);
        $this->Cell($w, 5, $this->cleanStr($this->doc_subtitle), 0, 0, 'R');

        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.7);
        $this->Line($this->lMargin, 27, $this->lMargin + $w, 27);
        $this->SetLineWidth(0.2);
        if ($this->purchase) {
            // Pastille « usage interne » à côté du logo
            $this->SetFillColor(...$this->accent_dark);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8.5);
            $bw = 52;
            $this->SetXY($this->lMargin + 46, 14); // à droite du logo, hors du titre et du sous-titre
            $this->Cell($bw, 6.5, $this->cleanStr('USAGE INTERNE - CONFIDENTIEL'), 0, 0, 'C', true);
        }
        $this->SetTextColor(0);
        $this->SetY(33);
    }

    function Footer() {
        $w = $this->usable_width();
        $this->SetY(-17);
        $this->SetDrawColor(...self::LINE);
        $this->Line($this->lMargin, $this->GetY(), $this->lMargin + $w, $this->GetY());
        $this->Ln(1.5);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(...self::MID);

        $line1 = implode(' - ', array_filter([
            get_option('wpcb_companyName'),
            get_option('wpcb_companyAdress'),
            trim(get_option('wpcb_companyNIP') . ' ' . get_option('wpcb_companyCity')),
            get_option('wpcb_companyCountry'),
        ]));
        $line2 = implode(' - ', array_filter([get_option('wpcb_companyMail'), get_option('wpcb_companyPhone'), get_option('wpcb_companyWebsite')]));
        $this->Cell($w, 4, $this->cleanStr($line1), 0, 1, 'C');
        $this->Cell($w, 4, $this->cleanStr($line2), 0, 1, 'C');
        $this->Cell($w, 4, $this->cleanStr(($this->purchase ? 'Document interne - ne pas diffuser - ' : '') . 'Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0);
    }

    // ------------------------------------------------------------------ Blocs

    /** Titre de section (nouvelle page) : grand titre + sous-titre gris. */
    public function section($title, $subtitle = '') {
        $this->AddPage();
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(...self::DARK);
        $this->Cell(0, 7, $this->cleanStr($title), 0, 1, 'L');
        if ($subtitle !== '') {
            $this->SetFont('Arial', '', 9.5);
            $this->SetTextColor(...self::MID);
            $this->MultiCell(0, 4.6, $this->cleanStr($subtitle), 0, 'L');
        }
        $this->SetTextColor(0);
        $this->Ln(3);
    }

    /**
     * Tableau de prix.
     *
     * @param array $groups  En-tête de niveau 1 : [['label' => 'Hauteur < 2500 mm', 'span' => 3], …]
     * @param array $heads   En-tête de niveau 2 : ['130 mm', '160 mm', …] (une entrée par colonne de prix)
     * @param array $rows    [['label' => '500', 'cells' => [1873.0, null, …]], …] ; null = pas de prix (affiché « - »)
     * @param array $subgroups Niveau intermédiaire facultatif (ex. isolant) : [['label' => 'Laine de roche', 'span' => 3], …]
     * @param float $rh      Hauteur d'une ligne (mm)
     * @param string $first  Libellé de la première colonne (ex. « Litres », « Diamètre »)
     */
    public function price_table(array $groups, array $heads, array $rows, $first, array $subgroups = [], $rh = 6.4) {
        $n       = count($heads);
        $first_w = 22;
        $col_w   = ($this->usable_width() - $first_w) / max(1, $n);
        $x0      = $this->lMargin;
        // Beaucoup de colonnes : police un peu plus petite pour que « 13 574.00 » tienne
        $fs      = $col_w < 17 ? 8 : 9.5;

        $draw_head = function () use ($groups, $subgroups, $heads, $first, $first_w, $col_w, $x0) {
            $this->SetFillColor(...$this->accent);
            $this->SetTextColor(255);
            $this->SetDrawColor(255);
            $this->SetFont('Arial', 'B', 9);
            $y = $this->GetY();
            $has_groups = !empty($groups);
            $h1 = $has_groups ? 7 : 0;
            $hm = $subgroups ? 6 : 0;
            $h2 = 6.5;
            $this->SetXY($x0, $y);
            $this->Cell($first_w, $h1 + $hm + $h2, $this->cleanStr($first), 1, 0, 'C', true);
            if ($has_groups) {
                foreach ($groups as $g) {
                    $this->Cell($col_w * $g['span'], $h1, $this->cleanStr($g['label']), 1, 0, 'C', true);
                }
                $this->SetXY($x0 + $first_w, $y + $h1);
            }
            if ($subgroups) {
                $this->SetFillColor(...$this->accent_dark); // second niveau : teinte plus sombre
                foreach ($subgroups as $g) {
                    $this->Cell($col_w * $g['span'], $hm, $this->cleanStr($g['label']), 1, 0, 'C', true);
                }
                $this->SetFillColor(...$this->accent);
                $this->SetXY($x0 + $first_w, $y + $h1 + $hm);
            }
            $this->SetFont('Arial', 'B', 8.5);
            foreach ($heads as $h) {
                $this->Cell($col_w, $h2, $this->cleanStr($h), 1, 0, 'C', true);
            }
            $this->SetXY($x0, $y + $h1 + $hm + $h2);
            $this->SetTextColor(0);
            $this->SetDrawColor(...self::LINE);
        };

        $draw_head();
        $i  = 0;
        foreach ($rows as $row) {
            // Saut de page au milieu du tableau : on redessine l'en-tête
            if ($this->GetY() + $rh > $this->PageBreakTrigger) {
                $this->AddPage();
                $this->SetY(33);
                $draw_head();
            }
            $fill = ($i++ % 2) === 1;
            $this->SetFillColor(...$this->tint);
            $this->SetFont('Arial', 'B', $fs);
            $this->Cell($first_w, $rh, $this->cleanStr($row['label']), 'B', 0, 'C', $fill);
            $this->SetFont('Arial', '', $fs);
            foreach ($row['cells'] as $v) {
                $txt = $v === null ? '-' : ISPAG_Price_List_Export::money($v);
                $this->SetTextColor(...($v === null ? self::MID : self::DARK));
                $this->Cell($col_w, $rh, $this->cleanStr($txt), 'B', 0, 'R', $fill);
            }
            $this->SetTextColor(0);
            $this->Ln($rh);
        }
        $this->Ln(4);
    }

    /** Encadré gris avec un titre et une liste de lignes « libellé ........ valeur ». */
    public function key_value_box($title, array $lines) {
        if (!$lines) return;
        $w    = $this->usable_width();
        $need = 9 + 5.2 * count($lines);
        if ($this->GetY() + $need > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->SetY(33);
        }
        $this->SetFillColor(...$this->tint);
        $this->SetTextColor(...$this->accent);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell($w, 7, $this->cleanStr('  ' . $title), 0, 1, 'L', true);
        $this->SetFont('Arial', '', 9);
        foreach ($lines as $l) {
            $this->SetTextColor(...self::DARK);
            $this->Cell($w * 0.72, 5.2, $this->cleanStr('   ' . $l[0]), 0, 0, 'L', true);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell($w * 0.28, 5.2, $this->cleanStr($l[1] . '  '), 0, 1, 'R', true);
            $this->SetFont('Arial', '', 9);
        }
        $this->SetTextColor(0);
        $this->Ln(3);
    }

    /** Liste à puces de conditions. */
    public function bullets($title, array $items) {
        if (!$items) return;
        $need = 8 + 4.8 * count($items);
        if ($this->GetY() + $need > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->SetY(33);
        }
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(...$this->accent);
        $this->Cell(0, 6, $this->cleanStr($title), 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(...self::DARK);
        foreach ($items as $it) {
            $this->Cell(5, 4.8, chr(149), 0, 0, 'C');
            $this->MultiCell(0, 4.8, $this->cleanStr($it), 0, 'L');
        }
        $this->SetTextColor(0);
        $this->Ln(2);
    }
}
