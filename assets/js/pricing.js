jQuery(document).ready(function($) {
    const deal_id = getUrlParam('deal_id');
    // $(document).on('change', '#ispag-coef-select', function() {
    $('#ispag-bloc-stat-projet').on('change', '#ispag-coef-select', function() {
        const selectedKey = $(this).val();
        
        

        $.post(ajaxurl, {
            action: 'ispag_change_sales_coef',
            coef_key: selectedKey,
            deal_id: deal_id
        }, function(response) {
            if(response.success) {
//                console.log("Nouveau coef :", response.data);
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
    var deal_id = getUrlParam('deal_id');
    jQuery.get(ajaxurl, {
        action: 'ispag_get_sales_coef_notice',
        deal_id: deal_id
    }, function(html) {
        jQuery('#ispag-coef-notice').html(html);
    });
}