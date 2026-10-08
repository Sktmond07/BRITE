@echo off
echo ========================================
echo  Testing OCR Script
echo ========================================
echo.

set PYTHONHOME=%~dp0python
set PYTHONPATH=%~dp0python\Lib;%~dp0python\DLLs;%~dp0python\Lib\site-packages
set PATH=%PYTHONHOME%;%PYTHONHOME%\DLLs;%PATH%

echo Testing OCR script...
"%PYTHONHOME%\python.exe" -c "from paddleocr import PaddleOCR; print('✅ PaddleOCR loaded!')"

if errorlevel 1 (
    echo ❌ PaddleOCR not working!
    echo Please run install_python_portable.bat first.
    pause
    exit /b 1
)

echo ✅ PaddleOCR is ready!
pause