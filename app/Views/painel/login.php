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
    <label class="campo">
      <span class="campo__rotulo">Senha</span>
      <input class="entrada" type="password" name="senha" autocomplete="current-password" required>
    </label>
    <p class="campo__erro" id="erro-login" role="alert" hidden></p>
    <button type="submit" class="botao botao--g botao--bloco">Entrar</button>
  </form>
</main>
<script type="module" src="<?= e(asset('js/painel/login.js')) ?>"></script>
</body>
</html>
