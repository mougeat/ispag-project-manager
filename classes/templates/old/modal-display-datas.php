
<?php
/**
 * ISPAG Article Modal View
 * * @package    ISPAG_Project_Manager
 * @version    1.0.0
 * @author     Cyril Barthel
 * @description Vue modernisée de la modale article avec grille responsive et badges de statut.
 */

?>
        <?php $user_can = current_user_can('manage_order'); ?>
        <?php $can_view_prices = current_user_can('display_sales_prices'); ?>
        <h2>
        <?php 
            echo esc_html(stripslashes($article->Article));        
        ?>
        </h2>
        
        <div class="ispag-modal-grid">

            <!--  Gauche : image + description -->
            <div class="ispag-modal-left" id="modal_img">
                <?php
                $content = trim($article->image);
                if (strpos($content, '<svg') === 0) {
                    // C’est un SVG brut, on l'affiche directement
                    echo $content;
                } else {
                    // Sinon, on considère que c'est une URL ou un chemin vers une image
                    $src = htmlspecialchars($content, ENT_QUOTES);
                    echo '<img src="' . $src . '" alt="image" style="max-width:100%; max-height:400px;">';
                }
                ?>
            
            
                
            </div>

            <!-- Droite : données clés -->
            <div class="ispag-modal-right">
                <p id="article-description">
                <?php 
                    echo wp_kses_post(nl2br(stripslashes($article->Description))); 

                ?>
                </p>

                <button class="ispag-btn-copy-description" data-target="#article-description"><img draggable="false" role="img" class="emoji" alt="📋" src="https://s.w.org/images/core/emoji/15.1.0/svg/1f4cb.svg"></button>

                
            </div>

        </div> <!-- ispag-modal-grid -->

        <div class="ispag-modal-grid ispag-bloc-common">
            <!-- Gauche : dates et fournisseur -->
            <div class="ispag-modal-left">
                <div class="ispag-modal-meta">
                    <?php if ($user_can) { ?>
                        <p><strong><?php echo __('Supplier', 'creation-reservoir'); ?>:</strong> <?php echo esc_html($article->fournisseur_nom) ?></p>
                    <?php } ?>
                    <p><strong><?php echo __('Factory departure date', 'creation-reservoir'); ?>:</strong> <?php echo ($article->TimestampDateDeLivraison ? date('d.m.Y', $article->TimestampDateDeLivraison) : '-'); ?></p>
                    <p><strong><?php echo __('Delivery ETA', 'creation-reservoir'); ?>:</strong> <?php echo ($article->TimestampDateDeLivraisonFin ? date('d.m.Y', $article->TimestampDateDeLivraisonFin) : '-'); ?></p>
                </div>
            </div>
            <div class="ispag-modal-right">
                <p><strong><?php echo __('Group', 'creation-reservoir'); ?>:</strong> <?php echo esc_html(stripslashes($article->Groupe)) ?></p>
                <?php if ($user_can) { ?>
                    <p><strong><?php echo __('Master article', 'creation-reservoir'); ?>:</strong> <?php echo esc_html($article->IdArticleMaster) ?></p>
                <?php } ?>
                <p><strong><?php echo __('Quantity', 'creation-reservoir'); ?>:</strong> <?php echo intval($article->Qty) ?></p>
                <?php if ($can_view_prices){ ?>
                <p><strong><?php echo __('Gross unit price', 'creation-reservoir'); ?>:</strong> <?php echo number_format((float) $article->prix_total_calculé, 2, '.', ' ') ?> <?php echo get_option('wpcb_currency'); ?></p>
                <p><strong><?php echo __('Discount', 'creation-reservoir'); ?>:</strong> <?php echo number_format($article->discount, 2) ?> %</p>
                <?php } ?>
            </div>
            
        </div> <!-- .ispag-modal-grid -->
        <!-- Droite : statuts -->
        <?php if ($user_can) { ?>
            
            <div class="ispag-modal-status">
                <p><?php echo __('Purchase requested', 'creation-reservoir'); ?>: <?php echo ($article->DemandeAchatOk ? '✅' : '❌'); ?></p>
                <p><?php echo __('Drawing approved', 'creation-reservoir'); ?>: <?php echo ((int)$article->DrawingApproved === 1 ? '✅' : '❌'); ?></p>
                <p><?php echo __('Delivered', 'creation-reservoir'); ?>: <?php echo ($article->Livre ? '✅' : '❌'); ?></p>
                <p><?php echo __('Invoiced', 'creation-reservoir'); ?>: <?php echo ($article->invoiced ? '✅' : '❌'); ?></p>
            </div>
            <?php
        }
        ?>