<?php

date_default_timezone_set('America/Sao_Paulo');

/**
 * Classe responsável por toda a lógica de leitura e processamento
 * dos e-mails de autorização via IMAP.
 * 
 * Compatível com Gmail, Outlook e qualquer provedor de e-mail.
 */
class AutorizacaoEmail {

    /** @var resource Conexão IMAP ativa */
    private $conexao;

    /** @var string Caminho do arquivo JSON que armazena o histórico de autorizações */
    private $arquivoHistorico = __DIR__ . '/autorizacoes.json';

    /**
     * Configurações de acesso ao servidor IMAP.
     * A senha deve ser uma App Password gerada em: myaccount.google.com/apppasswords
     */
    private $config = [
        'host'    => '{imap.gmail.com:993/imap/ssl}INBOX',
        'usuario' => 'germanoana943@gmail.com',
        'senha'   => 'fyundigyadhuvkll',
    ];

    /**
     * Abre a conexão com o servidor IMAP.
     * Encerra o script com mensagem de erro caso a conexão falhe.
     */
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

    /**
     * Carrega o histórico de autorizações salvo no arquivo JSON.
     * Retorna um array vazio se o arquivo ainda não existir.
     * 
     * @return array Lista de autorizações já processadas
     */
    private function carregarHistorico() {
        if (!file_exists($this->arquivoHistorico)) {
            return [];
        }
        $json = file_get_contents($this->arquivoHistorico);
        return json_decode($json, true) ?? [];
    }

    /**
     * Salva o histórico atualizado no arquivo JSON.
     * 
     * @param array $historico Lista completa de autorizações a salvar
     */
    private function salvarHistorico(array $historico) {
        file_put_contents(
            $this->arquivoHistorico,
            json_encode($historico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Extrai o corpo de texto de um e-mail pelo seu ID.
     * Prioriza texto puro (PLAIN); usa HTML como fallback se PLAIN estiver vazio.
     * Trata encoding UTF-8 e ISO-8859-1, e remove caracteres invisíveis.
     * 
     * @param int $emailId ID do e-mail na caixa IMAP
     * @return string Corpo do e-mail limpo e normalizado
     */
    public function pegarCorpoEmail($emailId) {
        $estrutura = imap_fetchstructure($this->conexao, $emailId);
        $corpo     = '';
        $corpoHtml = '';

        if (!isset($estrutura->parts)) {
            // E-mail simples (sem partes multipart)
            $corpo = imap_fetchbody($this->conexao, $emailId, '1');
            if ($estrutura->encoding == 3)     $corpo = base64_decode($corpo);
            elseif ($estrutura->encoding == 4) $corpo = quoted_printable_decode($corpo);
        } else {
            // E-mail multipart — extrai recursivamente
            $this->extrairPartes($estrutura->parts, $emailId, $corpo, $corpoHtml);
        }

        // Usa HTML como fallback se texto puro estiver vazio
        if (empty(trim($corpo)) && !empty($corpoHtml)) {
            $corpo = strip_tags($corpoHtml);
        }

        // Converte para UTF-8 se necessário (e-mails legados em ISO-8859-1)
        if (mb_detect_encoding($corpo, 'UTF-8', true) === false) {
            $corpo = mb_convert_encoding($corpo, 'UTF-8', 'ISO-8859-1');
        }

        // Remove caracteres de controle invisíveis
        $corpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $corpo);

        return trim($corpo);
    }

    /**
     * Percorre recursivamente as partes de um e-mail multipart,
     * extraindo o conteúdo PLAIN e HTML.
     * Necessário para e-mails do Outlook que usam estrutura aninhada.
     * 
     * @param array  $partes     Partes do e-mail a percorrer
     * @param int    $emailId    ID do e-mail na caixa IMAP
     * @param string &$corpo     Variável de saída para texto puro
     * @param string &$corpoHtml Variável de saída para HTML
     * @param string $prefixo    Prefixo de índice para partes aninhadas
     */
    private function extrairPartes($partes, $emailId, &$corpo, &$corpoHtml, $prefixo = '') {
        foreach ($partes as $i => $parte) {
            $index = $prefixo ? $prefixo . '.' . ($i + 1) : (string)($i + 1);

            if (isset($parte->parts)) {
                // Parte aninhada — entra recursivamente
                $this->extrairPartes($parte->parts, $emailId, $corpo, $corpoHtml, $index);
                continue;
            }

            $dados = imap_fetchbody($this->conexao, $emailId, $index);

            if ($parte->encoding == 3)     $dados = base64_decode($dados);
            elseif ($parte->encoding == 4) $dados = quoted_printable_decode($dados);

            $subtype = strtoupper($parte->subtype ?? '');

            if ($subtype === 'PLAIN' && empty($corpo)) {
                $corpo = $dados;
            } elseif ($subtype === 'HTML' && empty($corpoHtml)) {
                $corpoHtml = $dados;
            }
        }
    }

    /**
     * Função principal: busca e-mails novos, processa autorizações
     * e exibe o histórico completo na tela.
     * 
     * Fluxo:
     * 1. Carrega histórico do JSON
     * 2. Busca todos os e-mails da caixa
     * 3. Filtra por assunto "AUTORIZAÇÃO DE ACESSO"
     * 4. Extrai e valida o e-mail no corpo
     * 5. Salva no histórico sem duplicatas
     * 6. Marca o e-mail como lido
     * 7. Exibe todos os registros do histórico
     */
    public function listarAutorizacoes() {

        $historico = $this->carregarHistorico();

        // Busca todos os e-mails e filtra por assunto no PHP
        // (evita problemas de encoding com acentos no imap_search)
        $emails = imap_search($this->conexao, 'ALL');

        if ($emails) {
            rsort($emails); // processa do mais recente para o mais antigo

            foreach ($emails as $emailId) {

                $header = imap_headerinfo($this->conexao, $emailId);

                // Normaliza o assunto para comparação sem acentos/maiúsculas
                $assuntoNormalizado = mb_strtolower(imap_utf8($header->subject ?? ''));
                $assuntoValido = str_contains($assuntoNormalizado, 'autoriza')
                              && str_contains($assuntoNormalizado, 'acesso');

                // Ignora e-mails com assunto diferente
                if (!$assuntoValido) {
                    continue;
                }

                // Extrai e normaliza o corpo do e-mail
                $corpo = $this->pegarCorpoEmail($emailId);
                $corpo = preg_replace('/=\r?\n/', '', $corpo);  // remove soft line breaks (Outlook)
                $corpo = preg_replace('/\r?\n/', ' ', $corpo);  // junta em uma linha
                $corpo = preg_replace('/\s+/', ' ', $corpo);    // normaliza espaços

                // Tenta extrair o e-mail a autorizar do corpo
                // Aceita formatos: "Email:", "EMAIL:", "E-MAIL:" etc.
                $emailAutorizar = '';
                if (preg_match('/E-?MA?IL:\s*([\w\.\-\+]+@[\w\.\-]+\.[a-zA-Z]{2,})/i', $corpo, $match)) {
                    $emailAutorizar = trim($match[1]);
                }

                // Extrai o endereço do remetente
                $remetenteEmail = isset($header->from[0])
                    ? $header->from[0]->mailbox . '@' . $header->from[0]->host
                    : 'desconhecido';

                // Autorizado apenas se e-mail válido encontrado no corpo
                $emailValido = filter_var($emailAutorizar, FILTER_VALIDATE_EMAIL) !== false;
                $corpoValido = !empty(trim($corpo));
                $autorizado  = $corpoValido && $emailValido;

                $messageId = $header->message_id ?? uniqid();

                // Evita duplicatas: verifica pelo message_id ou pelo e-mail a autorizar
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

                // Marca o e-mail como lido para não reprocessar
                imap_setflag_full($this->conexao, (string)$emailId, "\\Seen");
            }

            $this->salvarHistorico($historico);
        }

        // Exibe mensagem se histórico estiver vazio
        if (empty($historico)) {
            echo '<p style="font-family:sans-serif;color:#888;font-size:14px;">
                    Nenhuma autorização encontrada.
                  </p>';
            return;
        }

        // Exibe do mais recente para o mais antigo
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

    /**
     * Fecha a conexão IMAP e aplica as mudanças de flags.
     * Deve ser chamado sempre ao final do uso da classe.
     */
    public function fechar() {
        imap_close($this->conexao, CL_EXPUNGE);
    }
}