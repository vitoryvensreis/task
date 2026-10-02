-- TaskEsc schema 13 — detalhes enriquecidos dos eventos do Google Agenda
ALTER TABLE events
  ADD COLUMN organizer_name VARCHAR(190) NULL AFTER description,
  ADD COLUMN organizer_email VARCHAR(190) NULL AFTER organizer_name,
  ADD COLUMN attendees_json LONGTEXT NULL AFTER organizer_email,
  ADD COLUMN meet_url TEXT NULL AFTER attendees_json,
  ADD COLUMN conference_json LONGTEXT NULL AFTER meet_url,
  ADD COLUMN event_url TEXT NULL AFTER conference_json,
  ADD COLUMN attachments_json LONGTEXT NULL AFTER event_url;

INSERT INTO settings (`key`, value)
VALUES ('schema_version', '13')
ON DUPLICATE KEY UPDATE value='13';
