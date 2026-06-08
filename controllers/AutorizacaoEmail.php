<?php

class AutorizacaoEmail {

    private $conexao;

    private $config = [
        'host'    => '{imap.gmail.com:993/imap/ssl}INBOX',
        'usuario' => 'germanoana943@gmail.com',
        'senha'   => 'fyundigyadhuvkll',
    ];

    public function conectar() {
        $this->conexao = imap_open(
            $this->config['host'],
            $this->config['usuario'],
            $this->config['senha']
        );

        if (!$this->conexao) {
            die("Erro IMAP: " . imap_last_error());
        }
    }

    public function pegarCorpoEmail($emailId) {
        $estrutura = imap_fetchstructure($this->conexao, $emailId);
        $corpo = '';

        if (!isset($estrutura->parts)) {
            $corpo = imap_body($this->conexao, $emailId);
        } else {
            foreach ($estrutura->parts as $i => $parte) {
                $parteIndex = $i + 1;
                $dados = imap_fetchbody($this->conexao, $emailId, $parteIndex);

                if ($parte->encoding == 3) {
                    $dados = base64_decode($dados);
                } elseif ($parte->encoding == 4) {
                    $dados = quoted_printable_decode($dados);
                }

                if (isset($parte->subtype) && $parte->subtype == 'PLAIN') {
                    $corpo = $dados;
                    break;
                }

                if (isset($parte->subtype) && $parte->subtype == 'HTML') {
                    $corpo = strip_tags($dados);
                }
            }
        }

        return trim($corpo);
    }

    public function listarAutorizacoes() {

        // 🔍 Busca APENAS e-mails com o assunto exato
        $emails = imap_search($this->conexao, 'SUBJECT "AUTORIZAÇÃO DE ACESSO"');

        if (!$emails) {
            echo "<p>Nenhuma autorização encontrada.</p>";
            return;
        }

        rsort($emails);

        foreach ($emails as $emailId) {

            $header = imap_headerinfo($this->conexao, $emailId);
            $corpo  = $this->pegarCorpoEmail($emailId);

            $remetenteEmail = $header->from[0]->mailbox . '@' . $header->from[0]->host;

            $emailAutorizar = '';
            if (preg_match('/Email:\s*([\w\.\-\+]+@[\w\.\-]+\.[a-z]{2,})/i', $corpo, $match)) {
                $emailAutorizar = $match[1];
            }

            // Exibe o card
            echo '<div style="
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 16px 20px;
                margin-bottom: 12px;
                font-family: sans-serif;
                font-size: 14px;
                line-height: 2;
            ">';

            echo "Remetente: <strong>{$remetenteEmail}</strong><br>";

            if ($emailAutorizar) {
                echo "Email: <strong>{$emailAutorizar}</strong><br>";
            } else {
                echo "Email: <em style='color:#999'>não encontrado no corpo</em><br>";
            }

            echo "🟩 AUTORIZADO";
            echo '</div>';
        }
    }

    public function fechar() {
        imap_close($this->conexao);
    }
}

// =========================
// 🚀 EXECUÇÃO
// =========================

$auth = new AutorizacaoEmail();
$auth->conectar();
$auth->listarAutorizacoes();
$auth->fechar();