<?php
// Sécurité pour WordPress
if (!defined('ABSPATH')) exit;

// Chargement de FPDF de manière sécurisée
if (!class_exists('FPDF')) {
    $fpdf_path = plugin_dir_path(__FILE__) . '../libs/fpdf/fpdf.php';
    if (file_exists($fpdf_path)) {
        require_once $fpdf_path;
    }
}

// Note : J'ai retiré le require vers FPDI car il causait l'erreur fatale 
// et n'est pas utilisé dans les fonctions ci-dessous.

class ISPAG_PDF_Generator extends FPDF {
    protected $title;
    protected $project_header;
    protected $project;
    protected $infos;
    protected $table_header;
    protected $articles;
    protected $logo_url = 'https://app.ispag-asp.ch/wp-content/uploads/2025/03/Logo_ISPAG_RGB_F.png';
    protected $x_position = 10;
    protected $footer_y_position = 29;
    protected $ln = 5;

    /**
     * Nettoyage et conversion des caractères spéciaux pour FPDF (Windows-1252)
     */
    protected function cleanStr($str) {
        if (empty($str)) return '';
        // Convertit l'UTF-8 en ISO-8859-1 (Windows-1252) et ignore les caractères incompatibles
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'windows-1252//IGNORE', $str);
        }
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    public function generate_delivery_note($project_header, $project, $infos, $table_header, $articles, $title = 'Bulletin de livraison', bool $showTotal = false) {
        $this->title = $title; 
        $this->project = $project;
        $this->infos = $infos;
        $this->table_header = $table_header;
        $this->articles = $articles;
        $this->project_header = $project_header;

        $this->SetCreator('Cyril Barthel');
        $this->SetTitle($this->cleanStr($this->title), true);
        $this->AddPage();
        $this->SetAutoPageBreak(true, 20);
        
        $this->addHeader();
        $this->addClientBlock();
        $this->addArticleTable($this->table_header, $this->articles, $showTotal);
    }

    protected function addHeader() {
        // Logo
        $this->Image($this->logo_url, 10, 10, 40);

        // Titre
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(200, 0, 0);
        $this->SetXY(150, 10);
        $this->Cell(50, 10, $this->cleanStr($this->title), 0, 1, 'R');

        // Bloc gris avec infos projet
        $this->SetFillColor(230, 230, 230);
        $this->SetXY(60, 25);
        $this->projectDatas($this->project_header, 130);
    }

    protected function projectDatas(?array $project_datas = null, ?int $x = null) {
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 10);
        $x = $x ?? $this->x_position;

        $max_width = 70;
        $line_height = $this->ln * 1.25;
        $y = $this->GetY();

        if ($project_datas) {
            foreach ($project_datas as $key => $value) {
                $this->SetXY($x, $y);
                $text = (!empty($key) && is_string($key)) ? "$key : $value" : $value;
                $text = $this->cleanStr($text);

                $this->SetFillColor(230, 230, 230);
                $this->MultiCell($max_width, $line_height, $text, 0, 'L', true);
                $y = $this->GetY();
            }
        }
    }

    protected function addClientBlock() {
        $this->SetXY(30, 50);
        $this->SetFont('Arial', '', 10);
        
        // On place tous les champs dans un tableau
        $address_lines = [
            $this->infos->nom_entreprise ?? '',
            $this->infos->contact_name ?? '',
            $this->infos->AdresseDeLivraison ?? '',
            $this->infos->PersonneContact ?? '',
            $this->infos->DeliveryAdresse2 ?? '',
            $this->infos->DeliveryAdresse3 ?? ''
        ];

        // On filtre pour enlever les éléments vides (null, '', false)
        $address_lines = array_filter($address_lines);

        // On ajoute la ligne NIP + Ville à la fin si au moins l'un des deux existe
        $zip_city = trim(($this->infos->NIP ?? '') . ' ' . ($this->infos->City ?? ''));
        if (!empty($zip_city)) {
            $address_lines[] = $zip_city;
        }

        // On assemble avec un seul saut de ligne entre chaque élément présent
        $address_string = implode("\n", $address_lines);

        $this->MultiCell(90, 5, $this->cleanStr($address_string));
    }

    protected function addArticleTable(array $columns = [], array $rows = [], bool $showTotal = false) {
        $this->Ln(10);
        $this->SetFont('Arial', 'B', 10);

        // En-têtes
        foreach ($columns as $col) {
            $this->SetFillColor(240, 240, 240);
            $label = $this->cleanStr($col['label'] ?? '');
            $this->Cell($col['width'], 8, $label, 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 10);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $lineHeight = 6;
                $nbLines = [];
                foreach ($columns as $col) {
                    $text = $row[$col['key']] ?? '';
                    $nbLines[] = $this->NbLines($col['width'], $this->cleanStr($text));
                }
                $maxLines = max($nbLines);
                $rowHeight = $lineHeight * $maxLines;

                // Gestion saut de page
                if($this->GetY() + $rowHeight > $this->PageBreakTrigger) {
                    $this->AddPage();
                    $this->SetFont('Arial', 'B', 10);
                    foreach ($columns as $col) {
                        $this->Cell($col['width'], 8, $this->cleanStr($col['label'] ?? ''), 1, 0, 'C', true);
                    }
                    $this->Ln();
                    $this->SetFont('Arial', '', 10);
                }

                $x = $this->GetX();
                $y = $this->GetY();

                foreach ($columns as $col) {
                    $text = $this->cleanStr($row[$col['key']] ?? '');
                    $this->SetXY($x, $y);
                    $align = $col['align'] ?? 'L';
                    
                    $this->MultiCell($col['width'], $lineHeight, $text, 0, $align);
                    $this->Rect($x, $y, $col['width'], $rowHeight);
                    $x += $col['width'];
                }
                $this->SetY($y + $rowHeight);
            }
        }

        if ($showTotal) {
            $this->addTotalLine($columns, $rows);
        }
    }

    protected function addTotalLine($columns, $rows) {
        $lastKey = end($columns)['key'] ?? '';
        $total = 0;
        foreach ($rows as $row) {
            $val = floatval(str_replace([' ', "'"], '', $row[$lastKey] ?? 0));
            $total += $val;
        }

        $this->SetFont('Arial', 'B', 10);
        foreach ($columns as $i => $col) {
            $w = $col['width'];
            if ($i === count($columns) - 1) {
                $value = number_format($total, 2, '.', "'");
                $this->Cell($w, 8, $this->cleanStr($value), 1, 0, 'R');
            } elseif ($i === count($columns) - 2) {
                $this->Cell($w, 8, $this->cleanStr('Total'), 1, 0, 'R');
            } else {
                $this->Cell($w, 8, '', 1, 0);
            }
        }
    }

    protected function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 and $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                continue;
            }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
                } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function Footer() {
        $this->SetY(-20);
        $this->SetFont('Arial', '', 9);

        $line1 = get_option('wpcb_companyName') . ' - ' .
                 get_option('wpcb_companyAdress') . ' - ' .
                 get_option('wpcb_companyNIP') . ' ' .
                 get_option('wpcb_companyCity') . ' - ' .
                 get_option('wpcb_companyCountry');

        $line2 = get_option('wpcb_companyMail') . ' - ' .
                 get_option('wpcb_companyPhone') . ' - ' .
                 get_option('wpcb_companyWebsite');

        $this->Cell(0, 5, $this->cleanStr($line1), 0, 1, 'C');
        $this->Cell(0, 5, $this->cleanStr($line2), 0, 1, 'C');
        $this->Cell(0, 5, $this->cleanStr('Page ' . $this->PageNo()), 0, 0, 'C');
    }
}