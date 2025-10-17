jQuery(document).ready(function ($) {

  // === Store mémoire des idées ===
  let FTG_IDEA_STORE = [];

  // === Utils ===
  function esc(s){ return $('<div>').text(String(s || '')).html(); }

  // === Overlay avec progression (%) et barre ===
  let FTG_PROGRESS = { timer:null, percent:0, target:0 };

  function ftgShowOverlay(message, initialPercent = 0) {
    if ($('#ftg-overlay').length) { $('#ftg-overlay').remove(); }
    const overlay = $(`
      <div id="ftg-overlay" aria-live="polite" style="
        position:fixed; inset:0; background:rgba(0,0,0,0.8);
        display:flex; align-items:center; justify-content:center; z-index:99999;
        color:#fff; font-size:18px; line-height:1.5; text-align:center;">
        <div style="width:min(520px,90vw);">
          <div class="ftg-title" style="font-size:20px; margin-bottom:8px;">Création du conte</div>
          <div class="ftg-msg" style="margin-bottom:12px;">${message || 'Démarrage...'}</div>
          <div style="height:10px; background:rgba(255,255,255,0.2); border-radius:6px; overflow:hidden; margin:10px 0;">
            <div class="ftg-bar" style="height:100%; width:0%; background:#ffd24d;"></div>
          </div>
          <div class="ftg-percent" style="font-family:monospace;">0%</div>
          <div class="ftg-sub" style="opacity:0.8; font-size:14px; margin-top:8px;">
            Merci de patienter, cela peut prendre ~1 minute.
          </div>
          <div style="margin-top:12px;">
            <img src="/wp-content/plugins/fairy-tale-generator/images/escribir.gif" alt="Chargement..." style="max-width:100%; height:auto;">
          </div>
        </div>
      </div>
    `);
    $('body').append(overlay);

    FTG_PROGRESS.percent = Math.max(0, Math.min(99, initialPercent));
    FTG_PROGRESS.target  = FTG_PROGRESS.percent;
    ftgRenderProgress();
    ftgStartProgressTick();

    // Accessibilité : annoncer et focus
    $('#ftg-overlay .ftg-msg').attr('tabindex','-1').focus();
  }

  function ftgUpdateOverlay(message) {
    $('#ftg-overlay .ftg-msg').html(message);
  }

  function ftgSetStage(targetPercent, label) {
    FTG_PROGRESS.target = Math.max(FTG_PROGRESS.percent, Math.min(99, targetPercent));
    ftgUpdateOverlay(label);
  }

  function ftgStartProgressTick() {
    if (FTG_PROGRESS.timer) clearInterval(FTG_PROGRESS.timer);
    FTG_PROGRESS.timer = setInterval(() => {
      const delta = (FTG_PROGRESS.target - FTG_PROGRESS.percent);
      if (Math.abs(delta) < 0.2) return;
      FTG_PROGRESS.percent += Math.max(0.2, delta * 0.08);
      FTG_PROGRESS.percent = Math.min(99, FTG_PROGRESS.percent);
      ftgRenderProgress();
    }, 120);
  }

  function ftgRenderProgress() {
    const p = Math.round(FTG_PROGRESS.percent);
    $('#ftg-overlay .ftg-bar').css('width', p + '%');
    $('#ftg-overlay .ftg-percent').text(p + '%');
  }

  function ftgCompleteOverlay(doneMessage) {
    if (FTG_PROGRESS.timer) clearInterval(FTG_PROGRESS.timer);
    FTG_PROGRESS.target = 100;
    FTG_PROGRESS.percent = 100;
    ftgRenderProgress();
    ftgUpdateOverlay(doneMessage || 'Terminé ✅');
  }

  function ftgHideOverlay() {
    if (FTG_PROGRESS.timer) clearInterval(FTG_PROGRESS.timer);
    $('#ftg-overlay').fadeOut(200, function(){ $(this).remove(); });
  }

  // === Soumission du formulaire : génération des idées ===
  $('#fairy-tale-form').on('submit', function (e) {
    e.preventDefault();

    const payload = {
      action: 'generate_fairy_tale_ideas',
      nonce: fairyTaleAjax.nonce,
      age: $('#age option:selected').text(),
      theme: $('#theme option:selected').text(),
      duration: $('#duration option:selected').text(),
      hero_name: $('#hero_name').val(),
      character: $('#character option:selected').text(),
      lieu: $('#lieu option:selected').text(),
      objet: $('#objet option:selected').text()
    };

    $('#tale-ideas').html('<p>Génération des idées en cours...</p>');

    $.ajax({
      url: fairyTaleAjax.ajaxurl,
      type: 'POST',
      data: payload,
      dataType: 'json',
      timeout: 60000 // 60s
    }).done(function(response){
      // Cas WordPress: 0/−1 => nonce/permissions expirés
      if (response === 0 || response === -1) {
        $('#tale-ideas').html('<p>' + esc('Session expirée (nonce). Rechargez la page puis réessayez.') + '</p>');
        return;
      }

      if (!response || !response.success) {
        var msg = (response && response.data && response.data.message) ? response.data.message : 'Erreur lors de la génération des idées';
        $('#tale-ideas').html('<p>' + esc(msg) + '</p>');
        return;
      }

      FTG_IDEA_STORE = Array.isArray(response.data.ideas) ? response.data.ideas : [];
      const $list = $('<ul/>');

      FTG_IDEA_STORE.forEach((idea, index) => {
        const $li = $('<li/>');
        $('<p/>').text(idea).appendTo($li);
        const $btn = $('<button/>', {
          class: 'generate-story',
          'data-index': index,
          disabled: !response.data.can_create_story,
          text: 'Créer le conte'
        }).data('meta', {
          age: response.data.generated_values.age,
          duration: response.data.generated_values.duration,
          hero_name: response.data.generated_values.hero_name,
          character: response.data.generated_values.character,
          theme: response.data.generated_values.theme,
          lieu: response.data.generated_values.lieu,
          objet: response.data.generated_values.objet
        });
        $li.append($btn);
        $list.append($li);
      });

      $('#tale-ideas').empty()
        .append('<h3>Idées de contes :</h3>')
        .append($list);

    }).fail(function(jqXHR, textStatus){
      const msg = (textStatus === 'timeout')
        ? '⏳ Le serveur met trop de temps à répondre. Réessayez.'
        : 'Erreur réseau lors de la génération des idées. Réessayez.';
      $('#tale-ideas').html('<p>' + esc(msg) + '</p>');
    });
  });

  // === Création du conte complet depuis une idée ===
  $(document).on('click', '.generate-story', function () {
    const $btn = $(this);
    if ($btn.prop('disabled')) return; // garde-fou double clic

    const index = Number($btn.attr('data-index') || -1);
    const idea = FTG_IDEA_STORE[index];
    if (!idea) { alert("Idée introuvable. Veuillez régénérer la liste."); return; }

    const meta = $btn.data('meta') || {};
    const payload = {
      action: 'create_fairy_tale_from_idea',
      nonce: fairyTaleAjax.nonce,
      idea: idea,
      age: meta.age,
      theme: meta.theme,
      duration: meta.duration,
      hero_name: meta.hero_name,
      character: meta.character,
      lieu: meta.lieu,
      objet: meta.objet
    };

    $btn.prop('disabled', true).text('Création en cours...');

    // Overlay + Étapes semi-réalistes
    ftgShowOverlay('✍️ Étape 1 sur 4 : génération du texte…<br><small>(20–40 secondes)</small>', 2);
    ftgSetStage(40, '✍️ Étape 1 sur 4 : génération du texte…<br><small>(20–40 secondes)</small>');
    const stage2 = setTimeout(() => ftgSetStage(65, '🔍 Étape 2 sur 4 : vérification et mise en forme du conte…'), 20000);
    const stage3 = setTimeout(() => ftgSetStage(90, '🎨 Étape 3 sur 4 : création de l’illustration…'), 40000);
    const stage4 = setTimeout(() => ftgSetStage(98, '💾 Étape 4 sur 4 : enregistrement du conte…'), 55000);

    $.ajax({
      url: fairyTaleAjax.ajaxurl,
      type: 'POST',
      data: payload,
      dataType: 'json',
      timeout: 120000 // 120s (génération + image + insertion)
    }).done(function(response){
      clearTimeout(stage2); clearTimeout(stage3); clearTimeout(stage4);

      if (response === 0 || response === -1) {
        ftgUpdateOverlay('❌ Session expirée. Rechargez la page puis réessayez.');
        setTimeout(() => ftgHideOverlay(), 500);
        alert('Session expirée (nonce). Rechargez la page puis réessayez.');
        $btn.prop('disabled', false).text('Créer le conte');
        return;
      }

      if (response && response.success) {
        ftgCompleteOverlay('✨ Votre conte est prêt ! Redirection…');
        setTimeout(() => { window.location.href = response.data.redirect; }, 700);
        return;
      }

      var msg = (response && response.data && response.data.message) ? response.data.message : 'Erreur lors de la création du conte.';
      ftgUpdateOverlay('❌ Une erreur est survenue. Vous pouvez réessayer.');
      setTimeout(() => ftgHideOverlay(), 400);
      alert(msg);
      $btn.prop('disabled', false).text('Créer le conte');

    }).fail(function(jqXHR, textStatus){
      clearTimeout(stage2); clearTimeout(stage3); clearTimeout(stage4);
      ftgUpdateOverlay(textStatus === 'timeout'
        ? '⏳ Le serveur met trop de temps à répondre. Réessayez.'
        : '❌ Problème réseau. Vérifiez votre connexion et réessayez.'
      );
      setTimeout(() => ftgHideOverlay(), 500);
      alert('Erreur réseau (' + textStatus + '). Réessayez.');
      $btn.prop('disabled', false).text('Créer le conte');
    });
  });

});
