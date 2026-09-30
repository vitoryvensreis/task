-- TaskEsc v2.4
-- API v1, visualizacao de cliente e notificacoes de atividade.
-- Sem alteracoes destrutivas de tabelas; apenas registra a versao da aplicacao.
INSERT INTO settings (`key`,value) VALUES ('schema_version','6') ON DUPLICATE KEY UPDATE value='6';
