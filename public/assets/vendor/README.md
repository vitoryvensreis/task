# centrifuge-js local

O navegador do TaskEsc usa uma cópia local do cliente oficial `centrifuge` **5.7.4**, compatível com Centrifugo 6.x.

`bin/vendor-centrifuge-js.sh` obtém exatamente `centrifuge@5.7.4` do pacote oficial npm durante CI/deploy, valida nome/versão, extrai o browser build e a licença e grava SHA-256. Em produção o navegador carrega somente `/assets/vendor/centrifuge-5.7.4.js`; não existe dependência de CDN em runtime.
