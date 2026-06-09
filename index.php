<?php

/**
 * Inclui a classe AutorizacaoEmail e executa o processamento.
 */

require_once 'controllers/AutorizacaoEmail.php';

$auth = new AutorizacaoEmail();
$auth->conectar();
$auth->listarAutorizacoes();
$auth->fechar();