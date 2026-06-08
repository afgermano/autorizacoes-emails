<?php

date_default_timezone_set('America/Sao_Paulo');

class AutorizacaoEmail {

    private $conexao;
    private $arquivoHistorico = __DIR__ . '/autorizacoes.json'; // ← adiciona esta linha


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

    private function carregarHistorico() {
        if (!file_exists($this->arquivoHistorico)) {
            return [];
        }
        $json = file_get_contents($this->arquivoHistorico);
        return json_decode($json, true) ?? [];
    }

    private function salvarHistorico(array $historico) {
        file_put_contents(
            $this->arquivoHistorico,
            json_encode($historico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
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

        $historico = $this->carregarHistorico();

        $emails = imap_search($this->conexao, 'ALL SUBJECT "AUTORIZAÇÃO DE ACESSO"');

        // Processa novos e-mails e adiciona ao histórico
        if ($emails) {
            rsort($emails);

            foreach ($emails as $emailId) {

                $header = imap_headerinfo($this->conexao, $emailId);
                $corpo  = $this->pegarCorpoEmail($emailId);

                $remetenteEmail = $header->from[0]->mailbox . '@' . $header->from[0]->host;

                $emailAutorizar = '';
                if (preg_match('/Email:\s*([\w\.\-\+]+@[\w\.\-]+\.[a-z]{2,})/i', $corpo, $match)) {
                    $emailAutorizar = $match[1];
                }

                $emailValido = filter_var($emailAutorizar, FILTER_VALIDATE_EMAIL);

                $corpoValido = !empty(trim($corpo));

                $assuntoValido = stripos($header->subject, 'AUTORIZAÇÃO DE ACESSO') !== false;

                $autorizado = (
                    $assuntoValido &&
                    $corpoValido &&
                    $emailValido
                );  

                // Evita duplicatas no histórico pelo message-id
                $messageId = $header->message_id ?? uniqid();
                $jaExiste = array_filter($historico, fn($r) => 
                    $r['message_id'] === $messageId || 
                    ($r['email_autorizar'] === $emailAutorizar && $emailAutorizar !== '')
                );

                if (empty($jaExiste)) {
                    $historico[] = [
                        'message_id'      => $messageId,
                        'remetente'       => $remetenteEmail,
                        'email_autorizar' => $emailAutorizar,
                        'data'            => date('d/m/Y H:i'),
                        'status'          => $autorizado ? 'autorizado' : 'nao_autorizado',
                    ];
                }
                // Marca como lido
                imap_setflag_full($this->conexao, (string)$emailId, "\\Seen");
            }

            $this->salvarHistorico($historico);
        }

        if (empty($historico)) {
            echo '<p style="font-family:sans-serif;color:#888;font-size:14px;">
                    Nenhuma autorização encontrada.
                  </p>';
            return;
        }

        $historico = array_reverse($historico);

        foreach ($historico as $registro) {
            echo '<div style="
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 16px 20px;
                margin-bottom: 12px;
                font-family: sans-serif;
                font-size: 14px;
                line-height: 2;
            ">';

            echo "Remetente: <strong>{$registro['remetente']}</strong><br>";

            if ($registro['email_autorizar']) {
                echo "Email: <strong>{$registro['email_autorizar']}</strong><br>";
            } else {
                echo "Email: <em style='color:#999'>não encontrado</em><br>";
            }

            echo "Data: <span style='color:#888'>{$registro['data']}</span><br>";
            if (($registro['status'] ?? 'autorizado') === 'autorizado') {
                echo " 🟩 AUTORIZADO ";
            } else {
                echo "<span style='color:#c0392b;font-weight:bold;'>🟥 NÃO AUTORIZADO </span>";
            }
            echo '</div>';
        }
    }

    public function fechar() {
        imap_close($this->conexao, CL_EXPUNGE);
    }
}

$auth = new AutorizacaoEmail();
$auth->conectar();
$auth->listarAutorizacoes();
$auth->fechar();