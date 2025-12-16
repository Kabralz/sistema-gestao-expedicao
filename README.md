# 📦 Sistema de Gerenciamento Expedição - Comercial Souza

[![Status](https://img.shields.io/badge/status-Em%20Produção-brightgreen)]()
[![PHP](https://img.shields.io/badge/PHP-7%2B-blue)]()
[![XAMPP](https://img.shields.io/badge/XAMPP-Apache%20%2B%20MySQL-orange)]()
[![Banco](https://img.shields.io/badge/MySQL-Database-yellowgreen)]()

---

## 📝 Descrição

Contexto
O setor de expedição apresentava gargalos no monitoramento da produção em tempo real. O processo dependia de planilhas de Excel extremamente volumosas para alimentar o Power BI, o que gerava latência na atualização dos dados (análises apenas de hora em hora). Além disso, o fluxo era altamente suscetível a falhas humanas, como erros de digitação, registros duplicados e lentidão operacional.

Ação
Desenvolvi um sistema de monitoramento em tempo real utilizando Python, integrando o ERP da empresa diretamente a um banco de dados MariaDB. A solução otimizou a comunicação entre as bases de dados, permitindo a extração e o processamento de dados de forma assíncrona e muito mais veloz que o método anterior.

Resultado
A implementação centralizou as ferramentas de gestão e controle, eliminando a redundância de dados e bloqueando erros de duplicidade na origem. Além disso, o sistema passou a realizar uma "limpeza" automática de pedidos com erros de digitação ou inconsistências de banco, garantindo integridade total da informação e um ganho expressivo na performance global da expedição.

---

## 🛠️ Funcionalidades Principais

✅ Substituição completa de planilhas Excel por sistema centralizado
✅ Integração direta com banco de dados MariaDB
✅ Controle de expedição por status (em separação, expedido, conferido, finalizado)
✅ Validação automática para evitar duplicidade de registros
✅ Tratamento de exceções para garantir integridade dos dados
✅ Operação multiusuário com controle básico de permissões
✅ Logs de operações para rastreabilidade e auditoria
✅ Otimização de performance para uso em rede local
✅ Redução de retrabalho e erros manuais no processo operacional
✅ Base preparada para evolução e escalabilidade do sistema

---

## 📁 Estrutura do Projeto

📁 Pasta principal (PICKING)

/picking
├── api_funcionarios.php
├── api_gabaritos.php
├── crud_gabaritos.php
├── funcionarios.php
├── inserir_dados_teste.php
├── inserir_dados.php
├── login.php
├── logout.php
├── troca_senha.php
├── registro_ocorrencias.php
├── salvar_ocorrencia.php
├── pickingtv.html
├── pickingtv_teste.html
├── prototipo.txt
├── Logo.svg

Responsável por exibir o sistema aos usuários, controlar acessos e registrar operações.

Principais responsabilidades:

Interface web de operação (login, picking, conferência)

Controle de status dos pedidos

Registro de ocorrências e eventos operacionais

Painel de acompanhamento em tempo real

Controle de usuários e permissões básicas

Comunicação com o banco de dados central

👉 Essa camada substitui totalmente o uso de planilhas Excel, padronizando o processo operacional.


2️⃣ Camada de Integração e Automação (Python – Real Time)

📁 Pasta picking_realtime

/picking_realtime
├── backup/
├── importar_funcionarios.py
├── main.py                 # Robô principal (ponte com ERP / integração)
├── main_teste.py
├── pendentes.py
├── transacao.py
├── robo_faxina.py
├── robo_faxina_looping.py
├── start_picking_server.ba

Responsável por integrações, automações e manutenção do sistema em tempo real, sem intervenção humana.

Componentes principais:

main.py
Robô principal que faz a ponte com o servidor de integração / ERP, sincronizando dados de pedidos, status e eventos.

Robôs auxiliares de manutenção

Limpeza e normalização de dados

Atualização automática de pendências

Monitoramento de estados inconsistentes

Processos cíclicos (looping) para tempo real

Controle transacional

Garantia de integridade dos dados

Prevenção de duplicidades

Recuperação automática em caso de falhas

Benefícios para o Negócio

✅ Operação em tempo real, sem dependência manual
✅ Redução de erros humanos e retrabalho
✅ Maior estabilidade e confiabilidade dos dados
✅ Sistema escalável e preparado para crescimento
✅ Separação clara entre interface e automação
✅ Manutenção facilitada sem impacto na operação
```
---

## 📸 Capturas de tela e explicações

> As imagens a seguir ilustram as funcionalidades do sistema.

### 1. 🔐 Login (`login.php`)
Tela de autenticação com controle por tipo de perfil.  
![Login](prints/login.png)

### 2. 📅 Calendário de Agendamentos (`pagina-principal.php`)
Interface com dias disponíveis, bloqueados e modal de agendamento.  
![Calendário de Agendamentos](prints/calendario.png)

### 3. 🗂️ Visualização de Agendamentos (`visao-agendamentos.php`)
Área interna para consulta de todos os agendamentos cadastrados.  
![Visualização de Agendamentos](prints/agendamentos.png)

### 4. 🧾 Visualização de Recebimento (`visao-recebimento.php`)
Permite registro e liberação das cargas que chegam no dia.  
![Visualização de Recebimento](prints/recebimento.png)

### 5. 🛎️ Painel da Recepção (`visao-recepcao.php`)
Mostra agendamentos do dia com botão de chamada e conferência.  
![Painel da Recepção](prints/recepcao.png)

### 6. 🌐 Página Pública (`pagina-publica.php`)
Apresenta informações e acesso ao módulo público.  
![Página Pública](prints/publica.png)

### 7. 👁️ Calendário Publico (`pagina-publica.php`)
Permite qualquer visitante consultar dias agendados/livres.  
![Calendário Publico](prints/calendario-publico.png)

### 8. 👁️ Ver Agendamentos Públicos (`visao-agendamentos-publico.php`)
Permite qualquer visitante consultar os agendamentos que ele mesmo fez.  
![Ver Agendamentos Públicos](prints/agendamentos-publicos.png)

### 9. 📤 Redirecionamento por E-mail
O setor de Compras é responsável por encaminhar automaticamente o link de agendamento aos fornecedores.
![Email](prints/email.png)

---

## 👨‍💻 Autor

**Matheus Cabral**  
Sistema desenvolvido para uso interno da operação logística do Souza Atacado Distribuidor.  

---

## 🤝 Colaboradores

**Alexandre Rodrigues** – Contribuição na parte de User Interface (UI) e User Experience (UX)
j

## 📄 Licença

Projeto de uso interno.  
Livre para adaptar conforme a necessidade da empresa.
