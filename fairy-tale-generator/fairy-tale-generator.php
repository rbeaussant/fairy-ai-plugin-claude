<?php
/**
 * Plugin Name: Générateur de Contes de Fées
 * Description: Permet aux utilisateurs de générer des contes de fées personnalisés avec OpenAI
 * Version: 1.0
 * Author: Votre Nom
 */

 if (!defined('ABSPATH')) exit; // Sécurité

// Configuration OpenAI via la constante ou option WordPress
define('OPENAI_API_KEY', get_option('openai_api_key'));

// Initialisation du plugin
function fairy_tale_init() {

    // Création de la page "contes-auteur"
    if (!get_page_by_path('contes-auteur')) {
        wp_insert_post(array(
            'post_title' => 'Mes Contes',
            'post_name' => 'contes-auteur',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[liste_contes_auteur]'
        ));
    }
}
add_action('init', 'fairy_tale_init');

// Ajout des scripts et styles
function fairy_tale_enqueue_scripts() {
    wp_enqueue_style('fairy-tale-style', plugins_url('css/style.css', __FILE__));
    wp_enqueue_script('fairy-tale-script', plugins_url('js/script.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('fairy-tale-script', 'fairyTaleAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fairy_tale_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'fairy_tale_enqueue_scripts');

// Début ajout chat gpt 12 10 25

// === VALIDATION & UTILS ===
// Remplace entièrement ftg_validate_story() par ceci :
function ftg_validate_story($story, $min_words = 120) {
    if (!is_string($story)) return new WP_Error('ftg_story_invalid', 'Le texte est invalide.');
    $plain = trim(wp_strip_all_tags($story));
    if ($plain === '') return new WP_Error('ftg_story_empty', 'Le texte est vide.');

    // Compte de mots (tolère accents)
    $word_count = str_word_count($plain, 0, 'ÀÂÄÇÉÈÊËÎÏÔÖÙÛÜàâäçéèêëîïôöùûüœŒ');
    // On se contente de 80% du minimum : évite de bloquer pour 10–20 mots manquants
    $min_ok = ($word_count >= max(60, (int)floor($min_words * 0.8)));

    // Comptage de phrases grossier : ., !, ?, …
    $sentences = preg_split('/(?<=[\.\!\?\…])\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
    $sentence_count = is_array($sentences) ? count($sentences) : 0;
    $sent_ok = ($sentence_count >= 4); // 3–4 phrases mini pour une “histoire”

    // Heuristique de “clôture” : ponctuation finale + quelques marqueurs communs (mais optionnels)
    $ends_with_punct = (bool)preg_match('/[\.!\?…]"?[\)\]]*$/u', $plain); // finit par . ! ? … (avec guillemet/parenthèse éventuel)
    $closure_markers = [
        'Ainsi', 'Enfin', 'Depuis ce jour', 'Dès lors', 'Et ils vécurent', 'La fin',
        'Tout le monde', 'désormais', 'dorénavant', 'depuis lors'
    ];
    $last_sentence = $sentence_count ? trim(end($sentences)) : '';
    $has_marker = false;
    foreach ($closure_markers as $mk) {
        if (mb_stripos($last_sentence, $mk) !== false) { $has_marker = true; break; }
    }

    // Score souple : 3 critères sur 4 suffisent
    $score = 0;
    $score += $min_ok ? 1 : 0;
    $score += $sent_ok ? 1 : 0;
    $score += $ends_with_punct ? 1 : 0;
    $score += ($has_marker ? 1 : 0);

    if ($score >= 3) return true;

    // Messages plus précis
    if (!$min_ok)      return new WP_Error('ftg_story_too_short', 'Le conte semble inachevé (un peu court).');
    if (!$sent_ok)     return new WP_Error('ftg_story_too_few_sentences', 'Le conte manque de structure (trop peu de phrases).');
    if (!$ends_with_punct) return new WP_Error('ftg_story_no_final_punct', 'Le conte ne semble pas se conclure (ponctuation finale manquante).');
    return new WP_Error('ftg_story_no_closure', 'Le conte ne semble pas se conclure clairement.');
}


/** Télécharge l’image distante et vérifie que c’est bien une image. */
function ftg_download_and_probe_image($image_url) {
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) return new WP_Error('ftg_img_download', 'Téléchargement image impossible: '.$tmp->get_error_message());
    $img_info = @getimagesize($tmp);
    if ($img_info === false) {
        @unlink($tmp);
        return new WP_Error('ftg_img_not_image', 'Le fichier reçu n’est pas une image valide.');
    }
    return $tmp;
}

/** Attache un fichier temporaire image comme miniature du post. */
function ftg_attach_tmp_image_as_thumb($post_id, $tmp_path, $filename = '') {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    if (!$filename) $filename = 'conte-illustration-'.$post_id.'.jpg';
    $file_array = [
        'name' => sanitize_file_name($filename),
        'tmp_name' => $tmp_path
    ];
    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp_path);
        return new WP_Error('ftg_img_attach', 'Import image impossible: '.$attachment_id->get_error_message());
    }
    set_post_thumbnail($post_id, $attachment_id);
    return $attachment_id;
}

/** Table simple durée → cible mots */
function words_target_for_duration($duration){
    $map = [
        '1 minute'  => 150,
        '3 minutes' => 450,
        '5 minutes' => 700,
    ];
    return $map[$duration] ?? 450;
}


// À coller à côté des helpers :
function ftg_repair_story_closure($title, $story, $age, $theme, $target_words){
    $repair_prompt = "Tu es un éditeur francophone de contes pour enfants.
Corrige et complète la fin de ce conte pour qu'il se conclue clairement, avec une morale douce adaptée à {$age} ans.
Ne change pas l'intrigue ni le style, garde cohérents le temps verbal et les noms.
Rends UNIQUEMENT le texte final complet (PAS de JSON), environ {$target_words} mots au total.

TITRE: {$title}
CONTE:
{$story}";
    $fixed = generate_with_openai($repair_prompt, max(600, (int)($target_words * 2)));
    if (is_wp_error($fixed)) return $fixed;
    $fixed = trim($fixed);
    // Par sécurité : enlève les éventuels marqueurs/explications
    $fixed = preg_replace('/^\s*(Titre|TITRE)\s*:\s*/u', '', $fixed);
    return $fixed;
}



 // fin ajout chat gpt 12 10 25

	/**
 * Compte le nombre de "conte" (CPT) créés par l'utilisateur actuel ce mois-ci.
 *
 * @return int Le nombre de "conte" créés par l'utilisateur actuel.
 */
function count_user_conte_this_month() {
    // Récupérer l'utilisateur actuel
    $current_user_id = get_current_user_id();



    // Définir les dates de début et de fin du mois actuel
    $start_of_month = date('Y-m-01 00:00:00');
    $end_of_month = date('Y-m-t 23:59:59');

    // Construire la requête WP_Query
    $query_args = [
        'post_type'      => 'conte-ai', // Nom du CPT
        'post_status'    => 'publish', // Status de publication
        'author'         => $current_user_id, // Filtrer par auteur actuel
        'date_query'     => [
            [
                'after'     => $start_of_month,
                'before'    => $end_of_month,
                'inclusive' => true,
            ],
        ],
        'fields'         => 'ids', // Récupérer uniquement les IDs pour des performances accrues
        'no_found_rows'  => true, // Optimisation : ne pas compter les rangées totales
    ];

    $query = new WP_Query($query_args);

    // Retourner le nombre de résultats trouvés
    return $query->post_count;
}

// Exemple d'utilisation :
// add_shortcode('count_conte', function() {
//    return count_user_conte_this_month();
//});


// compter les contes des utilisateurs 
function get_user_fairy_tale_count($user_id) {
    $current_month = date('Y-m');
    $count = get_user_meta($user_id, "fairy_tales_created_$current_month", true);
    return $count ? intval($count) : 0;
}

function increment_user_fairy_tale_count($user_id) {
    $current_month = date('Y-m');
    $count = get_user_fairy_tale_count($user_id);
    update_user_meta($user_id, "fairy_tales_created_$current_month", $count + 1);
}

function can_user_create_fairy_tale($user_id) {
    $user = get_userdata($user_id);
    $role = $user ? $user->roles[0] : 'visitor'; // Par défaut, un visiteur déconnecté

    $max_count = 0;
    if ($role === 'administrator') {
        return true; // Aucun limite pour les administrateurs
    } elseif ($role === 'subscriber') {
        $max_count = 10; // Limite pour les abonnés
    } elseif ($role === 'visitors') {
        $max_count = 3; // Limite pour les visiteurs
    } elseif ($role === 'visitor') {
        $max_count = 0; // Limite pour les visiteurs
    }

    return get_user_fairy_tale_count($user_id) < $max_count;
}


// Formulaire de création de conte
function fairy_tale_form() {

    ob_start();

// début du formulaire
	
    ?>
    <form id="fairy-tale-form" class="fairy-tale-generator">
        <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/enfants.png'; ?>">
            <label for="age">Âge cible :</label>
            <select name="age" id="age" required>
			    <option value="random">Aléatoire</option>
				<option value="1-2">1-2 ans</option>
                <option value="3-5">3-5 ans</option>
                <option value="6-8">6-8 ans</option>
                <option value="9-12">9-12 ans</option>
            </select>
        </div>
		
        <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/duree.png'; ?>">
            <label for="duration">Durée de lecture :</label>
            <select name="duration" id="duration" required>
				<option value="1">1 minute</option>
				<option value="3">3 minutes</option>
                <option value="5">5 minutes</option>
            </select>
        </div>
		
		<!-- Liste des personnages typiques des contes de fées -->

        <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/dragon.png'; ?>">
            <label for="character">Personnage principal :</label>
            <select name="character" id="character" required>
			    <option value="random">Aléatoire</option>
                <option value="humain">Humain</option>
                <option value="animal">Animal</option>
                <option value="creature">Créature fantastique</option>
				<option value="prince">Prince</option>
				<option value="princesse">Princesse</option>
				<option value="sorcier">Magicien</option>
				<option value="fée">Fée</option>
				<option value="géant">Géant</option>
				<option value="dragon">Dragon</option>
				<option value="paysan">Paysan</option>
				<option value="loup">Loup</option>
				<option value="ogre">Ogre</option>
				<option value="roi">Roi</option>
				<option value="reine">Reine</option>
				<option value="voyageur">Voyageur</option>
            </select>
        </div>
		
        <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/ile.png'; ?>">
			<label for="lieu">Lieu :</label>
			<select name="lieu" id="lieu" required>
			    <option value="random">Aléatoire</option>
				<option value="chateau">Château enchanté</option>
				<option value="foret">Forêt mystérieuse</option>
				<option value="village">Petit village</option>
				<option value="grotte">Grotte secrète</option>
				<option value="montagne">Montagne escarpée</option>
				<option value="mer">Mer infinie</option>
				<option value="ile">Île magique</option>
				<option value="jardin">Jardin ensorcelé</option>
				<option value="tour">Tour isolée</option>
				<option value="marais">Marais lugubre</option>
				<option value="caverne">Caverne scintillante</option>
				<option value="pont">Pont suspendu</option>
			</select>
		</div>
		
        <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/valeurs.png'; ?>">
            <label for="theme">Thématique :</label>
            <select name="theme" id="theme" required>
			    <option value="random">Aléatoire</option>
                <option value="aventure">Aventure</option>
                <option value="amitie">Amitié</option>
                <option value="nature">Nature</option>
                <option value="magie">Magie</option>
				<option value="courage">Courage face à l'adversité</option>
				<option value="bonté">Bonté et générosité</option>
				<option value="justice">Recherche de justice</option>
				<option value="amour">Amour véritable</option>
				<option value="quete">Quête initiatique</option>
				<option value="espoir">Espoir malgré les épreuves</option>
				<option value="transformation">Transformation et métamorphose</option>
				<option value="triomphe">Triomphe du bien sur le mal</option>
				<option value="perseverance">Persévérance dans la quête</option>
				<option value="identite">Recherche de l’identité</option>
				<option value="loyaute">Loyauté et fidélité</option>
				<option value="humilité">Humilité et simplicité</option>
            </select>
        </div>

		
		<div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/epee.png'; ?>"><label for="objet">Objet :</label>
			<select name="objet" id="objet" required>
			    <option value="random">Aléatoire</option>
				<option value="baguette">Baguette magique</option>
				<option value="miroir">Miroir magique</option>
				<option value="tapis">Tapis volant</option>
				<option value="lampe">Lampe magique</option>
				<option value="epee">Épée légendaire</option>
				<option value="cape">Cape d'invisibilité</option>
				<option value="pomme">Pomme empoisonnée</option>
				<option value="cle">Clé dorée</option>
				<option value="haricot">Haricot magique</option>
				<option value="sablier">Sablier magique</option>
				<option value="couronne">Couronne royale</option>
				<option value="grimoire">Grimoire ancien</option>
			</select>
		</div>
		
		 <div class="form-group">
			<img width="64px" src="<?php echo plugin_dir_url( __FILE__ ) . 'images/nom-perso.png'; ?>">
                <label for="hero_name">Nom du héros/de l’héroïne (facultatif) :</label>
    			<input type="text" id="hero_name" name="hero_name" />
        </div>
		
		<?php
		
		    if (!is_user_logged_in()) {
        echo '<br><div>Veuillez vous connecter pour créer un conte.<br>
		Pas encore inscrit-e ? <a href="https://contesdefees.com/register/">Inscrivez-vous pour créer 3 contes gratuitement par mois, ou abonnez-vous pour en créer plus</a>
		<br>Vous pouvez générer des idées.</div>
					<button type="submit">Générer des idées</button>';
			}
			else {
				// Afficher le nombre de contes créés par l'utilisateur actuel ce mois-ci
				$conte_count_deprecated = count_user_conte_this_month();
				$conte_max = 3;
				
				$user = wp_get_current_user();
				$user_id = get_current_user_id();
				$user_data = get_userdata($user_id);
				$conte_count = get_user_fairy_tale_count($user_id);
				$conte_reste = $conte_max-$conte_count;
				
				if ($conte_count >= 10) {
						if ( in_array( 'subscriber'||'premium', (array) $user->roles )) {
						echo '<p>Vous avez déjà créé 10 contes ce mois-ci, <a href="/register">abonnez-vous</a><br>
						Vous pouvez continuer à créer des idées.</p>';
						}
						else {
							echo 'Admin?';
						}
						echo '<button type="submit">Générer des idées</button>';
					}	
				elseif ($conte_count >= 3) {
						if ( in_array( 'visitors'||'en-attente'||'pending', (array) $user->roles ))  {
						echo '<p>Vous avez déja créé ' . $conte_count . ' conte(s) ce mois-ci.</p>
						<p>Il vous reste '.$conte_reste.' conte(s)<br></p>';
						}
						else  {
						echo '<p>Vous avez déja créé ' . $conte_count . ' conte(s) ce mois-ci.</p>
						<p>Il vous reste '.$conte_reste.' conte(s)</p>'; }
						echo '<button type="submit">Générer des idées</button>';
					}	
					elseif ($conte_count > 0) {
						echo '<p>Vous avez déja créé ' . $conte_count . ' conte(s) ce mois-ci.</p>
						<p>Il vous reste '.$conte_reste.' conte(s)</p>
					<button type="submit">Générer des idées</button>';
					} 
					else {
						echo '<p>Vous n\'avez créé aucun conte ce mois-ci.</p>
						<p>Il vous reste 3 contes.</p>
						<button type="submit">Générer des idées</button>';
					}
				
				if ($conte_reste === 0) {
					echo '<p>Vous pouvez continuer à créer des idées.<br>
					Pour continuer à les transformer en contes entiers, abonnez-vous</p>';
				}
				
			}
		?>

        
    </form>
    <div id="tale-ideas"></div>
	
	<?php
	
    return ob_get_clean();
}
add_shortcode('generateur_conte', 'fairy_tale_form');

function get_random_or_selected($selected, $options) {
    return ($selected === 'Aléatoire') ? $options[array_rand($options)] : $selected;
}

// Traitement AJAX pour les idées de contes
function generate_fairy_tale_ideas() {
	check_ajax_referer('fairy_tale_nonce', 'nonce');
	
	$conte_count = count_user_conte_this_month();
	$user = wp_get_current_user();

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fairy_tale_nonce')) {
        wp_send_json_error(['message' => 'Nonce invalide']);
        wp_die();
    }
	




    // Récupérer les données du formulaire
    $age = sanitize_text_field($_POST['age'] ?? '');
    $theme = sanitize_text_field($_POST['theme'] ?? '');
    $duration = sanitize_text_field($_POST['duration'] ?? '');
    $hero_name = sanitize_text_field($_POST['hero_name'] ?? '');
    $character = sanitize_text_field($_POST['character'] ?? '');
    $lieu = sanitize_text_field($_POST['lieu'] ?? '');
    $objet = sanitize_text_field($_POST['objet'] ?? '');

    if (empty($age) || empty($theme) || empty($duration)) {
        wp_send_json_error(['message' => 'Veuillez remplir les champs requis.']);
    }

    // Définir les options pour les valeurs aléatoires
$age_options = ['1-2 ans', '3-5 ans', '6-8 ans', '9-12 ans'];


$character_options = [
    'Humain', 'Animal', 'Créature fantastique', 'Prince',
    'Princesse', 'Sorcier', 'Fée',
    'Géant', 'Dragon', 'Paysan',
    'Loup', 'Ogre', 'Roi', 'Reine',
    'Voyageur'
];

$lieu_options = [
    'Château enchanté', 'Forêt mystérieuse', 'Petit village', 'Grotte secrète',
    'Montagne escarpée', 'Mer infinie', 'Île magique', 'Jardin ensorcelé',
    'Tour isolée', 'Marais lugubre', 'Caverne scintillante', 'Pont suspendu'
];

$theme_options = [
    'Aventure', 'Amitié', 'Nature', 'Magie', 'Courage face à l’adversité',
    'Bonté et générosité', 'Recherche de justice', 'Amour véritable',
    'Quête initiatique', 'Espoir malgré les épreuves', 'Transformation et métamorphose',
    'Triomphe du bien sur le mal', 'Persévérance dans la quête', 'Recherche de l’identité',
    'Loyauté et fidélité', 'Humilité et simplicité'
];

$objet_options = [
    'Baguette magique', 'Miroir magique', 'Tapis volant', 'Lampe magique',
    'Épée légendaire', 'Cape d’invisibilité', 'Pomme empoisonnée', 'Clé dorée',
    'Haricot magique', 'Sablier magique', 'Couronne royale', 'Grimoire ancien'
];


    // Obtenir les valeurs générées ou sélectionnées
    $age = get_random_or_selected($age, $age_options);
    $character = get_random_or_selected($character, $character_options);
    $lieu = get_random_or_selected($lieu, $lieu_options);
    $theme = get_random_or_selected($theme, $theme_options);
    $objet = get_random_or_selected($objet, $objet_options);


    $prompt = "Génère trois idées de contes de fées pour des enfants de {$age} ans, sur le thème de {$theme}, d'une durée de lecture de {$duration} minutes, avec comme personnage principal {$hero_name} un {$character}, dans un lieu de type: {$lieu}. Cet objet est utilisé: {$objet}. Chaque idée doit comporter maximum 55 mots. Sépare les idées uniquement par une barre |";

    $ideas = generate_with_openai($prompt, 250);

    if (is_wp_error($ideas)) {
        wp_send_json_error(['message' => $ideas->get_error_message()]);
        wp_die();
    }

    $idea_list = explode("|", trim($ideas));

    wp_send_json_success([
		'ideas' => $idea_list,
    	'generated_values' => [
			'age' => $age,
			'duration' => $duration,
			'hero_name' => $hero_name,
			'character' => $character,
			'theme' => $theme,
			'lieu' => $lieu,
			'objet' => $objet
		],
	    'can_create_story' => is_user_logged_in() && can_user_create_fairy_tale(get_current_user_id())
	]);
}
add_action('wp_ajax_generate_fairy_tale_ideas', 'generate_fairy_tale_ideas');
add_action('wp_ajax_nopriv_generate_fairy_tale_ideas', 'generate_fairy_tale_ideas');




// Traitement AJAX pour créer un conte entier

function create_fairy_tale_from_idea() {
    check_ajax_referer('fairy_tale_nonce', 'nonce');

    $user_id = get_current_user_id();
    if (!$user_id || !can_user_create_fairy_tale($user_id)) {
        wp_send_json_error(['message' => 'Vous avez atteint votre limite de créations de contes pour ce mois-ci.']);
    }

    // Données
    $idea      = sanitize_text_field($_POST['idea'] ?? '');
    $age       = sanitize_text_field($_POST['age'] ?? '');
    $theme     = sanitize_text_field($_POST['theme'] ?? '');
    $duration  = sanitize_text_field($_POST['duration'] ?? '');
    $hero_name = sanitize_text_field($_POST['hero_name'] ?? '');
    $character = sanitize_text_field($_POST['character'] ?? '');
    $lieu      = sanitize_text_field($_POST['lieu'] ?? '');
    $objet     = sanitize_text_field($_POST['objet'] ?? '');

    if (empty($idea) || empty($age) || empty($duration)) {
        wp_send_json_error(['message' => 'Les informations nécessaires sont incomplètes.']);
    }

    $target_words = words_target_for_duration($duration);

    // === 1) Génère JSON stricte (title, story, illustration_prompt)
    $gen_prompt = "Tu es un auteur de contes pour enfants francophone.
Réponds uniquement en JSON valide (pas de texte hors JSON), avec les clés EXACTES:
{\"title\":\"string\",\"story\":\"string\",\"illustration_prompt\":\"string\"}

Contraintes:
- Public: {$age} ans.
- Thème: {$theme}. Personnage: {$hero_name} {$character}. Lieu: {$lieu}. Objet: {$objet}.
- Longueur ≈ {$target_words} mots.
- Temps de narration cohérent tout du long (éviter le passé composé en narration).
- Fin explicite et rassurante pour l’enfant (morale douce).
- Pas de contenu anxiogène.

Base-toi sur l’idée ci-dessous (ne la recopie pas mot à mot) et écris un conte complet et fluide:
IDEE: \"{$idea}\"

L’illustration doit être décrite de façon neutre et sécure pour enfants : gravure XIXe / encre + aquarelle légère, lisible, plan moyen sur le héros, éléments clés cohérents (tenue/couleurs), ambiance douce.";
    $raw_json = generate_with_openai($gen_prompt, 1200);
    if (is_wp_error($raw_json)) {
        wp_send_json_error(['message' => $raw_json->get_error_message()]);
    }

    // Parsing JSON (tolérant)
    $data = json_decode($raw_json, true);
    if (!is_array($data)) {
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw_json, $m)) {
            $data = json_decode($m[0], true);
        }
    }
    if (!is_array($data) || empty($data['title']) || empty($data['story']) || empty($data['illustration_prompt'])) {
        wp_send_json_error(['message' => 'Réponse IA invalide (JSON attendu).']);
    }

	// === 2) Valider le texte + réparations automatiques au besoin
	$target_words = words_target_for_duration($duration);

	// 1ère validation
	$valid_story = ftg_validate_story($data['story'], max(80, (int)($target_words * 0.6)));
	if (is_wp_error($valid_story)) {
		// 1ère tentative de réparation
		$fixed = ftg_repair_story_closure($data['title'], $data['story'], $age, $theme, $target_words);
		if (!is_wp_error($fixed)) {
			$data['story'] = $fixed;
			$valid_story = ftg_validate_story($data['story'], max(80, (int)($target_words * 0.6)));
		}
	}

	// 2ème tentative si nécessaire
	if (is_wp_error($valid_story)) {
		$fixed2 = ftg_repair_story_closure($data['title'], $data['story'], $age, $theme, $target_words);
		if (!is_wp_error($fixed2)) {
			$data['story'] = $fixed2;
			$valid_story = ftg_validate_story($data['story'], max(80, (int)($target_words * 0.6)));
		}
	}

	if (is_wp_error($valid_story)) {
		wp_send_json_error(['message' => $valid_story->get_error_message()]);
	}


    // === 3) Générer l’image + vérifier le fichier
    $image_url = generate_illustration_with_openai($data['illustration_prompt']);
    if (is_wp_error($image_url)) {
        wp_send_json_error(['message' => $image_url->get_error_message()]);
    }
    $tmp_img = ftg_download_and_probe_image($image_url);
    if (is_wp_error($tmp_img)) {
        wp_send_json_error(['message' => $tmp_img->get_error_message()]);
    }

    // === 4) Seulement maintenant, créer le post
    $post_data = [
        'post_title'   => wp_strip_all_tags($data['title']),
        'post_content' => wp_kses_post($data['story']),
        'post_status'  => 'publish',
        'post_author'  => $user_id,
        'post_type'    => 'conte-ai'
    ];
    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id) || !$post_id) {
        @unlink($tmp_img);
        wp_send_json_error(['message' => 'Erreur d’insertion du conte.']);
    }

    // === 5) Attacher l’image (si échec, on supprime le post pour rester atomique)
    $att_id = ftg_attach_tmp_image_as_thumb($post_id, $tmp_img, 'conte-'.$post_id.'.jpg');
    if (is_wp_error($att_id)) {
        wp_delete_post($post_id, true);
        wp_send_json_error(['message' => $att_id->get_error_message()]);
    }

    // === 6) Taxonomies + metas
    $taxonomies_terms = [
        'age'        => [$age],
        'duree'      => [$duration],
        'personnage' => [$character],
        'lieu'       => [$lieu],
        'thematique' => [$theme],
        'objet'      => [$objet],
    ];
    foreach ($taxonomies_terms as $taxonomy => $terms) {
        wp_set_object_terms($post_id, $terms, $taxonomy);
    }
    update_post_meta($post_id, '_illustration_prompt', wp_strip_all_tags($data['illustration_prompt']));
    update_post_meta($post_id, '_ftg_status', 'ready');

    // === 7) Incrément quota UNIQUEMENT ici (succès complet)
    increment_user_fairy_tale_count($user_id);

    // === 8) OK
    wp_send_json_success(['message' => 'Conte créé avec succès', 'redirect' => get_permalink($post_id)]);
}

add_action('wp_ajax_create_fairy_tale_from_idea', 'create_fairy_tale_from_idea');

// Fonction générique pour appeler OpenAI
function generate_with_openai($prompt, $max_tokens) {
    $api_key = OPENAI_API_KEY;
	error_log('Prompt envoyé à OpenAI : ' . $prompt);


    if (empty($api_key)) {
        return new WP_Error('openai_error', 'La clé API OpenAI est manquante');
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
		// Dans generate_with_openai($prompt, $max_tokens)
		'body' => json_encode(array(
			'model' => 'gpt-4o',
			'messages' => [
				['role' => 'system', 'content' => 'Tu écris en français, style adapté aux enfants. Réponds de façon concise et structurée.'],
				['role' => 'user', 'content' => $prompt]
			],
			'max_tokens' => max(256, (int)$max_tokens), // utilise vraiment le paramètre
			'temperature' => 0.7,
		)),
    'timeout' => 50,  // Augmenter le délai d'attente à 30 secondes
    ));

    if (is_wp_error($response)) {
        return $response;
    }
	error_log('Réponse brute de l\'API : ' . print_r($response, true));


    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['choices'][0]['message']['content'])) {
        return $data['choices'][0]['message']['content'];
    } else {
        return new WP_Error('openai_error', 'Réponse invalide de l\'API OpenAI');
    }
}

// Fonction pour générer l'illustration avec OpenAI
function generate_illustration_with_openai($prompt_image) {

    $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
				'model' => 'dall-e-3',
                'prompt' => $prompt_image,
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'url'
        )),
    'timeout' => 50,  // Augmenter le délai d'attente à 30 secondes
    ));

    if (is_wp_error($response)) {
        return $response;
    }
	error_log('Réponse brute de l\'API : ' . print_r($response, true));


    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['data'][0]['url'])) {
        return $data['data'][0]['url'];
    } else {
        return new WP_Error('openai_error', 'Réponse invalide de l\'API OpenAI');
		wp_send_json_error(['message' => 'Erreur lors de la création de l\image']);
    }
}

// Fonction pour sauvegarder l'illustration
function save_illustration($post_id, $image_url) {
    try {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Téléchargement de l'image
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            throw new Exception('Erreur lors du téléchargement de l\'image: ' . $tmp->get_error_message());
        }

        $file_array = array(
            'name' => 'conte-illustration-' . $post_id . '.jpg',
            'tmp_name' => $tmp
        );

        // Insertion dans la bibliothèque de médias
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception('Erreur lors de l\'importation de l\'image: ' . $attachment_id->get_error_message());
        }

        // Définition de l'image à la une
        set_post_thumbnail($post_id, $attachment_id);

        return true;

    } catch (Exception $e) {
        fairy_tale_log('Erreur lors de la sauvegarde de l\'illustration', $e->getMessage());
        return false;
    }
}

// Shortcode pour afficher la liste des contes de l'utilisateur
function display_user_tales() {
    if (!is_user_logged_in()) {
        return 'Veuillez vous connecter pour voir vos contes.';
    }

    $args = array(
        'post_type' => 'conte-ai',
        'author' => get_current_user_id(),
        'post_status' => array('draft', 'private', 'publish'),
        'posts_per_page' => -1
    );

    $query = new WP_Query($args);

    ob_start();
    ?>
    <div class="user-tales">
        <h2>Mes Contes</h2>
        <?php if ($query->have_posts()) : ?>
            <div class="tales-grid">
                <?php while ($query->have_posts()) : $query->the_post(); ?>
                    <div class="tale-card">
                        <?php if (has_post_thumbnail()) : ?>
                            <?php the_post_thumbnail('medium'); ?>
                        <?php endif; ?>
                        <h3><?php the_title(); ?></h3>
                        <p class="status">Statut : <?php echo get_post_status() === 'private' ? 'En attente de validation' : 'Publié'; ?></p>
                        <a href="<?php the_permalink(); ?>" class="read-tale">Lire le conte</a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else : ?>
            <p>Vous n'avez pas encore créé de contes.</p>
        <?php endif; ?>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('liste_contes_auteur', 'display_user_tales');

// Ajout du menu d'administration
function fairy_tale_admin_menu() {
    add_menu_page(
        'Gestion des Contes',
        'Contes à Valider',
        'edit_posts',
        'fairy-tale-management',
        'fairy_tale_admin_page',
        'dashicons-book-alt'
    );
}
add_action('admin_menu', 'fairy_tale_admin_menu');

// Page d'administration
function fairy_tale_admin_page() {

    // Liste les contes à valider (private + draft)
    $args = array(
        'post_type'      => 'conte-ai',
        'post_status'    => array('private','draft'),
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    $query = new WP_Query($args);

    // Crée le nonce une fois ici
    $nonce = wp_create_nonce('fairy_tale_nonce');
    ?>
    <div class="wrap">
        <h1>Contes à Valider</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Titre</th>
                    <th>Auteur</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($query->have_posts()) : ?>
                    <?php while ($query->have_posts()) : $query->the_post(); ?>
                        <tr>
                            <td><?php echo esc_html(get_the_title()); ?></td>
                            <td><?php echo esc_html(get_the_author()); ?></td>
                            <td><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_permalink()); ?>" target="_blank" rel="noopener">Prévisualiser</a> |
                                <a href="#" class="publish-tale"
                                   data-id="<?php echo (int) get_the_ID(); ?>"
                                   data-nonce="<?php echo esc_attr($nonce); ?>">
                                   Publier
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="4">Aucun conte à valider</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    wp_reset_postdata();
}


// Action AJAX pour publier un conte
function publish_fairy_tale() {
    check_ajax_referer('fairy_tale_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission refusée');
    }

    $post_id = intval($_POST['post_id']);
    $post = array(
        'ID' => $post_id,
        'post_status' => 'publish'
    );

    $updated = wp_update_post($post);

    if ($updated) {
        wp_send_json_success('Conte publié avec succès');
    } else {
        wp_send_json_error('Erreur lors de la publication');
    }
}
add_action('wp_ajax_publish_fairy_tale', 'publish_fairy_tale');