# Changelog

Todos os recursos notáveis desta biblioteca serão documentados neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/), e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [1.0.0-beta.2] - 2026-06-19
### Adicionado
- Suporte a Laravel 13.

## [1.0.0-beta.1]
### Adicionado
- Integração básica com o Portal de Sistemas da USP.
- Suporte a aplicações legadas (PHP puro).
- Provedor de serviço, rotas e middlewares para integração com Laravel (^8.0 a ^13.0).
- Suporte a sincronização de permissões com `spatie/laravel-permission`.
- Endpoint de "Agente" (server-to-server) com validação de token por introspecção.
- Tratamento e validação de assinatura de Webhooks para logout global.
- Testes unitários para os diferentes cenários suportados.
- Arquivos `LICENSE` e `CHANGELOG.md`.
