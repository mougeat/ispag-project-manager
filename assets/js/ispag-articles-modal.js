jQuery(document).ready(function($) {
    // Vérifier que ispagArticlesAjax existe
    if (typeof ispagArticlesAjax === 'undefined') {
        console.error('ispagArticlesAjax is not defined. Check wp_localize_script.');
        return;
    }

    // Gestion des filtres
    var $searchInput = $('#ispag-articles-search');
    var $typeFilter = $('#ispag-articles-type-filter');

    if ($searchInput.length) {
        $searchInput.on('keyup', function(e) {
            if (e.key === 'Enter') {
                var search = encodeURIComponent(this.value);
                var typeFilter = $typeFilter.val();
                window.location.href = '?search=' + search + '&type_filter=' + typeFilter;
            }
        });
    }

    if ($typeFilter.length) {
        $typeFilter.on('change', function() {
            var search = encodeURIComponent($searchInput.val());
            var typeFilter = this.value;
            window.location.href = '?search=' + search + '&type_filter=' + typeFilter;
        });
    }

    // Gestion des actions (Edit, Preview, Delete)
    $(document).on('click', '.ispag-edit-btn', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation(); // Empêcher toute propagation

        var articleId = $(this).data('article-id');
        if (articleId) {
            openEditModal(articleId);
        } else {
            console.error('No article ID found');
        }
    });

    $(document).on('click', '.ispag-preview-btn', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var articleId = $(this).data('article-id');
        console.log('Preview article:', articleId);
    });

    $(document).on('click', '.ispag-delete-btn', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        var articleId = $(this).data('article-id');
        var articleRow = $(this).closest('tr');

        if (articleId && confirm(ispagArticlesAjax.confirm_delete)) {
            var $btn = $(this);
            $btn.addClass('ispag-btn-loading').prop('disabled', true);

            $.ajax({
                url: ispagArticlesAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ispag_delete_standard_article',
                    article_id: articleId,
                    nonce: ispagArticlesAjax.nonce
                },
                success: function(response) {
                    if (response && response.success) {
                        articleRow.fadeOut(300, function() {
                            $(this).remove();
                        });
                        alert(response.data.message);
                    } else {
                        alert(response && response.data && response.data.message
                            ? response.data.message
                            : ispagArticlesAjax.error_text);
                    }
                },
                error: function(xhr, status, error) {
                    alert(ispagArticlesAjax.error_text + ' (' + status + ')');
                    console.error("AJAX Error:", status, error, xhr.responseText);
                },
                complete: function() {
                    $btn.removeClass('ispag-btn-loading').prop('disabled', false);
                }
            });
        }
    });

    // Fermer la modale
    // $(document).on('click', '#ispag-modal-product .ispag-modal-close, #ispag-cancel-edit', function(e) {
    //     e.preventDefault();
    //     $('#ispag-modal-product').hide();
    // });

    // Fermer la modale en cliquant à l'extérieur
    // $(document).on('click', function(e) {
    //     if (e.target.id === 'ispag-modal-product') {
    //         $('#ispag-modal-product').hide();
    //     }
    // });

    // Fonction pour ouvrir la modale d'édition
    function openEditModal(articleId) {
        var modal = $('#ispag-modal-product');
        var modalBody = $('#ispag-modal-body');

        if (!modal.length || !modalBody.length) {
            console.error('Modal elements not found');
            return;
        }

        // Afficher le spinner
        modalBody.html('<div style="text-align: center; padding: 20px;"><span class="spinner is-active"></span> ' + ispagArticlesAjax.loading_text + '</div>');
        modal.show();

        // Requête AJAX pour récupérer les données de l'article
        $.ajax({
            url: ispagArticlesAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_get_standard_article_data',
                article_id: articleId,
                nonce: ispagArticlesAjax.get_article_nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success && response.data && response.data.form) {
                    modalBody.html(response.data.form);
                } else {
                    var errorMsg = response && response.data && response.data.message
                        ? response.data.message
                        : ispagArticlesAjax.error_text;
                    modalBody.html('<div style="color: red; padding: 20px;">' + errorMsg + '</div>');
                    console.error("AJAX Error Response:", response);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = ispagArticlesAjax.error_text + ' (' + status + ': ' + error + ')';
                modalBody.html('<div style="color: red; padding: 20px;">' + errorMsg + '</div>');
                console.error("AJAX Error:", status, error, xhr.responseText);
            }
        });
    }

    // // Soumission du formulaire d'édition
    // $(document).on('submit', '#ispag-edit-article-form', function(e) {
    //     e.preventDefault();
    //     e.stopImmediatePropagation();

    //     var form = $(this);
    //     var submitBtn = form.find('button[type="submit"]');

    //     if (!submitBtn.length) {
    //         console.error('Submit button not found');
    //         return;
    //     }

    //     submitBtn.addClass('ispag-btn-loading').prop('disabled', true).html('<span class="spinner is-active"></span> ' + ispagArticlesAjax.saving_text);

    //     $.ajax({
    //         url: ispagArticlesAjax.ajax_url,
    //         type: 'POST',
    //         data: form.serialize(),
    //         dataType: 'json',
    //         success: function(response) {
    //             if (response && response.success) {
    //                 alert(response.data.message);
    //                 location.reload();
    //             } else {
    //                 alert(response && response.data && response.data.message
    //                     ? response.data.message
    //                     : ispagArticlesAjax.error_text);
    //             }
    //         },
    //         error: function(xhr, status, error) {
    //             alert(ispagArticlesAjax.error_text + ' (' + status + ')');
    //             console.error("Form Submission Error:", status, error, xhr.responseText);
    //         },
    //         complete: function() {
    //             submitBtn.removeClass('ispag-btn-loading').prop('disabled', false).html('<span class="dashicons dashicons-media-archive"></span> ' + ispagArticlesAjax.save_text);
    //         }
    //     });
    // });
});