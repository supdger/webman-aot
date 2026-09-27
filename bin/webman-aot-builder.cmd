@echo off
setlocal

if /I "%PROCESSOR_ARCHITECTURE%"=="AMD64" goto architecture_ok
if /I "%PROCESSOR_ARCHITEW6432%"=="AMD64" goto architecture_ok
1>&2 echo webman-aot-builder requires Windows x64.
exit /b 1

:architecture_ok
if defined WEBMAN_AOT_BUILDER_HOME (
    set "AOT_HOME=%WEBMAN_AOT_BUILDER_HOME%"
) else (
    if not defined LOCALAPPDATA (
        1>&2 echo webman-aot-builder cannot resolve the current user data directory.
        exit /b 1
    )
    set "AOT_HOME=%LOCALAPPDATA%\webman-aot-builder"
)

set "PRIVATE_PHP=%AOT_HOME%\current\runtime\php.exe"
set "APPLICATION=%AOT_HOME%\current\app\bin\webman-aot-builder.php"

if not exist "%PRIVATE_PHP%" (
    1>&2 echo webman-aot-builder private PHP runtime is missing: %PRIVATE_PHP%
    exit /b 1
)
if not exist "%APPLICATION%" (
    1>&2 echo webman-aot-builder application is missing: %APPLICATION%
    exit /b 1
)

"%PRIVATE_PHP%" -c "%AOT_HOME%\current\runtime\php.ini" -d "extension_dir=%AOT_HOME%\current\runtime\ext" "%APPLICATION%" %*
exit /b %ERRORLEVEL%
