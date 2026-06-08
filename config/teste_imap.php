<?php

$host = '{imap.gmail.com:993/imap/ssl}INBOX';
$email = 'germanoana943@gmail.com';
$senha = 'nedrvgumctbpppzo';

$conexao = @imap_open($host, $email, $senha);

if ($conexao) {
    echo "✅ Conectado com sucesso!";
    imap_close($conexao);
} else {
    echo "❌ Erro: " . imap_last_error();
}
