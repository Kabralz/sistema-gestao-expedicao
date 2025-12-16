@echo off
REM Define o diretório onde o main.py está.
cd C:\Users\matheus.souza\Desktop\Projetos\picking_realtime

REM Inicia o servidor Python Uvicorn/FastAPI em uma nova janela.
REM O comando 'start ""' é crucial para que o Agendador de Tarefas não espere o servidor terminar.
start "Picking Realtime Server" python main.py

exit