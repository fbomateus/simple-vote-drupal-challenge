# Simple Vote

Sistema de votação para Drupal 11 com entidade customizada, API REST e interface administrativa.

## Visao Geral

O modulo implementa um sistema completo de enquetes onde:

- Administradores criam perguntas com opcoes de resposta (imagem, titulo e descricao)
- Usuarios autenticados votam uma unica vez por pergunta
- Resultados podem ser exibidos ou ocultados por pergunta
- Uma flag global permite desabilitar todo o sistema de votacao

## Requisitos

| Componente | Versao |
|------------|--------|
| Drupal     | 11.x   |
| PHP        | 8.3+   |
| MySQL      | 8.0+   |

## Instalacao

```bash
lando start
lando composer install
lando drush en simple_vote basic_auth -y
lando drush cr
```

## Usuarios de Teste

Os seguintes usuarios foram criados para teste da aplicacao e autenticacao na API:

| Usuario  | Senha  | Perfil        |
| -------- | ------ | ------------- |
| admin    | admin | Administrador |
| maria    | 123456 | Usuario comum |
| joao     | 123456 | Usuario comum |
| ana      | 123456 | Usuario comum |
| jose     | 123456 | Usuario comum |
| paulo    | 123456 | Usuario comum |
| lucas    | 123456 | Usuario comum |
| carlos   | 123456 | Usuario comum |
| marcos   | 123456 | Usuario comum |
| juliana  | 123456 | Usuario comum |
| fernanda | 123456 | Usuario comum |

## Configuracao

### Permissoes

Acesse `/admin/people/permissions` e configure:

| Permissao                           | Descricao                              |
|-------------------------------------|----------------------------------------|
| `administer simple vote questions`  | Gerenciar perguntas e configuracoes    |
| `access simple vote`                | Visualizar listagem de perguntas       |
| `vote on simple vote questions`     | Registrar votos                        |
| `access simple vote api`            | Acessar endpoints da API               |
| `view simple vote results`          | Visualizar resultados das votacoes     |

### Configuracoes Globais

Em `/admin/config/simple-vote/settings`:

- **Habilitar votacao**: Liga/desliga todo o sistema de votacao

## Estrutura do Modulo

```
simple_vote/
├── config/
│   ├── install/
│   │   └── simple_vote.settings.yml
│   └── schema/
│       └── simple_vote.schema.yml
├── src/
│   ├── Access/
│   │   └── VoteQuestionAccessControlHandler.php
│   ├── Controller/
│   │   ├── SimpleVoteApiController.php
│   │   └── UserVoteController.php
│   ├── Entity/
│   │   ├── VoteQuestion.php
│   │   └── VoteQuestionListBuilder.php
│   ├── Form/
│   │   ├── SimpleVoteSettingsForm.php
│   │   ├── UserVoteForm.php
│   │   └── VoteQuestionForm.php
│   └── Service/
│       └── SimpleVoteManager.php
├── simple_vote.info.yml
├── simple_vote.install
├── simple_vote.links.menu.yml
├── simple_vote.links.task.yml
├── simple_vote.module
├── simple_vote.permissions.yml
├── simple_vote.routing.yml
└── simple_vote.services.yml
```

## Rotas

### Administracao

| Rota                                         | Descricao                    |
|----------------------------------------------|------------------------------|
| `/admin/config/simple-vote/settings`         | Configuracoes do modulo      |
| `/admin/content/simple-vote`                 | Listagem de perguntas        |
| `/admin/content/simple-vote/add`             | Criar nova pergunta          |
| `/admin/content/simple-vote/{id}/edit`       | Editar pergunta              |
| `/admin/content/simple-vote/{id}/delete`     | Excluir pergunta             |

### Interface do Usuario

| Rota                              | Descricao                    |
|-----------------------------------|------------------------------|
| `/simple-vote/questions`          | Listagem publica de perguntas|
| `/simple-vote/questions/{id}`     | Pagina de votacao            |

### API REST

| Metodo | Rota                                          | Descricao              |
|--------|-----------------------------------------------|------------------------|
| GET    | `/api/simple-vote/questions`                  | Listar perguntas       |
| GET    | `/api/simple-vote/questions/{identifier}`     | Detalhar pergunta      |
| POST   | `/api/simple-vote/questions/{identifier}/vote`| Registrar voto         |
| GET    | `/api/simple-vote/questions/{identifier}/results` | Obter resultados   |

## API

### Autenticacao

A API usa Basic Auth do Drupal. Inclua o header `Authorization` em todas as requisicoes:

```bash
curl -u usuario:senha http://simple-vote.lndo.site/api/simple-vote/questions
```

### Endpoints

#### Listar Perguntas

```http
GET /api/simple-vote/questions
```

#### Detalhar Pergunta

```http
GET /api/simple-vote/questions/{identifier}
```

#### Registrar Voto

```http
POST /api/simple-vote/questions/{identifier}/vote
Content-Type: application/json

{
  "option_id": "0"
}
```

#### Obter Resultados

```http
GET /api/simple-vote/questions/{identifier}/results
```

### Codigos de Resposta

| Codigo | Significado                                      |
|--------|--------------------------------------------------|
| 200    | Sucesso                                          |
| 400    | Requisicao invalida (payload malformado)         |
| 401    | Nao autenticado                                  |
| 403    | Sem permissao ou votacao desabilitada            |
| 404    | Pergunta nao encontrada                          |
| 409    | Conflito (usuario ja votou)                      |

## Modelo de Dados

### Entidade `vote_question`

| Campo          | Tipo         | Descricao                              |
|----------------|--------------|----------------------------------------|
| `id`           | int          | ID interno (auto-increment)            |
| `uuid`         | string       | UUID da entidade                       |
| `identifier`   | string       | Identificador unico legivel            |
| `question`     | string       | Texto da pergunta                      |
| `options`      | json         | Array de opcoes serializadas           |
| `show_results` | boolean      | Exibir resultados aos usuarios         |
| `status`       | boolean      | Publicado/despublicado                 |
| `created`      | timestamp    | Data de criacao                        |
| `changed`      | timestamp    | Data da ultima alteracao               |

### Tabela `simple_vote_record`

| Campo         | Tipo      | Descricao                                |
|---------------|-----------|------------------------------------------|
| `id`          | int       | ID do registro                           |
| `question_id` | int       | FK para vote_question                    |
| `uid`         | int       | ID do usuario que votou                  |
| `option_id`   | string    | ID da opcao escolhida                    |
| `created`     | timestamp | Data do voto                             |

**Indice unico:** `(question_id, uid)` - garante voto unico por usuario/pergunta.
