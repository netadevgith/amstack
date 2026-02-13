-- Create the storage database and user
CREATE DATABASE IF NOT EXISTS `storage`;
GRANT ALL PRIVILEGES ON `storage`.* TO 'stor_user'@'%' IDENTIFIED BY 'stor_password_local';
GRANT ALL PRIVILEGES ON `mailsendas_testdev`.* TO 'app'@'%' IDENTIFIED BY 'app_password_local';
FLUSH PRIVILEGES;
