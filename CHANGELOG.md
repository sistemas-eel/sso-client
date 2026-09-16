# Changelog

Todos os recursos notáveis desta biblioteca serão documentados neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/), e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [1.0.0-beta.2] - 2026-09-16

### Adicionado

- Configuração de TTL e quantidade máxima de estados OAuth pendentes na integração Laravel.
- Testes de logins sobrepostos, callback inválido, replay, expiração, limite e parâmetro malformado.

### Corrigido

- O login Laravel agora preserva múltiplos fluxos OAuth pendentes na mesma sessão, consome somente o `state` validado e mantém compatibilidade de callback com o antigo `oauth_state` durante a atualização.

## [1.0.0-beta.1] - 2026-07-03
### Adicionado
- Integração básica com o Portal de Sistemas da USP.
- Suporte a aplicações legadas (PHP puro).
- Provedor de serviço, rotas e middlewares para integração com Laravel (^8.0 a ^13.0).
- Suporte a sincronização de permissões com `spatie/laravel-permission`.
- Tratamento e validação de assinatura de Webhooks para logout global.
- Testes unitários para os diferentes cenários suportados.
- Arquivos `LICENSE` e `CHANGELOG.md`.
