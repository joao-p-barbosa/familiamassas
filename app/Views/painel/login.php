<?php
/**
 * Login do painel.
 * @var string $nomeLoja
 * @var string $tema
 * @var string $csrf
 */
$tituloPagina = 'Entrar · ' . $nomeLoja;
$estilos = ['painel'];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require dirname(__DIR__) . '/parciais/cabeca.php'; ?>
</head>
<body class="login">
<main class="login__caixa">
  <a class="marca" href="/"><?= e($nomeLoja) ?></a>
  <h1 class="login__titulo">Painel da loja</h1>

  <form class="login__form" id="form-login" novalidate>
    <label class="campo">
      <span class="campo__rotulo">Usuário</span>
      <input class="entrada" type="text" name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </label>
    <div class="campo">
      <label class="campo__rotulo" for="login-senha">Senha</label>
      <div class="entrada-senha">
        <input class="entrada" type="password" id="login-senha" name="senha" autocomplete="current-password" required>
        <button type="button" class="botao botao--fantasma botao--icone" data-mostrar-senha aria-pressed="false" aria-label="Mostrar a senha">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <p class="campo__erro" id="erro-login" role="alert" hidden></p>
    <button type="submit" class="botao botao--g botao--bloco">Entrar</button>
  </form>
</main>
<script type="module" src="<?= e(asset('js/painel/login.js')) ?>"></script>
</body>
</html>
