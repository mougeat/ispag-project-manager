<h2>
  <?= $is_new ? __('New article', 'creation-reservoir') : __('Edit article', 'creation-reservoir') . ' : ' . esc_html(stripslashes($article->Article)) ?>
</h2>

<?php
$can_view_prices = current_user_can('display_sales_prices');
?>

<form class="ispag-edit-article-form" <?= $id_attr ?>>
  <input type="text" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard) ?>">
  <div class="ispag-modal-grid">
    <?php if ($is_new): ?>
      <input type="hidden" name="type" value="<?= esc_attr($article->Type) ?>">
      
    <?php endif; ?>
      
    <div class="ispag-modal-left" id="modal_img">
      <?php
      $content = trim($article->image);
      if (strpos($content, '<svg') === 0) {
        echo $content;
      } else {
        $src = htmlspecialchars($content, ENT_QUOTES);
        echo '<img src="' . $src . '" alt="image" style="max-width:100%; max-height:400px;">';
      }
      ?>
    </div>

    <div class="ispag-modal-right" id="ispag-title-description-area">
      <?php if ($article->Type == 1): ?>
        <div id="ispag-tank-form-container">
          <?php do_action('ispag_render_tank_form', $article->Id); ?>
        </div>
      <?php else:
        $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
        $description = stripslashes($description);
      ?>
        <label>
          <?= __('Title', 'creation-reservoir') ?><br>
          <input type="text" name="article_title" value="<?= esc_attr(stripslashes($article->Article)) ?>" list="standard-titles" id="article-title" data-type="<?= esc_attr($article->Type) ?>">
          <datalist id="standard-titles">
            <?php foreach ($standard_titles['titles'] as $title): ?>
              <option value="<?= esc_attr($title['title']) ?>" data-id="<?= esc_attr($title['id']) ?>">
            <?php endforeach; ?>
          </datalist> 
        </label>
        <br>
        <label>
          <?= __('Description', 'creation-reservoir') ?><br>
          <textarea name="description" id="article-description" cols="40" rows="15"><?= esc_textarea($description) ?></textarea>
        </label>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($article->Type == 1): ?>
    <div class="ispag-modal-grid">
      <?php do_action('ispag_render_tank_dimensions_form', $article->Id); ?>
    </div>
  <?php endif; ?>

  <div class="ispag-modal-grid ispag-bloc-common">
    <?php if ($user_can): ?>
    <div class="ispag-modal-left">
      <div class="ispag-modal-meta">
        <div class="ispag-field">
          <label>
            <?= __('Supplier', 'creation-reservoir') ?>
            <input type="text" name="supplier" value="<?= esc_attr($article->fournisseur_nom) ?>" list="supplier-list">
            <datalist id="supplier-list">
              <?php
              foreach ($standard_titles['suppliers'] as $supplier) { ?>
                <option value="<?= esc_attr($supplier['name']) ?>" data-id="<?= esc_attr($supplier['id']) ?>">
              <?php } ?>
            </datalist>
          </label>
        </div>
        <div class="ispag-field">
          <label>
            <?= __('Factory departure date', 'creation-reservoir') ?>
            <input type="date" name="date_depart" value="<?= $article->TimestampDateDeLivraison ? date('Y-m-d', $article->TimestampDateDeLivraison) : '' ?>">
          </label>
        </div>
        <div class="ispag-field">
          <label>
            <?= __('Delivery ETA', 'creation-reservoir') ?>
            <input type="date" name="date_eta" value="<?= $article->TimestampDateDeLivraisonFin ? date('Y-m-d', $article->TimestampDateDeLivraisonFin) : '' ?>">
          </label>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="ispag-modal-right">
      <div class="ispag-field">
        <label>
          <?= __('Group', 'creation-reservoir') ?><br>
          <input type="text" name="group" list="group-list" value="<?= esc_attr(stripslashes($article->Groupe)) ?>">
          <datalist id="group-list">
            <?php foreach ($groupes as $groupe): ?>
              <option value="<?= esc_attr(stripslashes($groupe)) ?>">
            <?php endforeach; ?>
          </datalist>
        </label>
      </div>

      <?php if ($user_can): ?>
        <div class="ispag-field">
          <label>
            <?= __('Master article', 'creation-reservoir') ?><br>
            <select name="master_article">
              <option value="0">—</option>
              <?php foreach ($article->master_articles as $groupe => $articles): ?>
                <optgroup label="<?= esc_attr($groupe) ?>">
                  <?php foreach ($articles as $article_master): ?>
                    <option value="<?= esc_attr($article_master->Id) ?>" <?= $article->IdArticleMaster == $article_master->Id ? 'selected' : '' ?>>
                      <?= esc_html($article_master->Article) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      <?php endif; ?>

      <div class="ispag-field">
        <label>
          <?= __('Quantity', 'creation-reservoir') ?><br>
          <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>" step="any">
        </label>
      </div>
      <?php if ($can_view_prices): ?>
      <div class="ispag-field">
        <label>
          <?= __('Gross unit price', 'creation-reservoir') ?><br>
          <input type="text" name="sales_price" value="<?= esc_attr($article->sales_price) ?>"> €
        </label>
      </div>
      <div class="ispag-field">
        <label>
          <?= __('Discount', 'creation-reservoir') ?><br>
          <input type="text" name="discount" value="<?= esc_attr($article->discount) ?>"> %
        </label>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($user_can): ?>
    <div class="ispag-modal-status">
      <label><input type="checkbox" name="DemandeAchatOk" <?= $article->DemandeAchatOk ? 'checked' : '' ?>> <?= __('Purchase requested', 'creation-reservoir') ?></label><br>
      <label><input type="checkbox" name="DrawingApproved" <?= ((int)$article->DrawingApproved === 1) ? 'checked' : '' ?>> <?= __('Drawing approved', 'creation-reservoir') ?></label><br>
      <label><input type="checkbox" name="Livre" <?= $article->Livre ? 'checked' : '' ?>> <?= __('Delivered', 'creation-reservoir') ?></label><br>
      <label><input type="checkbox" name="invoiced" <?= $article->invoiced ? 'checked' : '' ?>> <?= __('Invoiced', 'creation-reservoir') ?></label><br>
    </div>
  <?php endif; ?>

  <div class="ispag-modal-actions">
    <button type="submit" class="ispag-btn ispag-btn-red-outlined"><span class="dashicons dashicons-media-archive"></span> <?= __('Save', 'creation-reservoir') ?></button>
    <button type="button" class="ispag-btn ispag-btn-secondary-outlined" onclick="closeIspagModal()"><?= __('Cancel', 'creation-reservoir') ?></button>
  </div>
</form> 
