-- TaskEsc schema 19
-- Uploads grandes: deixa o TaskEsc sem limite total por padrão.
-- O envio continua resumível em blocos pequenos para não depender do limite de uma requisição HTTP única.

INSERT INTO settings (`key`,value)
VALUES ('upload_mb','0')
ON DUPLICATE KEY UPDATE value='0';

INSERT INTO settings (`key`,value)
VALUES ('schema_version','19')
ON DUPLICATE KEY UPDATE value='19';
