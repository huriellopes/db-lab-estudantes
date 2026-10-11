<?php

// Montado em /etc/phpmyadmin/config.user.inc.php pelo docker-compose.prod.yml.
// O phpMyAdmin é público (alunos usam pelo navegador): root não loga por aqui, nem
// tentando — mesmo que exista uma conta root acessível pela rede.
$cfg['Servers'][1]['AllowRoot'] = false;
