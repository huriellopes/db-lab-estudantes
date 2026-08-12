-- Redundante em instalações novas (01-schema.sql já cria "role" com 'admin' incluso),
-- mas documenta a migração aplicada manualmente em bancos que já existiam antes do
-- papel de super admin ser introduzido. Idempotente: seguro rodar mais de uma vez.
USE schoolapp;

ALTER TABLE users MODIFY COLUMN role ENUM('aluno', 'professor', 'admin') NOT NULL;

UPDATE users SET role = 'admin' WHERE email = 'huriellopes1996@gmail.com';
