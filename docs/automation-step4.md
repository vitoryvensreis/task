# Automações — Passo 4: editor visual em árvore

Este passo transforma o motor de automações já entregue no schema 24 em uma área visual para Administradores.

## Nova área no menu

Foi adicionada a página:

```text
Automações
```

Rota:

```text
/automacoes
```

Somente Administradores veem a entrada no menu e o backend continua protegendo todos os endpoints.

## Lista de automações

A tela inicial mostra:

- quantidade total de automações;
- quantidade ativa;
- total de execuções;
- automações cuja última execução falhou;
- nome;
- gatilho;
- estado ativa/pausada;
- última execução;
- botão para ativar/pausar;
- acesso ao editor.

A busca filtra por nome ou gatilho.

## Editor visual

O editor trabalha diretamente com o mesmo `definition_json` já validado pelo backend.

A árvore usa quatro tipos de bloco:

```text
Gatilho
Condição
WhatsApp
Fim
```

Fluxo simples:

```text
[Gatilho]
    |
[WhatsApp]
    |
   [Fim]
```

Com condição:

```text
              [Gatilho]
                  |
              [Condição]
              /        \
            SIM        NÃO
             |          |
        [WhatsApp]     [Fim]
             |
            [Fim]
```

Os botões entre os blocos permitem inserir uma nova condição ou uma ação WhatsApp antes do próximo bloco.

## Condições

O inspetor lateral permite configurar:

- cliente;
- projeto;
- serviço/categoria;
- etapa;
- prioridade;
- responsável;
- destinatário WhatsApp da Task;
- rascunho;
- situação comercial;
- tipo de alteração do evento.

Operadores:

- é igual a;
- é diferente de;
- está em;
- não está em;
- está vazio;
- não está vazio.

Condições geram sempre dois ramos visuais:

```text
SIM
NÃO
```

## WhatsApp

A ação WhatsApp mantém a regra definida no Passo 3:

- o fluxo não armazena número;
- o destinatário continua sendo o definido na própria Task;
- pode ser quem criou a task, cliente ou nenhum;
- rascunhos continuam bloqueados pelo worker.

O editor oferece as variáveis:

- `{{task.id}}`;
- `{{task.title}}`;
- `{{task.due_at}}`;
- `{{task.priority}}`;
- `{{task.url}}`;
- `{{client.name}}`;
- `{{assigned.name}}`;
- `{{creator.name}}`;
- `{{stage.name}}`.

Clicar em uma variável insere o token na posição atual do cursor da mensagem.

## Estado e proteção de edição

O editor mostra se existem alterações não salvas.

Se o usuário tentar:

- recarregar a página;
- fechar a aba;
- navegar para outro módulo;

com alterações pendentes, o TaskEsc pede confirmação antes de descartar.

A versão otimista do backend continua sendo usada para evitar sobrescrever uma automação alterada em outro acesso.

## Execuções

O painel lateral mostra as últimas execuções reais do fluxo:

- status;
- task;
- evento;
- horário;
- número de tentativas;
- erro sanitizado quando houver.

Os dados vêm de `automation_runs`, sem simulação no frontend.

## Responsividade

No desktop:

```text
Canvas da árvore | Inspetor
                 | Execuções
```

Em telas menores, o layout vira uma coluna e o canvas mantém rolagem horizontal quando a árvore fica larga.

## Sem nova migração

O Passo 4 não altera o banco.

Ele usa integralmente o schema 24 e o worker já instalados no Passo 3.

## Próximo passo sugerido

O próximo avanço pode adicionar recursos de produtividade ao editor, por exemplo:

- duplicar automação;
- modelos prontos;
- testar fluxo com uma Task real sem disparar produção;
- novas ações além de WhatsApp;
- atraso/espera;
- webhook;
- e-mail;
- atribuir responsável;
- mover etapa;
- criar comentário.
