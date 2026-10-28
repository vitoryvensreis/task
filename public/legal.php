<?php
declare(strict_types=1);
// Renderizado por index.php. Nunca exige sessão: o Google precisa acessar estas páginas publicamente.
if(!isset($legalPage)||!in_array($legalPage,['privacidade','termos-de-uso'],true)){http_response_code(404);exit;}
$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$root='/'.trim($basePath,'/');if($root==='/')$root='';
$isPrivacy=$legalPage==='privacidade';
$title=$isPrivacy?'Política de Privacidade':'Termos de Uso';
$canonical=rtrim((string)config('base_url','https://task.escotilhaprodutora.com'),'/').'/'.$legalPage;
$description=$isPrivacy
 ?'Entenda como o TaskEsc, produto da Escotilha Produtora, utiliza e protege informações, incluindo a integração com o Google Drive.'
 :'Leia as condições de uso do TaskEsc, plataforma de gestão de projetos e tarefas da Escotilha Produtora.';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0d12">
<meta name="description" content="<?=e($description)?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?=e($title)?> | TaskEsc">
<meta property="og:description" content="<?=e($description)?>">
<meta property="og:url" content="<?=e($canonical)?>">
<link rel="canonical" href="<?=e($canonical)?>">
<title><?=e($title)?> | TaskEsc — Escotilha Produtora</title>
<link rel="icon" href="<?=e($root)?>/assets/taskesc-logo.svg">
<link rel="stylesheet" href="<?=e($root)?>/assets/landing.css?v=1.1">
<link rel="stylesheet" href="<?=e($root)?>/assets/legal.css?v=1">
</head>
<body class="landing-page legal-page">
<div class="site-bg" aria-hidden="true"></div>
<a class="legal-skip" href="#conteudo">Ir para o conteúdo</a>
<header class="site-header">
  <a class="logo" href="<?=e($root)?>/" aria-label="TaskEsc, voltar ao início">
   <img class="logo-mark logo-image" src="<?=e($root)?>/assets/taskesc-logo.svg" alt="" width="34" height="34">
   <span>TaskEsc<span class="logo-dot">.</span></span>
  </a>
  <nav aria-label="Navegação pública"><a href="<?=e($root)?>/">Início</a><a href="<?=e($root)?>/#recursos">Recursos</a><a href="<?=e($root)?>/#planos">Planos</a></nav>
  <div class="header-actions"><a class="login-link" href="<?=e($root)?>/dashboard">Entrar</a></div>
</header>
<main class="legal-shell wrap" id="conteudo">
 <div class="legal-heading">
  <span class="legal-kicker">TASKESC / ESCOTILHA PRODUTORA</span>
  <h1><?=e($title)?></h1>
  <p><?=e($description)?></p>
  <div class="legal-date">Última atualização: 28 de outubro de 2026</div>
 </div>
 <div class="legal-columns">
  <aside class="legal-sidebar" aria-label="Navegação deste documento">
   <strong>Nesta página</strong>
   <?php if($isPrivacy): ?>
   <a href="#responsavel">Responsável e abrangência</a>
   <a href="#dados">Dados utilizados</a>
   <a href="#google">Integração Google Drive</a>
   <a href="#finalidades">Finalidades e bases legais</a>
   <a href="#compartilhamento">Compartilhamento</a>
   <a href="#seguranca">Segurança e conservação</a>
   <a href="#direitos">Direitos e solicitações</a>
   <a href="#contato">Contato e alterações</a>
   <?php else: ?>
   <a href="#apresentacao">Objeto e aceitação</a>
   <a href="#acesso">Contas e permissões</a>
   <a href="#conteudos">Conteúdo e colaboração</a>
   <a href="#integracoes">Integrações externas</a>
   <a href="#contratacao">Planos e pagamentos</a>
   <a href="#disponibilidade">Disponibilidade e segurança</a>
   <a href="#encerramento">Suspensão e encerramento</a>
   <a href="#contato">Contato e atualizações</a>
   <?php endif; ?>
  </aside>
  <article class="legal-document">
   <?php if($isPrivacy): ?>
   <section id="responsavel">
    <h2>1. Quem é responsável pelo TaskEsc</h2>
    <p>O <strong>TaskEsc</strong> é um produto da <strong>Escotilha Produtora</strong>, utilizado para organizar projetos, tarefas, equipes, clientes, arquivos, comentários e fluxos operacionais. Esta política explica como as informações podem ser tratadas no uso da plataforma e em suas integrações.</p>
    <p>Quando uma empresa utiliza o TaskEsc para administrar informações de seus próprios clientes, funcionários e colaboradores, essa empresa pode determinar as finalidades do tratamento desses dados; a Escotilha Produtora atua como fornecedora da plataforma, conforme a relação contratual e a legislação aplicável.</p>
   </section>
   <section id="dados">
    <h2>2. Quais dados são utilizados</h2>
    <p>Conforme as funções utilizadas, o TaskEsc pode tratar:</p>
    <ul>
     <li><strong>Cadastro e acesso:</strong> nome, e-mail, função, permissões, informações de autenticação e configurações de segurança da conta.</li>
     <li><strong>Operação:</strong> clientes, projetos, tarefas, responsáveis, prazos, briefings, comentários, checklists, arquivos, links e histórico de atividades.</li>
     <li><strong>Integrações:</strong> identificação da conta Google autorizada, escopos concedidos, identificadores de arquivos e pastas e credenciais técnicas de acesso, quando uma integração é ativada.</li>
     <li><strong>Comunicação e suporte:</strong> mensagens de suporte, notificações e dados de contato informados em recursos opcionais, incluindo integrações de mensagens.</li>
     <li><strong>Segurança e funcionamento:</strong> registros de acesso e ações, dados técnicos de requisições e cookies necessários à sessão e autenticação.</li>
     <li><strong>Assinaturas e cobrança:</strong> dados necessários ao gerenciamento de planos e registros de pagamentos quando essas funções forem contratadas.</li>
    </ul>
    <p>O conteúdo que sua empresa cadastra ou envia ao TaskEsc depende das escolhas de seus usuários e das permissões atribuídas dentro do workspace.</p>
   </section>
   <section id="google">
    <h2>3. Como funciona a integração com o Google Drive</h2>
    <p>A conexão com o Google é <strong>opcional</strong> e depende de autorização expressa na tela de consentimento do Google. O fluxo atual solicita <code>openid</code>, <code>email</code> e <code>https://www.googleapis.com/auth/drive.file</code>.</p>
    <p>Essas permissões permitem identificar a conta autorizada e <strong>criar, enviar, consultar, visualizar, baixar, organizar e, quando solicitado pelo usuário autorizado, remover arquivos</strong> que estejam disponíveis ao aplicativo por meio da integração Google Drive. O escopo <code>drive.file</code> não corresponde a acesso irrestrito a todos os arquivos pessoais do Drive.</p>
    <p>O TaskEsc usa o identificador da conta e do arquivo para manter os anexos vinculados às respectivas tarefas. Tokens de acesso e de renovação podem ser armazenados de forma criptografada no servidor para manter a conexão enquanto a autorização permanecer válida.</p>
    <p>Os arquivos enviados ao Google Drive permanecem sujeitos às regras e às permissões da conta Google conectada. A desconexão no TaskEsc ou a revogação do acesso em sua Conta Google impede novas operações autorizadas, mas <strong>não apaga automaticamente</strong> arquivos já armazenados no Drive nem registros que precisem ser conservados legitimamente.</p>
    <p><strong>Não vendemos dados obtidos das APIs do Google e não os utilizamos para publicidade direcionada.</strong> O acesso a esses dados é limitado às funções autorizadas pelo usuário e necessárias para fornecer a integração. Você pode gerenciar as autorizações em <a href="https://myaccount.google.com/connections" target="_blank" rel="noopener noreferrer">Conexões de terceiros da Conta Google</a>.</p>
   </section>
   <section id="finalidades">
    <h2>4. Finalidades e fundamentos do tratamento</h2>
    <p>As informações são utilizadas para autenticar usuários, fornecer os recursos solicitados, controlar acesso por empresa e função, armazenar e disponibilizar arquivos autorizados, enviar notificações operacionais, prestar suporte, processar cobranças quando aplicável e proteger a plataforma contra erros ou uso indevido.</p>
    <p>Conforme o contexto, o tratamento pode se apoiar na execução de contratos, em obrigações legais, em interesses legítimos avaliados de acordo com a LGPD ou no consentimento, quando exigido. A autorização de acesso ao Google é gerenciada separadamente pelo próprio Google.</p>
   </section>
   <section id="compartilhamento">
    <h2>5. Compartilhamento e terceiros</h2>
    <p>Os dados podem ser acessados por usuários autorizados da própria organização e tratados por serviços técnicos necessários à operação, tais como infraestrutura de hospedagem, Google Drive, serviços de notificações, comunicação ou processamento de pagamentos, conforme cada recurso ativado. O compartilhamento ocorre apenas na medida necessária para prestar o serviço, atender a instruções da organização ou cumprir obrigações legais.</p>
    <p>O TaskEsc não torna automaticamente públicos os arquivos privados de uma empresa. Compartilhamentos de pastas, links e permissões configurados pelos usuários na integração com o Google Drive são regidos também pelas configurações da conta Google.</p>
   </section>
   <section id="seguranca">
    <h2>6. Segurança, cookies e conservação</h2>
    <p>Adotamos medidas técnicas e administrativas de proteção, incluindo autenticação, controle de permissões, uso de conexões HTTPS, registros de ações e proteção de credenciais de integração. Nenhum serviço conectado à internet consegue eliminar integralmente todos os riscos.</p>
    <p>O TaskEsc utiliza recursos de sessão e armazenamento necessários à autenticação, segurança e funcionamento da interface. O período de conservação de informações depende de sua finalidade, das configurações da organização, das necessidades contratuais e das obrigações legais aplicáveis. Dados e arquivos podem permanecer também no provedor externo escolhido pelo usuário.</p>
   </section>
   <section id="direitos">
    <h2>7. Seus direitos e o controle dos dados</h2>
    <p>Nos termos da LGPD e conforme a hipótese aplicável, você pode solicitar confirmação de tratamento, acesso, correção, informação sobre compartilhamento, portabilidade quando cabível, oposição, anonimização, bloqueio ou eliminação, além de revogação do consentimento quando ele for a base legal.</p>
    <p>Se os dados forem administrados pela empresa que contratou o TaskEsc, a solicitação poderá precisar ser encaminhada ao administrador dessa empresa. A exclusão de dados será avaliada considerando obrigações legais, prevenção a fraudes, contratos e retenções necessárias.</p>
    <p>Para interromper o acesso da integração Google, desconecte a conta no TaskEsc ou revogue a autorização diretamente nas configurações de sua Conta Google.</p>
   </section>
   <section id="contato">
    <h2>8. Contato e mudanças nesta política</h2>
    <p>Para dúvidas sobre privacidade e solicitações relacionadas ao TaskEsc, utilize o canal de suporte disponível na plataforma ou os canais oficiais da <a href="https://escotilhaprodutora.com.br/" target="_blank" rel="noopener noreferrer">Escotilha Produtora</a>. Informe, quando possível, o e-mail da conta e a organização à qual o pedido se refere, sem enviar senhas ou tokens.</p>
    <p>Esta política poderá ser atualizada para refletir mudanças nos serviços ou requisitos legais. A data da revisão mais recente será mantida nesta página.</p>
   </section>
   <?php else: ?>
   <section id="apresentacao">
    <h2>1. O serviço e a aceitação dos termos</h2>
    <p>O <strong>TaskEsc</strong> é um produto da <strong>Escotilha Produtora</strong> para gestão de tarefas, projetos, equipes, clientes, arquivos, colaboração e automações operacionais. Ao criar ou utilizar uma conta, o usuário concorda com estes Termos de Uso, observados também os contratos celebrados com a organização contratante.</p>
    <p>O uso deve respeitar a legislação aplicável e os direitos de terceiros. Estes termos não substituem condições comerciais específicas formalmente acordadas.</p>
   </section>
   <section id="acesso">
    <h2>2. Contas, acessos e permissões</h2>
    <p>O acesso ao ambiente de cada organização depende de cadastro, convite ou autorização de seus responsáveis. Usuários devem manter credenciais em sigilo e são responsáveis pelas ações praticadas por suas contas, ressalvadas situações de comprometimento fora de seu controle.</p>
    <p>Administradores da organização gerenciam permissões e responsáveis. O acesso a informações de outros clientes, equipes ou empresas sem autorização é proibido.</p>
   </section>
   <section id="conteudos">
    <h2>3. Dados, arquivos e conteúdo enviado</h2>
    <p>O usuário ou a organização que envia conteúdo declara possuir as autorizações necessárias para utilizá-lo e compartilhá-lo na plataforma. É vedado usar o TaskEsc para divulgar material ilícito, violar privacidade, infringir direitos autorais ou comprometer a segurança do serviço.</p>
    <p>O conteúdo das tarefas permanece sob a responsabilidade de quem o cadastra e das organizações que administram o ambiente. A Escotilha Produtora utiliza os dados necessários à prestação do serviço, nos termos da <a href="<?=e($root)?>/privacidade">Política de Privacidade</a>, sem adquirir direitos de propriedade sobre os materiais enviados apenas em razão do armazenamento técnico.</p>
   </section>
   <section id="integracoes">
    <h2>4. Integrações com serviços externos</h2>
    <p>Recursos opcionais podem depender de serviços terceiros, incluindo Google Drive, mensagens, notificações e sistemas de pagamento. O uso de cada integração pode exigir autorização adicional e estar sujeito aos termos e políticas do respectivo provedor.</p>
    <p>Na integração com o Google Drive, o usuário autoriza os escopos indicados na tela de consentimento. É possível revogar a autorização posteriormente, mas isso poderá interromper visualizações, uploads e outras operações sobre arquivos vinculados à conta. A revogação não exclui automaticamente os arquivos armazenados no Google.</p>
   </section>
   <section id="contratacao">
    <h2>5. Planos, cobranças e condições comerciais</h2>
    <p>Os recursos e limites disponíveis podem variar conforme o plano contratado. Preços, períodos, renovação, cancelamento e eventuais condições específicas são apresentados no processo de contratação ou em instrumento comercial próprio.</p>
    <p>Falhas de pagamento ou término do vínculo contratual podem restringir funcionalidades conforme as condições aplicáveis, respeitados direitos previstos na legislação.</p>
   </section>
   <section id="disponibilidade">
    <h2>6. Funcionamento, segurança e manutenção</h2>
    <p>A Escotilha Produtora procura manter o TaskEsc estável e seguro, mas funcionalidades podem ser afetadas por manutenção, indisponibilidade de rede, infraestrutura ou serviços externos. Os usuários devem preservar cópias apropriadas de informações críticas conforme sua necessidade operacional.</p>
    <p>São proibidas tentativas de acesso não autorizado, exploração de falhas, automações abusivas ou qualquer prática destinada a prejudicar a plataforma e seus usuários.</p>
   </section>
   <section id="encerramento">
    <h2>7. Suspensão e encerramento</h2>
    <p>A utilização poderá ser suspensa em caso de violação destes termos, risco de segurança, exigência legal ou encerramento da relação contratual, consideradas as regras aplicáveis ao caso e a comunicação quando cabível.</p>
    <p>Pedidos de retirada e exclusão de dados devem ser encaminhados pelos canais oficiais. A disponibilidade de exportação e os prazos de retenção dependem do contrato, da legislação e dos serviços externos nos quais os dados também estejam armazenados.</p>
   </section>
   <section id="contato">
    <h2>8. Contato, política de privacidade e atualizações</h2>
    <p>Para dúvidas ou solicitações relacionadas à plataforma, utilize o suporte disponível no TaskEsc ou os canais oficiais da <a href="https://escotilhaprodutora.com.br/" target="_blank" rel="noopener noreferrer">Escotilha Produtora</a>.</p>
    <p>Consulte também a <a href="<?=e($root)?>/privacidade">Política de Privacidade</a>. Estes termos poderão ser atualizados em decorrência de mudanças no produto ou na legislação; a data da revisão será indicada nesta página.</p>
   </section>
   <?php endif; ?>
  </article>
 </div>
</main>
<footer class="footer legal-footer">
 <div class="wrap footer-row">
  <a class="logo" href="<?=e($root)?>/"><img class="logo-mark logo-image" src="<?=e($root)?>/assets/taskesc-logo.svg" alt="" width="34" height="34"><span>TaskEsc<span class="logo-dot">.</span></span></a>
  <p>Um produto da <strong>Escotilha Produtora</strong>.</p>
  <nav aria-label="Links institucionais"><a href="<?=e($root)?>/">Início</a><a href="<?=e($root)?>/privacidade" <?= $isPrivacy?'aria-current="page"':'' ?>>Privacidade</a><a href="<?=e($root)?>/termos-de-uso" <?= !$isPrivacy?'aria-current="page"':'' ?>>Termos de Uso</a></nav>
 </div>
</footer>
</body>
</html>
