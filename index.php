<?php

date_default_timezone_set('America/Sao_Paulo');

class AutorizacaoEmail {

    private $conexao;
    private $arquivoHistorico = __DIR__ . '/autorizacoes.json';

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
        $corpoHtml = '';

        if (!isset($estrutura->parts)) {
            $corpo = imap_fetchbody($this->conexao, $emailId, '1');
            if ($estrutura->encoding == 3) $corpo = base64_decode($corpo);
            elseif ($estrutura->encoding == 4) $corpo = quoted_printable_decode($corpo);
        } else {
            $this->extrairPartes($estrutura->parts, $emailId, $corpo, $corpoHtml);
        }

        if (empty(trim($corpo)) && !empty($corpoHtml)) {
            $corpo = strip_tags($corpoHtml);
        }

        if (mb_detect_encoding($corpo, 'UTF-8', true) === false) {
            $corpo = mb_convert_encoding($corpo, 'UTF-8', 'ISO-8859-1');
        }

        $corpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $corpo);

        return trim($corpo);
    }

    private function extrairPartes($partes, $emailId, &$corpo, &$corpoHtml, $prefixo = '') {
        foreach ($partes as $i => $parte) {
            $index = $prefixo ? $prefixo . '.' . ($i + 1) : (string)($i + 1);

            if (isset($parte->parts)) {
                $this->extrairPartes($parte->parts, $emailId, $corpo, $corpoHtml, $index);
                continue;
            }

            $dados = imap_fetchbody($this->conexao, $emailId, $index);

            if ($parte->encoding == 3) $dados = base64_decode($dados);
            elseif ($parte->encoding == 4) $dados = quoted_printable_decode($dados);

            $subtype = strtoupper($parte->subtype ?? '');

            if ($subtype === 'PLAIN' && empty($corpo)) {
                $corpo = $dados;
            } elseif ($subtype === 'HTML' && empty($corpoHtml)) {
                $corpoHtml = $dados;
            }
        }
    }

    public function listarAutorizacoes() {

        $historico = $this->carregarHistorico();

        // Busca TODOS e filtra por assunto no PHP (evita problema com acentos no imap_search)
        $emails = imap_search($this->conexao, 'ALL');

        if ($emails) {
            rsort($emails);

            foreach ($emails as $emailId) {

                $header = imap_headerinfo($this->conexao, $emailId);

                // Decodifica assunto corretamente
                $assunto = imap_utf8($header->subject ?? '');

                // Filtra apenas e-mails com o assunto correto
                $assuntoNormalizado = mb_strtolower(imap_utf8($header->subject ?? ''));
                $assuntoValido = str_contains($assuntoNormalizado, 'autoriza') && str_contains($assuntoNormalizado, 'acesso');

                if (!$assuntoValido) {
                    continue; // ignora e-mails com outro assunto
                }

                $corpo = $this->pegarCorpoEmail($emailId);

                $corpo = preg_replace('/=\r?\n/', '', $corpo);
                $corpo = preg_replace('/\r?\n/', ' ', $corpo);
                $corpo = preg_replace('/\s+/', ' ', $corpo);

                $emailAutorizar = '';

                if (preg_match('/E-?MA?IL:\s*([\w\.\-\+]+@[\w\.\-]+\.[a-zA-Z]{2,})/i', $corpo, $match)) {
                    $emailAutorizar = trim($match[1]);
                }

                $remetenteEmail = isset($header->from[0])
                    ? $header->from[0]->mailbox . '@' . $header->from[0]->host
                    : 'desconhecido';

                $emailValido = filter_var($emailAutorizar, FILTER_VALIDATE_EMAIL) !== false;
                $corpoValido = !empty(trim($corpo));
                $autorizado  = $corpoValido && $emailValido;

                $messageId = $header->message_id ?? uniqid();

                $jaExiste = array_filter($historico, fn($r) =>
                    $r['message_id'] === $messageId ||
                    ($emailAutorizar !== '' && ($r['email_autorizar'] ?? '') === $emailAutorizar)
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

            if (!empty($registro['email_autorizar'])) {
                echo "Email: <strong>{$registro['email_autorizar']}</strong><br>";
            } else {
                echo "Email: <em style='color:#999'>não encontrado</em><br>";
            }

            echo "Data: <span style='color:#888'>{$registro['data']}</span><br>";

            if (($registro['status'] ?? 'nao_autorizado') === 'autorizado') {
                echo "<span style='color:#008000;font-weight:bold;'>🟩 AUTORIZADO</span>";
            } else {
                echo "<span style='color:#c0392b;font-weight:bold;'>🟥 NÃO AUTORIZADO</span>";
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