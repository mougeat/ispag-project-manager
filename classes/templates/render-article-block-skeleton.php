<?php
/**
 * Template pour le squelette de chargement d'un article
 */
?>
<div class="ispag-skeleton-wrapper">
    <div class="ispag-article">
        
        <!-- Bloc visuel (cercle de chargement) -->
        <div class="ispag-article-visual-group">
            <div class="ispag-skeleton-circle"></div>
        </div>

        <!-- Bloc en-tête & métadonnées -->
        <div class="ispag-article-header">
            <div class="ispag-title-container">
                <span class="ispag-skeleton-line ispag-w-40"></span>
            </div>
            
            <div class="ispag-article-meta">
                <span class="ispag-skeleton-line ispag-w-20" style="height: 12px; display: inline-block;"></span>
            </div>

            <!-- Fausse ligne pour simuler la zone de boutons / badges -->
            <div class="ispag-article-buttons-row" style="display: flex; gap: 8px; align-items: center; margin-top: 8px;">
                <span class="ispag-skeleton-line ispag-w-30" style="height: 24px; border-radius: 4px;"></span>
                <span class="ispag-skeleton-line ispag-w-20" style="height: 24px; border-radius: 4px;"></span>
            </div>

            <!-- Fausse ligne pour simuler les dates -->
            <div class="ispag-article-dates" style="margin-top: 10px;">
                <span class="ispag-skeleton-line ispag-w-50" style="height: 12px;"></span>
            </div>
        </div>

        <!-- Bloc des prix (simulé) -->
        <div class="ispag-article-prices">
            <div class="ispag-article-qty">
                <span class="ispag-skeleton-line ispag-w-20" style="height: 14px;"></span>
            </div>
            <div class="fields-prices" style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                <span class="ispag-skeleton-line ispag-w-40" style="height: 14px;"></span>
                <span class="ispag-skeleton-line ispag-w-30" style="height: 12px;"></span>
            </div>
        </div>

        <!-- Bloc des boutons d'action (simulés sous forme de carrés/rectangles gris) -->
        <div class="ispag-article-actions" style="display: flex; gap: 6px; align-items: center;">
            <span class="ispag-skeleton-line" style="width: 28px; height: 28px; border-radius: 4px;"></span>
            <span class="ispag-skeleton-line" style="width: 28px; height: 28px; border-radius: 4px;"></span>
            <span class="ispag-skeleton-line" style="width: 28px; height: 28px; border-radius: 4px;"></span>
        </div>

    </div>
</div>