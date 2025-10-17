@echo off
echo ========================================
echo   Demarrage de WordPress - ContesDeFees
echo ========================================
echo.

REM Vérifier si Docker est en cours d'exécution
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo ERREUR: Docker n'est pas en cours d'execution.
    echo Veuillez demarrer Docker Desktop et reessayer.
    pause
    exit /b 1
)

echo [1/3] Demarrage des conteneurs Docker...
docker-compose up -d

if %errorlevel% neq 0 (
    echo.
    echo ERREUR lors du demarrage des conteneurs.
    pause
    exit /b 1
)

echo.
echo [2/3] Attente du demarrage de WordPress (30 secondes)...
timeout /t 30 /nobreak >nul

echo.
echo [3/3] Environnement pret !
echo.
echo ========================================
echo   ACCES A VOTRE SITE
echo ========================================
echo.
echo WordPress       : http://localhost:8080
echo PHPMyAdmin      : http://localhost:8081
echo.
echo Identifiants PHPMyAdmin :
echo   Serveur       : db
echo   Utilisateur   : root
echo   Mot de passe  : rootpassword123
echo.
echo ========================================
echo   COMMANDES UTILES
echo ========================================
echo.
echo Arreter         : docker-compose down
echo Logs            : docker-compose logs -f
echo Redemarrer      : docker-compose restart
echo.
pause
