<?php

class ISPAG_Tank_Designer {
    private $wpdb;
    private $table_conception;
    private $table_dimensions;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
    }

    public function get_conception_options($article_id) {
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table_conception} WHERE articleId = %d ORDER BY sort ASC",
            $article_id
        ));

        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row->SelectType][] = $row;
        }

        return $grouped;
    }

    public function get_dimensions($article_id) {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table_dimensions} WHERE customerTankId = %d LIMIT 1",
            $article_id
        ));
    }

    public function save_dimensions($article_id, $deal_id, $data) {
        $existing = $this->get_dimensions($article_id);
        $row = [
            'hubspot_deal_id' => $deal_id,
            'customerTankId' => $article_id,
            'TankType' => intval($data['TankType'] ?? 0),
            'Material' => intval($data['Material'] ?? 0),
            'Support' => intval($data['Support'] ?? 0),
            'Volume' => intval($data['Volume'] ?? 0),
            'Diameter' => intval($data['Diameter'] ?? 0),
            'Height' => intval($data['Height'] ?? 0),
            'FeetHeight' => intval($data['FeetHeight'] ?? 200),
            'GroundClearance' => intval($data['GroundClearance'] ?? 0),
            'MaxPressure' => floatval($data['MaxPressure'] ?? 0),
            'TestPressure' => floatval($data['TestPressure'] ?? 0),
            'usingTemperature' => sanitize_text_field($data['usingTemperature'] ?? ''),
            'InsulationThickness' => intval($data['InsulationThickness'] ?? 0),
            'insulation' => intval($data['insulation'] ?? 0),
            'userId' => get_current_user_id(),
        ];

        if ($existing) {
            $this->wpdb->update($this->table_dimensions, $row, ['customerTankId' => $article_id]);
        } else {
            $this->wpdb->insert($this->table_dimensions, $row);
        }
    }
}
