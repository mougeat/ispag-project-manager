<?php
if (!class_exists('FPDF')) {
    require_once plugin_dir_path(__FILE__) . '../libs/fpdf/fpdf.php';
}
if (!class_exists('Fpdi')) {
    require_once plugin_dir_path(__FILE__) . '../libs/fpdi/autoload.php';
}

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


    public function generate_delivery_note($project_header, $project, $infos, $table_header, $articles, $title = 'Bulletin de livraison', bool $showTotal = false) {

        $this->title = $title; 
        $this->project = $project;
        $this->infos = $infos;
        $this->table_header = $table_header;
        $this->articles = $articles;
        $this->project_header = $project_header;

        $this->SetCreator('Cyril Barthel');
        $this->SetTitle($this->title, true);
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
        $this->Cell(50, 10, mb_convert_encoding($this->title, 'ISO-8859-1', 'UTF-8'), 0, 1, 'R');

        // Bloc gris avec infos projet
        $this->SetFillColor(230, 230, 230);
        $this->SetXY(60, 25);
        $this->SetFont('Arial', '', 10);
        $this->projectDatas($this->project_header, 130);
        // $this->Cell(140, 6, 'Projet : ' . mb_convert_encoding($this->project['name'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L', true);
        // $this->Cell(140, 6, 'Référence : ' . mb_convert_encoding($this->project['reference'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L', true);
        // $this->Cell(140, 6, 'Date : ' . date('d.m.Y'), 0, 1, 'L', true);
    }

    protected function projectDatas(?array $project_datas = null, ?int $x = null)
    {
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 10);
        $x = $x ?? $this->x_position;

        $max_width = 70; // largeur max pour le bloc texte
        $line_height = $this->ln*1.25;
        $y = $this->GetY(); // position de départ

        foreach ($project_datas as $key => $value) {
            $this->SetXY($x, $y);

            $text = (!empty($key) && is_string($key)) ? "$key : $value" : $value;
            $text = iconv('UTF-8', 'windows-1252//IGNORE', $text);

            // Définir fond gris clair
            $this->SetFillColor(230, 230, 230);

            // MultiCell pour gérer les retours à la ligne et éviter les débordements
            $this->MultiCell($max_width, $line_height, $text, 0, 'L', true);

            // Position Y mise à jour automatiquement par MultiCell
            $y = $this->GetY();
        }
    }


    protected function addClientBlock() {
        $this->SetXY(30, 50);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(90, 5, mb_convert_encoding(
            $this->infos->nom_entreprise . "\n" .
            $this->infos->contact_name . "\n" .
            $this->infos->AdresseDeLivraison . "\n" .
            $this->infos->PersonneContact . "\n" .
            $this->infos->DeliveryAdresse2 . "\n" .
            
            $this->infos->NIP . ' ' . $this->infos->City
        , 'ISO-8859-1', 'UTF-8'));
    }

    protected function addArticleTable(array $columns = [], array $rows = [], bool $showTotal = false) {
        $this->Ln(10);
        $this->SetFont('Arial', 'B', 10);


        // En-têtes dynamiques
        foreach ($columns as $col) {
            $this->SetFillColor(240, 240, 240);
            $label = iconv('UTF-8', 'windows-1252//IGNORE', $col['label']);
            $this->Cell($col['width'], 8, $label, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Arial', '', 10);
        foreach ($rows as $row) {
            // Calculer la hauteur max de la ligne
            $lineHeight = 6; // hauteur de ligne standard

            // Pour chaque colonne, calculer le nb de lignes nécessaires avec MultiCell
            $nbLines = [];
            foreach ($columns as $col) {
                $key = $col['key'];
                $text = $row[$key] ?? '';
                $nbLines[] = $this->NbLines($col['width'], iconv('UTF-8', 'windows-1252//IGNORE', $text));
            }
            $maxLines = max($nbLines);
            $rowHeight = $lineHeight * $maxLines;

            // Vérifier saut de page si nécessaire
            if($this->GetY() + $rowHeight > $this->PageBreakTrigger) {
                $this->AddPage();
                // Réafficher les en-têtes
                $this->SetFont('Arial', 'B', 10);
                foreach ($columns as $col) {
                    $label = iconv('UTF-8', 'windows-1252//IGNORE', $col['label']);
                    $this->Cell($col['width'], 8, $label, 1, 0, 'C');
                }
                $this->Ln();
                $this->SetFont('Arial', '', 10);
            }

            // Afficher chaque cellule avec MultiCell et gérer la position X et Y
            $x = $this->GetX();
            $y = $this->GetY();

            foreach ($columns as $i => $col) {
                $key = $col['key'];
                $text = iconv('UTF-8', 'windows-1252//IGNORE', $row[$key] ?? '');

                $this->SetXY($x, $y);

                $align = $col['align'] ?? 'L';

                // Affiche le texte sans bordure
                $this->MultiCell($col['width'], $lineHeight, $text, 0, $align);

                // Dessine la bordure manuellement avec la hauteur max de la ligne
                $this->Rect($x, $y, $col['width'], $rowHeight);

                $x += $col['width'];
                $this->SetXY($x, $y);
            }

            $this->SetXY($this->GetX() - array_sum(array_column($columns, 'width')), $y + $rowHeight);

            
        }
        // Si on veut la ligne de total
        if ($showTotal) {
            // $this->Ln(2);
            // Calculer la somme de la dernière colonne
            $lastKey = end($columns)['key'];
            $total = 0;
            foreach ($rows as $row) {
                $val = floatval(str_replace([' ', "'"], '', $row[$lastKey] ?? 0));
                $total += $val;
            }

            // Affichage de la ligne de total
            $this->SetFont('Arial', 'B', 10);
            $x = $this->GetX();
            $y = $this->GetY();
            foreach ($columns as $i => $col) {
                $w = $col['width'];
                if ($i === count($columns) - 1) {
                    $value = number_format($total, 2, '.', "'");
                    $text = iconv('UTF-8', 'windows-1252//IGNORE', $value);
                    $this->Cell($w, 8, $text, 1, 0, 'R');
                } elseif ($i === count($columns) - 2) {
                    $text = iconv('UTF-8', 'windows-1252//IGNORE', 'Total');
                    $this->Cell($w, 8, $text, 1, 0, 'R');
                } else {
                    $this->Cell($w, 8, '', 1, 0);
                }
            }
        }
    }

    // Méthode utilitaire pour calculer le nombre de lignes qu’occupera un texte dans une largeur donnée
    protected function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 and $s[$nb - 1] == "\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j)
                        $i++;
                } else
                    $i = $sep + 1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
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

        $this->Cell(0, 5, iconv('UTF-8', 'windows-1252', $line1), 0, 1, 'C');
        $this->Cell(0, 5, iconv('UTF-8', 'windows-1252', $line2), 0, 1, 'C');

        // Numéro de page
        $this->Cell(0, 5, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }

}
