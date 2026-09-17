# Changelog

Todos os recursos notáveis desta biblioteca serão documentados neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/), e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [1.0.0-beta.4] - 2026-09-17

### Adicionado

- Armazenamento temporário de cada fluxo OAuth no cache do Laravel, vinculado a um cookie HTTP-only exclusivo.
- Destino pretendido individual para cada fluxo, permitindo que abas sobrepostas retornem às suas próprias páginas.
- Validação de destinos relativos e absolutos da mesma origem, descartando redirecionamentos externos.
- Testes de regressão para regeneração de sessão, múltiplas abas, expiração, replay, ausência ou troca do cookie e destinos externos.

### Corrigido

- O callback OAuth continua válido quando outro login regenera a sessão do Laravel e invalida seu identificador anterior.
- Estados armazenados no cache são consumidos uma única vez, sob lock específico, mesmo quando callbacks chegam por sessões diferentes.
- Abas autenticadas simultaneamente deixam de disputar o único `url.intended` da sessão.

### Compatibilidade

- Estados criados por versões anteriores continuam aceitos pela sessão durante a atualização.
- A nova persistência em cache e cookie aplica-se somente à integração Laravel; o scaffold PHP legado permanece inalterado.

### Documentação

- README, guia rápido e guia de integração agora explicam cache compartilhado, cookies seguros, destinos por fluxo e diagnóstico de erros de estado OAuth.
- O guia rápido esclarece quando manter `SSO_SYNC_PERMISSIONS=false`.

## [1.0.0-beta.3] - 2026-09-17

### Adicionado

- Bloqueio configurável das rotas OAuth de login e callback para serializar requisições concorrentes da mesma sessão.
- Variáveis `SSO_OAUTH_ROUTE_LOCK_SECONDS` e `SSO_OAUTH_ROUTE_LOCK_WAIT_SECONDS`, ambas com padrão de 30 segundos.
- Testes das durações padrão e personalizadas do bloqueio das rotas.
- Guia rápido de integração Laravel com roteiro inicial, checklist e solução dos problemas mais comuns.

### Corrigido

- Evita perda de estados OAuth pendentes quando várias abas da mesma sessão iniciam ou concluem autenticações simultaneamente.

### Documentação

- README e guia técnico agora explicam a diferença entre múltiplos estados pendentes e bloqueio de concorrência.
- Rotas OAuth sobrescritas manualmente devem preservar o bloqueio de sessão.

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
