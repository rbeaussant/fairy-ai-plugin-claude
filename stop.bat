@echo off
echo ========================================
echo   Arret de WordPress - ContesDeFees
echo ========================================
echo.

echo Arret des conteneurs Docker...
docker-compose down

if %errorlevel% neq 0 (
    echo.
    echo ERREUR lors de l'arret des conteneurs.
    pause
    exit /b 1
)

echo.
echo Conteneurs arretes avec succes !
echo.
pause
