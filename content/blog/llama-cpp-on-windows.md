---
title: Quick guide on getting llama.cpp up and running on Windows
slug: llama-cpp-on-windows
date: 2025-03-05
excerpt: A practical guide to running llama.cpp on Windows with downloadable binaries and GGUF models.
tags: [llm, windows, llama.cpp]
published: true
---

Quick guide on getting llama cpp up and running on Windows. I’m using downloadable binaries and not the llama cpp cli.

{{toc}}

# Download binaries

Go to the Llama cpp github repository and navigate to the [latest release](https://github.com/ggml-org/llama.cpp/releases). There should be a list of downloadable tar.gz files to download and unpack. For an RTX 5090 setup, use:

- Windows x64 (CUDA 13) - CUDA 13.1 DLLs

Download both files, unpack them, and move all the files in the DLL dir to the main dir.

# Download a GGUF

You can download with `curl` or browse hugging face for models that have downloadable `.gguf` files (there’s a filter on their search page).

Download the `.gguf` file, ideally inside a dedicated `models` directory (plan to store all your model `.gguf` files here). Take note of the path to the directory.

# Do a test run

Navigate to the main llama cpp file. Do this from the folder where `llama-server.exe` lives.

If all you want is “open the chat UI on localhost and use one of my GGUF files,” the simplest command is:

```
.\llama-server.exe -m "D:\models\your-model.gguf" -c 8192 --host 127.0.0.1 --port 8080 --n-gpu-layers 99
```

On Windows, the official quick start uses exactly this pattern: `llama-server.exe -m ...`, and by default it listens on `127.0.0.1:8080` with the web front end at that same URL.

Then open:

```
http://127.0.0.1:8080/
```

# Reopen in router mode

`llama-server` also supports **router mode** if you start it **without** `-m`. In that mode it exposes model load/unload APIs.

The command is:

```
.\llama-server.exe --models-dir "C:\Users\Flawnson\models" --host 127.0.0.1 --port 8080 -c 8192 --n-gpu-layers 99 --models-max 1
```

# Create a shortcut

It would be a lot easier to not have to run these commands every time you want to start using a local model. On Windows, you can create a shortcut by:

```bash
touch start-llama-router.bat
```

Vim into the file and paste:

The Virgin unsafe version:

```bash
@echo off
cd /d "C:\path\to\llama-cpp"
start "" /min cmd /c ".\llama-server.exe --models-dir ""C:\path\to\models"" --host 127.0.0.1 --port 8080 -c 8192 --n-gpu-layers 99 --models-max 1"
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:8080/"
```

The Chad safe version:

```bash
@echo off
setlocal

set "LLAMA_DIR=C:\path\to\llama-cpp"
set "MODELS_DIR=C:\path\to\models"
set "PORT=57575"

set "PID="
set "PROC="

for /f "tokens=5" %%a in ('netstat -ano ^| findstr /R /C:":%PORT% .*LISTENING"') do (
    set "PID=%%a"
    goto :found_port
)

:free_port
cd /d "%LLAMA_DIR%"
start "" /min cmd /c ".\llama-server.exe --models-dir ""%MODELS_DIR%"" --host 127.0.0.1 --port %PORT% -c 8192 --n-gpu-layers 99 --models-max 1"
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:%PORT%/"
exit /b 0

:found_port
for /f "skip=3 tokens=1" %%a in ('tasklist /FI "PID eq %PID%"') do (
    set "PROC=%%a"
    goto :check_proc
)

:check_proc
if /I "%PROC%"=="llama-server.exe" (
    echo llama-server is already running on port %PORT%. Reusing it.
    start "" "http://127.0.0.1:%PORT%/"
    exit /b 0
) else (
    echo Port %PORT% is already in use by %PROC% ^(PID %PID%^). Not starting llama-server.
    exit /b 1
)
```

Once the `.bat` file has been created, you can create a shortcut by:

- right-clicking `start-llama-router.bat`
- click **Show more options** if needed
- click **Create shortcut**
- drag that shortcut to your desktop, or copy/paste it there

You can rename the shortcut if you’d like.

If you want it to look good, you can add an icon by downloading the llama cpp logo, turning it into a `.ico`, and selecting it in the shortcut icon selection window.

You can do that by: Right-click the shortcut → **Properties** → **Shortcut** tab → **Change Icon**.

Windows can be annoying about pinning `.bat` files directly, so pin the **shortcut**, not the `.bat`.

- right-click the desktop shortcut
- click **Pin to taskbar**

If that option does not show up, do this instead:

- right-click the shortcut
- open **Properties**
- in **Target**, change it from the batch file path to:

```
C:\Windows\System32\cmd.exe /c "C:\Users\Flawnson\start-llama-router.bat"
```

That’s it. You should able to use it like any other application on your computer now.