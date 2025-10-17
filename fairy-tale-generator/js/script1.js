jQuery(document).ready(function($) {
    // Gestion du formulaire de création de conte
    $('#fairy-tale-form').on('submit', function(e) {
        e.preventDefault();
        console.log(fairyTaleAjax);
        const $form = $(this);
        const $submit = $form.find('button[type="submit"]');
        const $result = $('#tale-result');
        
        // Récupérer les données du formulaire
        const formData = {
            action: 'generate_fairy_tale',
            nonce: fairyTaleAjax.nonce,
            age: $('#age').val(),
            theme: $('#theme').val(),
            duration: $('#duration').val(),
            character: $('#character').val()
        };

        // Validation des champs
        if (!formData.age || !formData.theme || !formData.duration || !formData.character) {
            $result.html('<div class="tale-message error">Tous les champs sont obligatoires.</div>');
            return;
        }

        // Afficher le loader
        $submit.prop('disabled', true).text('Génération en cours...');
        $result.html('<div class="tale-loader"></div>');
        
        // Appel AJAX
        $.ajax({
            url: fairyTaleAjax.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="tale-message success">Votre conte a été généré avec succès ! <a href="' + response.data.redirect + '">Voir le conte</a></div>');
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 2000);
                } else {
                    const errorMessage = response.data && response.data.message 
                        ? response.data.message 
                        : 'Une erreur est survenue lors de la génération du conte.';
                    $result.html('<div class="tale-message error">' + errorMessage + '</div>');
                }
            },
            error: function(jqXHR) {
                const errorDetails = jqXHR.responseJSON && jqXHR.responseJSON.message 
                    ? jqXHR.responseJSON.message 
                    : 'Erreur de communication avec le serveur.';
                $result.html('<div class="tale-message error">' + errorDetails + '</div>');
            },
            complete: function() {
                $submit.prop('disabled', false).text('Générer le conte');
            }
        });
    });
    
    // Gestion de la publication des contes (interface admin)
    $('.publish-tale').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const postId = $button.data('id');
        
        if (confirm('Êtes-vous sûr de vouloir publier ce conte ?')) {
            $button.text('Publication en cours...');
            
            $.ajax({
                url: fairyTaleAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'publish_fairy_tale',
                    nonce: fairyTaleAjax.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        $('#tale-' + postId).addClass('published'); // Exemple de mise à jour
                        $button.text('Publié');
                    } else {
                        alert('Erreur lors de la publication du conte.');
                        $button.text('Publier');
                    }
                },
                error: function() {
                    alert('Erreur de communication avec le serveur.');
                    $button.text('Publier');
                }
            });
        }
    });
});
