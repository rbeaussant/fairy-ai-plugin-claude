# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin development environment for **Fairy Tale Generator** (Générateur de Contes de Fées) - a plugin that allows users to generate personalized fairy tales for children using OpenAI's GPT-4 and DALL-E 3 APIs. The entire development environment runs in Docker containers for easy setup and consistent development.

## Quick Start Commands

### Starting the Environment
```bash
# Windows
start.bat

# Or using Docker Compose directly
docker-compose up -d
```

Wait 30 seconds for WordPress to initialize before accessing the site.

### Stopping the Environment
```bash
# Windows
stop.bat

# Or using Docker Compose directly
docker-compose down
```

### Accessing Services
- **WordPress**: http://localhost:8080
- **PHPMyAdmin**: http://localhost:8081 (user: `root`, password: `rootpassword123`, server: `db`)
- **Admin Panel**: http://localhost:8080/wp-admin

### Viewing Logs
```bash
# All services
docker-compose logs -f

# WordPress only
docker-compose logs -f wordpress

# Database only
docker-compose logs -f db
```

### Restarting Services
```bash
# Restart all
docker-compose restart

# Restart WordPress only
docker-compose restart wordpress
```

### Complete Reset (⚠️ Deletes all data)
```bash
docker-compose down -v
docker-compose up -d
```

### Accessing WordPress Container Shell
```bash
docker exec -it contesdefees_wordpress bash
```

### Git Commands

The repository is configured with Git version control:

```bash
# Check status
git status

# Stage changes
git add .

# Commit changes
git commit -m "Description of changes"

# Push to GitHub
git push

# Pull latest changes
git pull

# View commit history
git log --oneline

# View remote repository
git remote -v
```

**Repository:** https://github.com/rbeaussant/fairy-ai-plugin-claude.git

**Protected files (in `.gitignore`):**
- `.env` - Contains sensitive passwords
- `uploads/` - User-generated content
- `db_data/` - Database volume
- `wordpress_data/` - WordPress volume
- IDE/editor config files

## Architecture Overview

### Docker Services Architecture
The application uses a three-container Docker setup:

1. **WordPress Container** (`contesdefees_wordpress`)
   - Serves the WordPress application on port 8080
   - Has volume mounts for live plugin/theme development
   - Debug mode enabled by default (`WORDPRESS_DEBUG: 'true'`)

2. **MySQL Database** (`contesdefees_db`)
   - MySQL 8.0 with persistent data volume
   - Database: `contesdefees_wp`
   - User: `wordpress_user` / Password: `wordpress_pass123`

3. **PHPMyAdmin** (`contesdefees_phpmyadmin`)
   - Web interface for database management on port 8081

### Plugin Architecture (fairy-tale-generator)

The plugin follows a **monolithic WordPress plugin structure** with the following key components:

#### Main Plugin File
`fairy-tale-generator/fairy-tale-generator.php` (~924 lines) contains:

**Core Functions:**
- `fairy_tale_init()` - Plugin initialization, creates default pages
- `fairy_tale_enqueue_scripts()` - Loads CSS/JS assets
- `fairy_tale_form()` - Main form shortcode `[generateur_conte]`
- `display_user_tales()` - User's tales library shortcode `[liste_contes_auteur]`

**AJAX Endpoints:**
- `generate_fairy_tale_ideas` - Generates 3 story ideas (~55 words each)
- `create_fairy_tale_from_idea` - Creates complete story with title, text, and illustration
- `publish_fairy_tale` - Admin function to publish pending stories

**OpenAI Integration:**
- `generate_with_openai($prompt, $max_tokens)` - Generic GPT-4o text generation
- `generate_illustration_with_openai($prompt_image)` - DALL-E 3 image generation
- Story generation uses JSON response format with keys: `title`, `story`, `illustration_prompt`

**Validation & Repair System:**
- `ftg_validate_story($story, $min_words)` - Validates story completeness using 4 criteria (word count, sentence count, final punctuation, closure markers)
- `ftg_repair_story_closure($title, $story, ...)` - Auto-repairs incomplete stories (runs up to 2 times if needed)
- Scoring system: 3 out of 4 criteria must pass for story to be valid

**User Quota Management:**
- `count_user_conte_this_month()` - Counts user's stories this month using WP_Query
- `get_user_fairy_tale_count($user_id)` - Gets count from user meta
- `increment_user_fairy_tale_count($user_id)` - Increments monthly counter
- `can_user_create_fairy_tale($user_id)` - Checks limits (visitors: 0, subscribers: 10, admins: unlimited)

**Image Handling:**
- `ftg_download_and_probe_image($image_url)` - Downloads and validates image using `getimagesize()`
- `ftg_attach_tmp_image_as_thumb($post_id, $tmp_path, $filename)` - Attaches image as post thumbnail
- `save_illustration($post_id, $image_url)` - Legacy function for image handling

#### Frontend JavaScript
`fairy-tale-generator/js/script.js` (~230 lines):

**Key Features:**
- Idea generation form submission with AJAX
- Multi-stage story creation with progress overlay (4 stages: text generation → validation → illustration → save)
- Progress bar with smooth animations (0-100%)
- Stores ideas in `FTG_IDEA_STORE` array for later use
- Timeout handling (60s for ideas, 120s for full story creation)
- Session/nonce expiration detection

**Progress Stages:**
- Stage 1 (0-40%): Text generation (20-40 seconds)
- Stage 2 (40-65%): Validation and formatting
- Stage 3 (65-90%): Illustration creation
- Stage 4 (90-98%): Saving to database

#### Styling
`fairy-tale-generator/css/style.css` (~179 lines):
- Form styling (2-column inline layout at 49% width each)
- Tale card grid (responsive with `auto-fill, minmax(250px, 1fr)`)
- Admin table styling
- Responsive mobile layout (stacks to single column)

### Story Generation Flow

1. **User fills form** → selects age, duration, character, location, theme, object (or random)
2. **Generates 3 ideas** → AJAX call returns 3 short ideas (~55 words each) separated by `|`
3. **User selects one idea** → clicks "Créer le conte"
4. **Backend creates story atomically:**
   - Generate JSON with title, story text (~150-700 words based on duration), illustration prompt
   - Validate story (word count, sentences, punctuation, closure)
   - If invalid: auto-repair up to 2 times using `ftg_repair_story_closure()`
   - Generate DALL-E 3 illustration
   - Download and validate image file
   - Create WordPress post (CPT: `conte-ai`)
   - Attach image as thumbnail
   - Set taxonomies (age, duree, personnage, lieu, thematique, objet)
   - Increment user quota
5. **Redirect to published story**

### Custom Post Type & Taxonomies

**Post Type:** `conte-ai` (Contes AI)

**Taxonomies:**
- `age` - Target age groups (1-2, 3-5, 6-8, 9-12 ans)
- `duree` - Reading duration (1, 3, 5 minutes)
- `personnage` - Main character type (humain, animal, prince, princesse, etc.)
- `lieu` - Location (château, forêt, village, grotte, etc.)
- `thematique` - Theme (aventure, amitié, courage, bonté, etc.)
- `objet` - Magical object (baguette, miroir, épée, cape, etc.)

### Configuration Requirements

The plugin requires an OpenAI API key to be stored in WordPress options. Set it via:

**Option 1: PHPMyAdmin SQL**
```sql
INSERT INTO wp_options (option_name, option_value, autoload)
VALUES ('openai_api_key', 'sk-YOUR_KEY_HERE', 'yes')
ON DUPLICATE KEY UPDATE option_value = 'sk-YOUR_KEY_HERE';
```

**Option 2: PHP Code Snippet**
```php
update_option('openai_api_key', 'sk-YOUR_KEY_HERE');
```

The key is accessed via: `get_option('openai_api_key')`

## Development Workflow

### Making Plugin Changes

The plugin directory is **volume-mounted** into the WordPress container:
```
./fairy-tale-generator → /var/www/html/wp-content/plugins/fairy-tale-generator
```

**Changes are reflected immediately** - just refresh the browser. No container restart needed.

### Adding Custom Themes

Place theme folders in `./themes/` directory. They will appear in WordPress Admin → Appearance → Themes.

### Debugging

- WordPress debug mode is **enabled by default** in `docker-compose.yml`
- PHP errors appear in container logs: `docker-compose logs -f wordpress`
- JavaScript errors appear in browser console
- OpenAI API calls are logged with `error_log()` in PHP

### Database Access

- Use PHPMyAdmin at http://localhost:8081 for visual management
- Direct MySQL access: host `localhost:3306`, credentials from `.env`
- Database name: `contesdefees_wp`
- All WordPress tables use `wp_` prefix

### Backup & Restore

**Export Database:**
1. PHPMyAdmin → `contesdefees_wp` → Export → Execute

**Import Database:**
1. PHPMyAdmin → `contesdefees_wp` → Import → Select `.sql` file → Execute

## Important Implementation Details

### Story Generation Prompts

The plugin uses **structured JSON responses** from OpenAI to ensure predictable parsing:
- System message: "Tu écris en français, style adapté aux enfants. Réponds de façon concise et structurée."
- Response format enforced in prompt: `{"title":"string","story":"string","illustration_prompt":"string"}`
- Model: `gpt-4o` (GPT-4 Optimized)
- Temperature: 0.7

### Story Validation Criteria

Stories must pass **3 out of 4 criteria**:
1. **Word count**: ≥80% of target (target varies by duration: 1min=150, 3min=450, 5min=700)
2. **Sentence count**: ≥4 sentences (split by `.!?…`)
3. **Final punctuation**: Ends with `.!?…` (with optional closing quotes/parentheses)
4. **Closure markers**: Contains words like "Ainsi", "Enfin", "Depuis ce jour", "Et ils vécurent", etc.

### Illustration Generation

- Model: `dall-e-3`
- Size: `1024x1024`
- Style constraint: "gravure XIXe / encre + aquarelle légère, lisible, plan moyen sur le héros" (19th century engraving with light watercolor)
- Content safety: Prompts emphasize "neutre et sécure pour enfants" (neutral and child-safe)

### Atomicity & Error Handling

The story creation process is **atomic**:
- If any step fails, the post is deleted: `wp_delete_post($post_id, true)`
- User quota is only incremented on **complete success**
- Image validation uses `getimagesize()` to ensure downloaded file is actually an image
- Failed downloads are cleaned up: `@unlink($tmp_img)`

### Session & Nonce Handling

Frontend JavaScript detects expired sessions:
- WordPress AJAX returns `0` or `-1` for invalid nonce
- Shows user-friendly error: "Session expirée (nonce). Rechargez la page puis réessayez."

## Common Development Tasks

### Modifying Story Generation Logic

Edit the prompt in `create_fairy_tale_from_idea()` function around line 555-570. The prompt includes:
- Age targeting
- Theme, character, location, object constraints
- Target word count based on duration
- Tense consistency requirements
- Child-friendly content requirements

### Adjusting User Quotas

Modify `can_user_create_fairy_tale()` function around line 210-226:
- Administrators: unlimited (`return true`)
- Subscribers: currently 10 per month
- Visitors: currently 0 (must be logged in)

### Adding New Form Fields

1. Add HTML in `fairy_tale_form()` function
2. Capture value in JavaScript `$('#fairy-tale-form').on('submit', ...)` (line 86-156)
3. Add to AJAX payload
4. Update PHP AJAX handler to sanitize and use the new field
5. Update OpenAI prompt to include the new parameter

### Modifying Validation Rules

Edit `ftg_validate_story()` function (lines 45-86):
- Adjust minimum word count multiplier (currently 80%: `$min_words * 0.8`)
- Modify minimum sentence count (currently 4)
- Add/remove closure marker keywords
- Change scoring threshold (currently 3 out of 4 criteria)

## Files Structure Summary

```
claude-test/
├── docker-compose.yml          # Docker orchestration (3 services)
├── .env                        # Environment variables (passwords)
├── start.bat / stop.bat        # Windows helper scripts
├── README.md                   # Detailed setup & troubleshooting
│
├── fairy-tale-generator/       # Main plugin (mounted live)
│   ├── fairy-tale-generator.php  # Core logic (924 lines)
│   ├── css/style.css            # Frontend styling (179 lines)
│   ├── js/
│   │   ├── script.js            # Main frontend logic (232 lines)
│   │   └── script1.js           # (legacy/unused)
│   └── images/                  # Form icons (PNG) + loading GIF
│
├── themes/                     # Custom themes mount point
└── uploads/                    # User-uploaded files mount point
```

## Testing Workflow

1. Start environment: `start.bat` (Windows) or `docker-compose up -d`
2. Access WordPress: http://localhost:8080
3. Complete WordPress installation if first run
4. Activate "Générateur de Contes de Fées" plugin
5. Add OpenAI API key via PHPMyAdmin SQL query
6. Create page with `[generateur_conte]` shortcode
7. Create page with `[liste_contes_auteur]` shortcode
8. Test full flow: generate ideas → create story → view published tale

## Performance Considerations

- Story generation takes **~60-90 seconds total** (text: 20-40s, image: 30-40s, save: <10s)
- Frontend shows 4-stage progress bar to manage user expectations
- AJAX timeouts: 60s for ideas, 120s for full creation
- OpenAI API timeout: 50s per request
- Docker RAM recommendation: 4-8 GB for smooth operation

## Security Notes

- OpenAI API key stored in WordPress database (`wp_options` table)
- All user inputs sanitized with `sanitize_text_field()`
- Story content filtered with `wp_kses_post()` before saving
- Nonce verification on all AJAX requests
- Permission checks: `can_user_create_fairy_tale()` enforces quotas
- Admin-only access to publishing interface (`edit_posts` capability)
