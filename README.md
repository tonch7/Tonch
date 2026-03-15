Open Source
PHP
SQLite
Self Hosted
No Ads
Lightweight


# FinApp

Sistema de gestão financeira **simples, rápido e totalmente gratuito**.

O FinApp foi criado para quem quer controlar receitas, despesas e contas sem depender de aplicativos pesados, assinaturas ou plataformas cheias de anúncios.

O objetivo do projeto é oferecer uma ferramenta **leve, acessível e totalmente sob controle do usuário**.

---

# Filosofia do Projeto

Hoje existem muitas ferramentas financeiras, mas a maioria apresenta problemas como:

- anúncios invasivos
- planos pagos
- coleta de dados
- aplicativos pesados
- dependência de plataformas externas

O **FinApp** segue uma filosofia diferente.

✔ Sem anúncios  
✔ Sem coleta de dados  
✔ Código aberto  
✔ Hospedagem própria  
✔ Total controle do usuário  

Você pode rodar o sistema:

- no seu servidor
- em hospedagem compartilhada
- em um VPS
- localmente com XAMPP
- em qualquer ambiente com PHP

---

# Recursos

## Dashboard Financeiro

Visão geral da situação financeira com:

- saldo atual
- total de receitas
- total de despesas
- resumo financeiro
- movimentações recentes

---

## Controle de Receitas e Despesas

Sistema completo de lançamentos financeiros.

Permite:

- adicionar receitas
- adicionar despesas
- editar lançamentos
- excluir lançamentos
- organizar movimentações financeiras

---

## Gestão de Contas

Permite cadastrar e gerenciar diferentes contas financeiras.

Exemplos:

- conta corrente
- conta poupança
- carteira
- contas digitais
- contas empresariais

Cada movimentação pode ser vinculada a uma conta específica.

---

## Relatórios Financeiros

Ferramenta para análise das movimentações.

Permite acompanhar:

- receitas por período
- despesas por período
- fluxo financeiro
- histórico financeiro

---

## Exportação de Dados

Exportação das informações financeiras para backup ou análise externa.

Pode ser utilizado para:

- backups
- auditoria
- análise em planilhas
- controle externo

---

# Características Técnicas

- extremamente leve
- rápido
- sem dependências pesadas
- roda em praticamente qualquer servidor

---

# Tecnologias Utilizadas

- PHP
- SQLite
- HTML5
- CSS3
- JavaScript

SQLite foi escolhido por ser:

- rápido
- simples
- sem necessidade de servidor de banco de dados
- ideal para aplicações leves

---

# Estrutura do Projeto


finapp/
│
├── assets/
│ ├── css/
│ └── js/
│
├── database/
│ ├── schema.sql
│ └── finapp.db
│
├── public/
│ ├── login.php
│ ├── dashboard.php
│ ├── lancamentos.php
│ ├── relatorios.php
│ ├── contas_banco.php
│ └── exportar.php
│
├── src/
│ ├── Database.php
│ ├── helpers.php
│ ├── bootstrap.php
│ ├── layout_header.php
│ └── layout_footer.php
│
├── index.php
├── setup_senha.php
└── README.md


---

# Instalação

## Clonar o repositório

```bash
git clone https://github.com/tonch7/FinApp.git
Requisitos

PHP 7.4+

Servidor Apache ou Nginx

Suporte a SQLite

Rodando localmente

Você pode usar:

XAMPP

WAMP

Laragon

MAMP

Docker

qualquer ambiente PHP

Coloque o projeto dentro do diretório do servidor e acesse pelo navegador.

Exemplo:

http://localhost/finapp
Configuração inicial

Ao acessar o sistema pela primeira vez:

Execute a configuração inicial

Defina a senha de acesso

O banco SQLite será configurado automaticamente

Após isso o sistema estará pronto para uso.

Privacidade

O FinApp foi desenvolvido com foco total em privacidade e controle de dados.

O sistema:

não envia dados para servidores externos

não coleta informações do usuário

não possui rastreamento

não possui anúncios

Todos os dados permanecem no seu próprio servidor.

Contribuições

Contribuições são bem-vindas.

Você pode ajudar com:

correção de bugs

melhorias no código

melhorias de interface

novas funcionalidades

melhorias de segurança

Abra uma issue ou envie um pull request.

Licença

Este projeto é open source.

Você pode:

usar

modificar

estudar

distribuir

desde que o crédito ao autor original seja mantido.

Consulte o arquivo LICENSE para mais detalhes.

Considerações Finais

📌 Se você chegou até aqui, parabéns.

Este sistema foi criado inicialmente para facilitar a minha própria vida.

Durante o desenvolvimento, percebi que muitas outras pessoas enfrentam os mesmos desafios ao tentar organizar suas finanças.

Seria uma injustiça manter essa ferramenta apenas para uso pessoal.

Por isso decidi disponibilizá-la gratuitamente para qualquer pessoa que precise de uma solução simples, leve e eficiente.

Se este projeto foi útil para você, considere apoiar o desenvolvimento.

Uma estrela no repositório já ajuda muito.

Autor

Gabriel Perdigão
Fundador — TÖNCH

🌐 https://www.tonch.com.br
