<?php

session_start();

require_once 'controlles/AutorizacaoEmail.php';
require_once 'model/AutorizacaoModel.php';

$auth = new AutorizacaoEmail();

try {

    $auth->conectar();

    $resultado = $auth->verificarAutorizacao(
        'ana.zgermano2005@gmail.com'
    );

    $model = new AutorizacaoModel();

    $model->salvar(
        $resultado['email'] ?? 'ana.zgermano2005@gmail.com',
        $resultado['autorizado'],
        $resultado['data_email'] ?? null
    );

    if ($resultado['autorizado']) {

        $_SESSION['token'] = $resultado['token'];

        echo "✅ Acesso liberado!<br>";
        echo "Token: " . $resultado['token'];

    } else {

        echo "❌ Acesso negado: " . $resultado['mensagem'];

    }

} catch (Exception $e) {

    echo "Erro: " . $e->getMessage();

} finally {

    $auth->fechar();

}