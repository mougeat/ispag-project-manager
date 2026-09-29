jQuery(document).ready(function($) {

    
    
    $('#ispag-bloc-stat-projet').on('change', '#ispag-coef-select', function() {
        const selectedKey = $(this).val();
        const deal_id = $(this).data('dealId');
        

        $.post(ajaxurl, {
            action: 'ispag_change_sales_coef',
            coef_key: selectedKey,
            deal_id: deal_id
        }, function(response) {
            if(response.success) {
               console.log("Nouveau coef :", response.data);
                // ici tu peux appeler une autre fonction pour recalculer les prix
                refreshCoefNotice(deal_id);
                reloadArticleList();
            } else {
                alert("Erreur : " + response.data);
            }
        });
    });
});


function refreshCoefNotice(deal_id) {
    // On récupère l'élément
    const noticeElement = jQuery('#ispag-coef-notice');
    
    // On extrait l'ID depuis l'attribut data-deal-id
    const currentDealId = noticeElement.data('deal-id'); 
    
    // console.log('refreshCoefNotice - Deal ID récupéré:', currentDealId);

    if (!currentDealId) {
        console.error("Impossible de trouver le deal_id sur #ispag-coef-notice");
        return;
    }

    jQuery.get(ajaxurl, {
        action: 'ispag_get_sales_coef_notice',
        deal_id: currentDealId
    }, function(html) {
        // console.log("Nouveau warning :", html);
        jQuery('#ispag-coef-notice').html(html);
    });
}