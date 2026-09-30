<?php
/**
 * Statuts de phase (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['Id' => 1, 'sort' => 1, 'Nom' => 'Done', 'TacheComplete' => 1, 'Couleur' => '#00C875', 'ClasseCss' => 'PhaseCmdFait'],
    ['Id' => 2, 'sort' => 2, 'Nom' => 'In progress', 'TacheComplete' => 0, 'Couleur' => '#FDAB3D', 'ClasseCss' => 'PhaseCmdEnCours'],
    ['Id' => 3, 'sort' => 4, 'Nom' => 'Pending', 'TacheComplete' => 0, 'Couleur' => '#E47085', 'ClasseCss' => 'PhaseCmdEnAttente'],
    ['Id' => 4, 'sort' => 5, 'Nom' => 'Planned', 'TacheComplete' => 0, 'Couleur' => '#007DB4', 'ClasseCss' => 'PhaseCmdplaned'],
    ['Id' => 5, 'sort' => 6, 'Nom' => 'N/A', 'TacheComplete' => 1, 'Couleur' => '#A9BEE8', 'ClasseCss' => 'PhaseCmdNonApplicable'],
    ['Id' => 6, 'sort' => 7, 'Nom' => 'Problem', 'TacheComplete' => 0, 'Couleur' => '#D21034', 'ClasseCss' => 'PhaseCmdACorriger'],
    ['Id' => 7, 'sort' => 8, 'Nom' => 'Sketch', 'TacheComplete' => 0, 'Couleur' => '#8c8c8c', 'ClasseCss' => 'PhaseCmdCroquisAEnvoyer'],
    ['Id' => 10, 'sort' => 9, 'Nom' => '-', 'TacheComplete' => 0, 'Couleur' => '#ccc', 'ClasseCss' => 'PhaseCmdAutre'],
    ['Id' => 11, 'sort' => 3, 'Nom' => 'Modifications asked', 'TacheComplete' => 0, 'Couleur' => '#e66f47', 'ClasseCss' => 'PaseModifRequested'],
];
