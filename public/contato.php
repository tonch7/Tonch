<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();

$mes = (int)($_GET['mes'] ?? 1);
$ano = (int)($_GET['ano'] ?? date('Y'));

$pageTitle  = 'Contato Desenvolvedor';
$activePage = 'contato';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<div style="max-width:640px;margin:0 auto;">

  <!-- CARD PRINCIPAL -->
  <div class="win mb-12">
    <div class="win-title" style="background:linear-gradient(180deg,#1a3a5c,#0d2137);color:#fff;letter-spacing:1px;">
      &#128222; Contato do Desenvolvedor
    </div>
    <div class="win-body" style="padding:32px 28px;">

      <!-- Logo / Nome -->
      <div style="text-align:center;margin-bottom:28px;">
        <div style="font-size:54px;line-height:1;">&#127760;</div>
        <div style="font-size:22px;font-weight:700;color:var(--blue);margin-top:8px;letter-spacing:1px;">
          TONCH
        </div>
        <div style="font-size:12px;color:var(--muted);margin-top:2px;letter-spacing:2px;text-transform:uppercase;">
          Soluções em Tecnologia
        </div>
      </div>

      <!-- Divisor -->
      <hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">

      <!-- Contatos -->
      <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- Site -->
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;
                    background:#f4f8ff;border:1px solid #c5d8f5;border-radius:8px;">
          <div style="font-size:28px;flex-shrink:0;">&#127760;</div>
          <div style="flex:1;">
            <div style="font-size:11px;text-transform:uppercase;color:var(--muted);
                        letter-spacing:1px;margin-bottom:2px;">Website</div>
            <div style="font-size:16px;font-weight:700;color:var(--blue);">
              tonch.com.br
            </div>
          </div>
          <a href="https://tonch.com.br" target="_blank" rel="noopener"
             class="btn btn-primary btn-sm" style="flex-shrink:0;">
            Acessar &#8599;
          </a>
        </div>

        <!-- Telefone / WhatsApp -->
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;
                    background:#f0fff4;border:1px solid #a8dfc0;border-radius:8px;">
          <div style="font-size:28px;flex-shrink:0;">&#128222;</div>
          <div style="flex:1;">
            <div style="font-size:11px;text-transform:uppercase;color:var(--muted);
                        letter-spacing:1px;margin-bottom:2px;">Telefone / WhatsApp</div>
            <div style="font-size:16px;font-weight:700;color:#1a7a3a;">
              (17) 92003-0811
            </div>
          </div>
          <a href="https://wa.me/5517920030811" target="_blank" rel="noopener"
             class="btn btn-sm" style="flex-shrink:0;background:#25d366;color:#fff;">
            WhatsApp &#128172;
          </a>
        </div>

        <!-- E-mail -->
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;
                    background:#fffaf0;border:1px solid #f0d5a0;border-radius:8px;">
          <div style="font-size:28px;flex-shrink:0;">&#128140;</div>
          <div style="flex:1;">
            <div style="font-size:11px;text-transform:uppercase;color:var(--muted);
                        letter-spacing:1px;margin-bottom:2px;">E-mail</div>
            <div style="font-size:16px;font-weight:700;color:#a05000;">
              contato@tonch.com.br
            </div>
          </div>
          <a href="mailto:contato@tonch.com.br"
             class="btn btn-sm" style="flex-shrink:0;background:#f0a030;color:#fff;">
            Enviar E-mail &#128231;
          </a>
        </div>

      </div>

      <!-- Divisor -->
      <hr style="border:none;border-top:1px solid #ddd;margin:24px 0;">

      <!-- Rodapé informativo -->
      <div style="text-align:center;font-size:12px;color:var(--muted);line-height:1.7;">
        <p style="margin:0 0 4px;">
&#128204; <strong>Se você chegou até aqui, parabéns!</strong><br><br>

Este sistema foi criado inicialmente para facilitar a minha própria vida.<br>
Durante o processo, percebi que seria uma injustiça mantê-lo apenas para uso pessoal,<br>
sabendo que muitas outras pessoas enfrentam os mesmos desafios no dia a dia.<br><br>

Por isso, decidi disponibilizá-lo gratuitamente para todos que precisem de uma ferramenta simples,<br>
eficiente e acessível — sem anúncios, sem complicações e sem ocupar espaço desnecessário<br>
no telefone ou computador.<br><br>

Se você está utilizando este sistema, fico realmente feliz em saber que ele está sendo útil para você.<br><br>

Caso queira apoiar o projeto e contribuir para sua evolução,<br>
considere nos apoiar através do GitHub.<br><br>

Um grande abraço,<br>
<strong>Gabriel Perdigão</strong><br>
Fundador — <strong>Tonch.com.br</strong>        </p>
        
      </div>

    </div>
  </div>

</div>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
