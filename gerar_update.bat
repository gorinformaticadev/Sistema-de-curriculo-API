@echo off
set PHP_PATH=C:\xampp\php\php.exe

if not exist "%PHP_PATH%" (
    echo ========================================================
    echo ERRO: O executavel do PHP nao foi encontrado no XAMPP!
    echo ========================================================
    echo Verifique se o XAMPP esta instalado em C:\xampp
    pause
    exit /b
)

echo Iniciando processo de empacotamento...
"%PHP_PATH%" build_update.php %1 %2
pause
