<?php

require_once "Deploy.php";

$deploy = new Deploy(
    '422ebdfcfff3c36ac146301cd6094bf3',
    'git@github.com:legoritma/PHP-git-deploy.git',
    dirname(__FILE__). '/deploy/'
);

$deploy->start($_GET['token']);