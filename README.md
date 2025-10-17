# Environnement de Développement WordPress - ContesDeFees.com

Configuration Docker complète pour développer et tester le plugin Fairy Tale Generator.

---

## Prérequis

- **Docker Desktop** installé et en cours d'exécution
  - Télécharger : https://www.docker.com/products/docker-desktop/
- **Minimum 4 GB de RAM** alloués à Docker

---

## Installation Rapide

### 1. Démarrer l'environnement

Double-cliquez sur `start.bat` ou exécutez :

```bash
docker-compose up -d
```

Attendez 30 secondes que WordPress se configure.

### 2. Accéder à WordPress

Ouvrez votre navigateur :

- **WordPress** : http://localhost:8080
- **PHPMyAdmin** : http://localhost:8081

### 3. Installer WordPress (première fois uniquement)

1. Allez sur http://localhost:8080
2. Choisissez la langue : **Français**
3. Remplissez les informations du site :
   - Titre : ContesDeFees Local
   - Identifiant : admin (ou votre choix)
   - Mot de passe : (généré automatiquement ou personnalisé)
   - Email : votre-email@exemple.com
4. Cliquez sur **Installer WordPress**

### 4. Activer le plugin

1. Connectez-vous à WordPress Admin : http://localhost:8080/wp-admin
2. Allez dans **Extensions** → **Extensions installées**
3. Trouvez **Fairy Tale Generator** et cliquez sur **Activer**

---

## Configuration du Plugin

### Ajouter votre clé API OpenAI

Le plugin nécessite une clé API OpenAI pour fonctionner.

#### Option 1 : Via PHPMyAdmin (Recommandé)

1. Ouvrez PHPMyAdmin : http://localhost:8081
2. Connectez-vous :
   - Serveur : `db`
   - Utilisateur : `root`
   - Mot de passe : `rootpassword123`
3. Sélectionnez la base `contesdefees_wp`
4. Cliquez sur l'onglet **SQL**
5. Exécutez cette requête (remplacez `VOTRE_CLE_API` par votre vraie clé) :

```sql
INSERT INTO wp_options (option_name, option_value, autoload)
VALUES ('openai_api_key', 'sk-VOTRE_CLE_API_ICI', 'yes')
ON DUPLICATE KEY UPDATE option_value = 'sk-VOTRE_CLE_API_ICI';
```

#### Option 2 : Via un plugin WordPress

1. Installez le plugin **WP Crontrol** ou **Code Snippets**
2. Ajoutez ce code PHP :

```php
update_option('openai_api_key', 'sk-VOTRE_CLE_API_ICI');
```

---

## Utilisation du Plugin

### Créer une page de génération de contes

1. Dans WordPress Admin, allez dans **Pages** → **Ajouter**
2. Titre : "Créateur de Contes"
3. Dans le contenu, ajoutez le shortcode :

```
[generateur_conte]
```

4. Cliquez sur **Publier**
5. Visitez la page pour voir le formulaire

### Créer une page bibliothèque

1. Créez une nouvelle page
2. Titre : "Mes Contes"
3. Ajoutez le shortcode :

```
[liste_contes_auteur]
```

4. Publiez la page

### Tester la génération

1. Allez sur la page "Créateur de Contes"
2. Remplissez tous les champs du formulaire
3. Cliquez pour générer 3 idées
4. Sélectionnez une idée et créez le conte complet
5. Attendez 30-60 secondes (barre de progression)

---

## Structure des Fichiers

```
claude-test/
├── docker-compose.yml          # Configuration Docker
├── .env                        # Variables d'environnement (mots de passe)
├── .env.example               # Exemple de configuration
├── start.bat                  # Script de démarrage (Windows)
├── stop.bat                   # Script d'arrêt (Windows)
├── README.md                  # Ce fichier
│
├── fairy-tale-generator/      # Plugin monté dans WordPress
│   ├── fairy-tale-generator.php
│   ├── css/
│   ├── js/
│   └── images/
│
├── themes/                    # Dossier pour vos thèmes personnalisés
└── uploads/                   # Fichiers uploadés (images générées)
```

---

## Services Docker

### WordPress
- **URL** : http://localhost:8080
- **Port** : 8080
- **Conteneur** : contesdefees_wordpress

### MySQL
- **Port** : 3306
- **Conteneur** : contesdefees_db
- **Base de données** : contesdefees_wp
- **Utilisateur** : wordpress_user
- **Mot de passe** : wordpress_pass123

### PHPMyAdmin
- **URL** : http://localhost:8081
- **Port** : 8081
- **Conteneur** : contesdefees_phpmyadmin

---

## Commandes Utiles

### Démarrer l'environnement
```bash
docker-compose up -d
```

### Arrêter l'environnement
```bash
docker-compose down
```

### Voir les logs en temps réel
```bash
docker-compose logs -f
```

### Voir les logs WordPress uniquement
```bash
docker-compose logs -f wordpress
```

### Redémarrer un service
```bash
docker-compose restart wordpress
```

### Accéder au terminal WordPress
```bash
docker exec -it contesdefees_wordpress bash
```

### Réinitialiser complètement (ATTENTION : supprime tout)
```bash
docker-compose down -v
docker-compose up -d
```

---

## Développement

### Modifier le plugin

Les fichiers du plugin sont montés directement depuis votre disque :

```
./fairy-tale-generator → /var/www/html/wp-content/plugins/fairy-tale-generator
```

Toute modification dans `fairy-tale-generator/` est **immédiatement reflétée** dans WordPress.

### Ajouter un thème personnalisé

1. Placez votre thème dans le dossier `themes/`
2. Il apparaîtra automatiquement dans WordPress Admin → Apparence → Thèmes

### Déboguer

Le mode debug WordPress est activé par défaut. Les erreurs PHP s'affichent dans :

```bash
docker-compose logs -f wordpress
```

---

## Sauvegarde et Restauration

### Exporter la base de données

1. Allez sur PHPMyAdmin : http://localhost:8081
2. Sélectionnez `contesdefees_wp`
3. Cliquez sur **Exporter** → **Exécuter**

### Importer une base de données

1. PHPMyAdmin → `contesdefees_wp`
2. Cliquez sur **Importer**
3. Sélectionnez votre fichier `.sql`
4. Cliquez sur **Exécuter**

---

## Résolution de Problèmes

### Docker n'est pas en cours d'exécution

**Erreur** : `Cannot connect to the Docker daemon`

**Solution** : Démarrez Docker Desktop et attendez qu'il soit complètement lancé.

---

### Les conteneurs ne démarrent pas

**Solution** :

```bash
docker-compose down
docker-compose up -d --force-recreate
```

---

### Le port 8080 est déjà utilisé

**Solution** : Modifiez le port dans `docker-compose.yml` :

```yaml
wordpress:
  ports:
    - "8090:80"  # Changez 8080 en 8090
```

Puis accédez à http://localhost:8090

---

### Erreur "Table 'wp_options' doesn't exist"

**Cause** : WordPress n'est pas encore installé

**Solution** : Allez sur http://localhost:8080 et suivez l'installation

---

### Les images ne s'affichent pas

**Cause** : Permissions de fichiers

**Solution** :

```bash
docker exec -it contesdefees_wordpress bash
chown -R www-data:www-data /var/www/html/wp-content/uploads
```

---

### Réinitialiser complètement

Si tout est cassé, recommencez de zéro :

```bash
docker-compose down -v
docker volume prune -f
docker-compose up -d
```

⚠️ **ATTENTION** : Cette commande supprime TOUTES les données (base de données, fichiers WordPress, etc.)

---

## Performances

### Augmenter la RAM allouée à Docker

1. Ouvrez Docker Desktop
2. Settings → Resources
3. Augmentez la RAM à **6-8 GB** pour de meilleures performances

---

## Sécurité

### Changer les mots de passe par défaut

Éditez le fichier `.env` et changez :

```env
MYSQL_ROOT_PASSWORD=votre_nouveau_mot_de_passe
MYSQL_PASSWORD=votre_nouveau_mot_de_passe_wordpress
```

Puis recréez les conteneurs :

```bash
docker-compose down -v
docker-compose up -d
```

---

## Prochaines Étapes

1. ✅ Installer WordPress
2. ✅ Activer le plugin Fairy Tale Generator
3. ✅ Configurer la clé API OpenAI
4. ✅ Créer les pages avec les shortcodes
5. ✅ Tester la génération de contes
6. 🎯 Commencer à améliorer le plugin !

---

## Support

Pour toute question ou problème, consultez :

- Documentation WordPress : https://wordpress.org/documentation/
- Documentation Docker : https://docs.docker.com/
- Documentation OpenAI : https://platform.openai.com/docs/

---

**Bon développement ! 🚀**
